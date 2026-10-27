<?php
/** Exercises real multi-batch orchestration against an asynchronous fake carrier. */
require __DIR__.'/manifest-visibility.php';
require __DIR__.'/../includes/class-wlp-settings.php';
function add_option($key,$value,$unused='',$autoload=false): bool { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key]=$value;return true; }
function update_option($key,$value,$autoload=false): bool { $GLOBALS['options'][$key]=$value;return true; }
function delete_option($key): void { unset($GLOBALS['options'][$key]); }
class WLP_Canada_Post_Client {
 public static array $posts=[];
 public static int $polls=0;
 public static bool $failSecond=false;
 public static bool $confirmed=false;
 public function contract_is_sandbox(): bool { return true; }
 public function contract_transport($method,$url,$body='',$accept=''): string {
  if ($method==='POST') {
   if (self::$failSecond && count(self::$posts)===1) { throw new RuntimeException('Carrier timeout'); }
   self::$posts[]=$body;
   return '<manifests><link rel="manifest" href="https://example.com/manifest/'.count(self::$posts).'"/></manifests>';
  }
  if (str_contains($url,'/shipment/')) { $id=basename($url);return '<shipment-info><shipment-id>'.$id.'</shipment-id><tracking-pin>'.$id.'</tracking-pin><shipment-status>created</shipment-status></shipment-info>'; }
  ++self::$polls;
  if (self::$confirmed) { return '<manifest><po-number>P000000001</po-number><link rel="artifact" href="https://example.com/manifest.pdf"/></manifest>'; }
  return '<manifest><po-number>EMPTY</po-number></manifest>';
 }
}
foreach ((new ReflectionClass(WLP_Settings::class))->getConstants() as $name=>$value) { if (str_starts_with($name,'OPTION_ORIGIN_')) $GLOBALS['options'][$value]='Example'; }
$GLOBALS['options'][WLP_Settings::OPTION_CUSTOMER]='123456';
$GLOBALS['options'][WLP_Settings::OPTION_CONTRACT]='123456';
$GLOBALS['options'][WLP_Settings::OPTION_PICKUP]='yes';
$GLOBALS['options'][WLP_Settings::OPTION_ORIGIN_POSTAL]='K1A0B1';
$config=WLP_Contract_Shipping::config();$batches=[];$tokens=[];
foreach (['first'=>[1,2],'second'=>[3,4,5]] as $key=>$ids) {
 $batches[$key]=['day'=>'2026-10-01','config'=>$config,'state'=>'open','shipments'=>[],'manifests'=>[]];
 foreach ($ids as $id) {
  $GLOBALS['orders'][$id]=new WC_Order(['shipment_id'=>(string)$id,'shipped_at'=>'2026-10-02']);
  $batches[$key]['shipments']['r'.$id]=['order'=>$id,'package'=>'primary:0','state'=>'created','result'=>['shipment_id'=>(string)$id,'tracking_number'=>(string)$id]];
  $tokens[]=hash('sha256',$key.'|r'.$id);
 }
}
$input=['ready'=>'yes','pickup_day'=>wp_date('Y-m-d'),'handoff_id'=>'hamilton-pickup','parcels'=>$tokens];
$run=new ReflectionMethod(WLP_Contract_Shipping::class,'finish_day');
$GLOBALS['options']['wlp_contract_batches_v1']=$batches;
$run->invoke(null,$input);
$saved=$GLOBALS['options']['wlp_contract_batches_v1'];
check(count(WLP_Canada_Post_Client::$posts)===2 && WLP_Canada_Post_Client::$polls===0,'Both batches must be sent without waiting for manifest PDFs.');
check($saved['first']['pending']===['r1','r2'] && $saved['second']['pending']===['r3','r4','r5'],'All five selected parcels must be durably awaiting confirmation.');
try { $run->invoke(null,$input);throw new LogicException('Unexpected retry'); } catch (RuntimeException $e) { check(count(WLP_Canada_Post_Client::$posts)===2,'Stale selections cannot retransmit pending parcels.'); }
$GLOBALS['options']['wlp_contract_batches_v1']=$batches;WLP_Canada_Post_Client::$posts=[];WLP_Canada_Post_Client::$failSecond=true;
try { $run->invoke(null,$input);throw new LogicException('Unexpected success'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'1 of 2 selected batches sent'),'Partial failure must report completed batch count.'); }
$saved=$GLOBALS['options']['wlp_contract_batches_v1'];
check($saved['first']['state']==='awaiting_manifest' && $saved['second']['state']==='uncertain','A real timeout must preserve sent and uncertain states without retrying.');
$posts=count(WLP_Canada_Post_Client::$posts);
WLP_Contract_Shipping::check_page_confirmations();
check(WLP_Canada_Post_Client::$polls===1,'Page load must check saved links, but never uncertain transmissions.');
WLP_Contract_Shipping::check_page_confirmations();
check(WLP_Canada_Post_Client::$polls===1,'Immediate page reloads must respect the per-batch cooldown.');
$GLOBALS['options']['wlp_manifest_page_checks']=[];
$GLOBALS['options']['wlp_contract_operation_lock']='busy';
WLP_Contract_Shipping::check_page_confirmations();
check(WLP_Canada_Post_Client::$polls===1,'Page checks must not interfere with an active operation.');
unset($GLOBALS['options']['wlp_contract_operation_lock']);
WLP_Canada_Post_Client::$confirmed=true;
WLP_Contract_Shipping::check_page_confirmations();
$saved=$GLOBALS['options']['wlp_contract_batches_v1'];
check($saved['first']['state']==='manifest_ready' && $saved['second']['state']==='uncertain','Confirmed results advance only the matching pending batch.');
check(count(WLP_Canada_Post_Client::$posts)===$posts,'Page checking must never send a carrier POST.');
WLP_Contract_Shipping::check_page_confirmations();
check(WLP_Canada_Post_Client::$polls===2,'Confirmed batches must not be polled again.');
echo "Multi-batch submission regressions passed.\n";
