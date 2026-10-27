<?php
/** Regression checks for manifest issues hidden by shipping status; no WordPress or carrier writes. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
/** Synthetic order with independent fulfillment metadata. */
class WC_Order {
 public function __construct(public array $meta = array(), public string $status = 'processing') {}
 public function get_edit_order_url(): string { return '#order'; }
 public function get_shipping_city(): string { return 'Riverdale'; }
 public function get_shipping_state(): string { return 'ON'; }
 public function get_shipping_country(): string { return 'CA'; }
 public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
 public function has_status(array $states): bool { return in_array($this->status, $states, true); }
}
/** Read the fixture metadata through the logistics boundary. */
class WLP_Order_Logistics { public static function read(WC_Order $order): array { return $order->meta; } }
/** Resolve synthetic orders only. */
function wc_get_order(int $id): ?WC_Order { return $GLOBALS['orders'][$id] ?? null; }
/** Escape output for the rendering regression. */
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function esc_url($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function admin_url($v): string { return '/wp-admin/'.$v; }
function get_option($k,$d=false) { return $GLOBALS['options'][$k] ?? $d; }
function wp_date($format): string { return date($format,strtotime('2026-10-27')); }
function selected($a,$b,$echo=false): string { return $a===$b?' selected':''; }
function wp_nonce_field($action): void { echo '<input type="hidden" name="_wpnonce" value="test-nonce">'; }
function wp_nonce_url($url,$action): string { return $url.'&_wpnonce=test-nonce'; }
function add_query_arg($a,$b=null,$c=null): string { return is_array($a) ? $b.'?'.http_build_query($a) : $c.'&'.urlencode($a).'='.urlencode((string)$b); }
require __DIR__ . '/../includes/class-wlp-contract-shipping.php';
/** Fail even when PHP assertions are disabled. */
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
/** Build an isolated saved carrier batch. */
function batch(int $id, string $state = 'created', string $batchState = 'open'): array {
 return array('day'=>'2026-10-01','state'=>$batchState,'config'=>array('pickup'=>true,'postal'=>'K1A0B1','customer'=>'123456','sandbox'=>true),'shipments'=>array('request'=>array('order'=>$id,'package'=>'primary:0','state'=>$state,'result'=>array('shipment_id'=>'test-'.$id,'tracking_number'=>'TEST-'.$id))));
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

$all['pending']=$pending;
$all['confirmed']['manifests']=[['po'=>'TEST-PO','artifact'=>'https://example.com/test.pdf','confirmed_at'=>'2026-10-02T12:00:00Z']];
$all['pending']['manifest_links']=['https://example.com/pending'];
$project=new ReflectionMethod(WLP_Contract_Shipping::class,'workspace_rows');
$data=$project->invoke(null,$all,[]);
check(count($data['review'])===5,'Awaiting submissions must be in the review table.');
check(count($data['submitted'])===1,'Only confirmed submissions belong in history.');
check(count(array_filter($data['needs'],static fn($r)=>!empty($r['token'])))===1,'Only the existing eligible parcel is selectable.');
$proofKey=WLP_Manifest_Verifier::key($all['ready']['config'],'test-3','TEST-3');
$verified=$project->invoke(null,$all,['parcels'=>[$proofKey=>['state'=>'transmitted','error'=>null]]]);
check(!in_array(3,array_column($verified['needs'],'order'),true),'Carrier-confirmed parcels cannot appear as selectable unsubmitted parcels.');
$uncertain=batch(3,'uncertain');$recovery=$project->invoke(null,['uncertain'=>$uncertain],[]);
check(!$recovery['needs'] && count($recovery['review'])===1,'Uncertain label requests stay in the visible review table.');
$conflictKey=WLP_Manifest_Verifier::key($all['confirmed']['config'],'test-4','TEST-4');
$conflict=$project->invoke(null,$all,['parcels'=>[$conflictKey=>['state'=>'created']]]);
check(count($conflict['review'])===6,'Carrier/local disagreement remains visible for review without resubmission.');
$render=new ReflectionMethod(WLP_Contract_Shipping::class,'workspace_render');
ob_start();$render->invoke(null,$all,[],'needs',1);$html=ob_get_clean();
check(str_contains($html,'Awaiting confirmation / Needs review') && str_contains($html,'#1') && str_contains($html,'#2'),'Missing and pending manifests remain visible on the main page.');
check(!str_contains($html,'Dispatch 2026') && !str_contains($html,'Finish shipping day'),'No per-day headings or old walkthrough.');
check(str_contains($html,'name="parcels[]"') && str_contains($html,'name="handoff_id"') && str_contains($html,'name="ready"'),'Submission contract remains unchanged.');
check(strpos($html,'Awaiting confirmation / Needs review')>strpos($html,'Submit selected parcels'),'Review table appears below the queue.');
check(!str_contains($html,'PDF TEST-PO'),'Confirmed history is not shown on the main page.');
ob_start();$render->invoke(null,$all,[],'submitted',1);$history=ob_get_clean();
check(str_contains($history,'PDF TEST-PO') && !str_contains($history,'name="parcels[]"') && !str_contains($history,'Awaiting confirmation / Needs review'),'History preserves PDF access and excludes actionable queue.');
$many=[];for($i=10;$i<35;$i++){ $many['b'.$i]=batch($i,'transmitted','manifest_ready');$many['b'.$i]['manifests']=[['po'=>'TEST-'.$i,'confirmed_at'=>'2026-10-02T12:00:00Z']]; }
ob_start();$render->invoke(null,$many,[],'submitted',1);$paged=ob_get_clean();
check(substr_count($paged,'<tr>')===21 && str_contains($paged,'Next'),'History must show at most 20 rows plus the header.');
if (in_array('--preview',$argv,true) || in_array('--history',$argv,true)) {
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shipping manifests preview</title><link rel="stylesheet" href="preview-base.css"><link rel="stylesheet" href="woo-manifests/assets/css/manifests.css"></head><body><aside class="preview-sidebar">WordPress<br><br>WooCommerce<br><br>Logistics<br><br><strong>Shipping manifests</strong></aside><main class="preview-main">'.(in_array('--history',$argv,true)?$history:$html).'</main><script src="woo-manifests/assets/js/manifests.js"></script></body></html>';exit;
}
echo "Manifest visibility and workspace regressions passed.\n";
