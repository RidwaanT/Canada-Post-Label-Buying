<?php
/** Exact-identity reconciliation, idempotency and partial-batch safety with fake carrier notices. */
require __DIR__.'/manifest-visibility.php';
class WP_REST_Request { public function __construct(private array $body) {} public function get_json_params(): array { return $this->body; } }
class WP_Error { public function __construct(public string $code,public string $message,public array $data) {} }
function get_current_user_id(): int { return 1; }
function rest_ensure_response($value) { return $value; }
function add_option($key,$value,$unused='',$autoload=false): bool { if (isset($GLOBALS['options'][$key])) { return false; } $GLOBALS['options'][$key]=$value;return true; }
$notice=['customer'=>'123456','tracking'=>'0000000000000003','order'=>3,'manifest'=>'P000000001','notice_date'=>'2026-10-20','message_id'=>'0000000000000001'];
$b=batch(3);$b['config']['sandbox']=false;$b['shipments']['request']['result']['tracking_number']=$notice['tracking'];
$GLOBALS['options']['wlp_contract_batches_v1']=['b'=>$b];
$call=static fn($body)=>WLP_Manifest_Reconciliation::record(new WP_REST_Request($body));
check($call(array_replace($notice,['customer'=>'999999'])) instanceof WP_Error,'Wrong account must not match.');
check($call(array_replace($notice,['tracking'=>'0000000000000004'])) instanceof WP_Error,'Wrong PIN must not match.');
check($call(array_replace($notice,['notice_date'=>'2026-02-30'])) instanceof WP_Error,'Invalid dates must be rejected.');
$GLOBALS['options']['wlp_contract_batches_v1']['b']['config']['sandbox']=true;
check($call($notice) instanceof WP_Error,'Sandbox labels cannot match production billing.');
$GLOBALS['options']['wlp_contract_batches_v1']['b']=$b;
$result=$call($notice);check(is_array($result) && $result['recorded'],'Exact match must record.');
check($call($notice)===$result,'Repeated import must be idempotent.');
check($call(array_replace($notice,['manifest'=>'P000000002'])) instanceof WP_Error,'Conflicting evidence cannot overwrite a record.');
check(!WLP_Contract_Shipping::ready_candidates(['b'=>$b]),'Billed labels must never be selectable.');
check(WLP_Contract_Shipping::reminder_summary(['b'=>$b])===['ready'=>0,'pending'=>0,'review'=>0],'Reconciled labels must not produce unresolved reminders.');
$b['state']='awaiting_manifest';$b['pending']=['request','other'];$b['shipments']['other']=batch(1)['shipments']['request'];
$data=$project->invoke(null,['b'=>$b],[]);
check(count($data['submitted'])===1 && $data['submitted'][0]['status']==='auto_billed','Billed parcel has distinct history.');
check(count($data['review'])===1 && count($data['review'][0]['parcels'])===1 && $data['review'][0]['parcels'][0]['order']===1,'Unmatched pending parcel stays visible.');
ob_start();$render->invoke(null,['b'=>$b],[],'submitted',1);$billingHtml=ob_get_clean();
check(str_contains($billingHtml,'Auto-billed by Canada Post') && str_contains($billingHtml,'Penalty amount has not been separately reconciled') && !str_contains($billingHtml,'Restore saved label'),'Billed history explains evidence and offers no label-reuse action.');
ob_start();$render->invoke(null,[],[],'needs',1);$empty=ob_get_clean();
check(!str_contains($empty,'Handoff location') && !str_contains($empty,'Submit selected parcels'),'Empty queue must hide unnecessary submission controls.');
echo "Manifest reconciliation regressions passed.\n";
