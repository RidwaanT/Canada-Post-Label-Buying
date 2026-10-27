<?php
/** Regression checks for manifest issues hidden by shipping status; no WordPress or carrier writes. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
/** Synthetic order with independent fulfillment metadata. */
class WC_Order {
 public function __construct(public array $meta = array(), public string $status = 'processing') {}
 public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
 public function has_status(array $states): bool { return in_array($this->status, $states, true); }
}
/** Read the fixture metadata through the logistics boundary. */
class WLP_Order_Logistics { public static function read(WC_Order $order): array { return $order->meta; } }
/** Resolve synthetic orders only. */
function wc_get_order(int $id): ?WC_Order { return $GLOBALS['orders'][$id] ?? null; }
/** Escape output for the rendering regression. */
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
require __DIR__ . '/../includes/class-wlp-contract-shipping.php';
/** Fail even when PHP assertions are disabled. */
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
/** Build an isolated saved carrier batch. */
function batch(int $id, string $state = 'created', string $batchState = 'open'): array {
 return array('day'=>'2026-10-01','state'=>$batchState,'config'=>array('pickup'=>true),'shipments'=>array('request'=>array('order'=>$id,'package'=>'primary:0','state'=>$state,'result'=>array('shipment_id'=>'test-'.$id,'tracking_number'=>'TEST-'.$id))));
}
$GLOBALS['orders'] = array(
 1=>new WC_Order(array('shipment_id'=>'test-1','shipped_at'=>'2026-10-02')),
 2=>new WC_Order(array('shipment_id'=>'test-2','delivered_at'=>'2026-10-03')),
 3=>new WC_Order(array('shipment_id'=>'test-3')),
 4=>new WC_Order(array('shipment_id'=>'test-4','shipped_at'=>'2026-10-02')),
 5=>new WC_Order(array('shipment_id'=>'test-5'), 'cancelled'),
 6=>new WC_Order(array('_wlp_split_shipments'=>array('packages'=>array(array('id'=>'a','label'=>array('shipment_id'=>'test-6'),'shippedAt'=>'2026-10-02'))))),
);
$all = array('shipped'=>batch(1),'delivered'=>batch(2),'ready'=>batch(3),'confirmed'=>batch(4,'transmitted','manifest_ready'),'held'=>batch(5),'split'=>batch(6));
$all['split']['shipments']['request']['package']='split:a';
$summary=WLP_Contract_Shipping::reminder_summary($all);
check($summary === array('ready'=>1,'pending'=>0,'review'=>4), 'Shipped, delivered, held and split parcels must remain review issues.');
$issues=WLP_Contract_Shipping::manifest_issues($all);
check(array_column($issues,'order') === array(1,2,5,6), 'Every unresolved excluded parcel must be visible.');
$pickup=WLP_Contract_Shipping::pickup_summary($all);
check($pickup['pickup']===0 && $pickup['remaining']===1, 'Already handed-over parcels must not inflate pickup totals or become quick-submit candidates.');
$pending=batch(1,'created','awaiting_manifest');$pending['pending']=array('request');
$summary=WLP_Contract_Shipping::reminder_summary(array('pending'=>$pending));
check($summary['pending']===1, 'Pending confirmation survives shipped status.');
check(str_contains(WLP_Contract_Shipping::manifest_issues(array('pending'=>$pending))[0]['reason'],'do not resubmit'), 'Pending submissions must retain no-resubmit guidance.');
check(WLP_Contract_Shipping::manifest_issues(array('void'=>batch(1,'voided'),'rejected'=>batch(2,'rejected'),'confirmed'=>batch(4,'transmitted','manifest_ready')))===array(), 'Terminal parcels must not create false warnings.');
$render=new ReflectionMethod(WLP_Contract_Shipping::class,'finish_day_panel');
ob_start();$render->invoke(null,array('shipped'=>batch(1),'delivered'=>batch(2)));$html=ob_get_clean();
check(str_contains($html,'2 parcel(s) need manifest attention') && str_contains($html,'#1') && str_contains($html,'#2'), 'The main panel must expose missing manifests.');
check(!str_contains($html,'All eligible labels are accounted for'), 'Empty ready queue must not imply manifest completion.');
if (in_array('--script', $argv, true)) { preg_match('/<script>(.*?)<\/script>/s', $html, $match); echo $match[1]; exit; }
echo "Manifest visibility regressions passed.\n";
