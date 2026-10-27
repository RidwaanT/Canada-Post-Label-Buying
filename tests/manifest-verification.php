<?php
/** Carrier verification regressions with isolated options and fake HTTP responses. */
declare(strict_types=1);
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS',86400);
$options=[]; $reads=[]; $httpFail=false;
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; return true; }
function add_option($key,$value,$deprecated='',$autoload=false) { if (isset($GLOBALS['options'][$key])) return false; return update_option($key,$value); }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function wc_get_orders($args) { return []; }
function wc_get_order_statuses() { return ['wc-completed'=>'Completed']; }
function __($s,$domain='') { return $s; }
function is_wp_error($r) { return false; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_get($url,$args) {
 $GLOBALS['reads'][]=$url;
 if ($GLOBALS['httpFail']) throw new RuntimeException('Temporary failure');
 return ['body'=>xmlFixture('created')];
}
function xmlFixture($state,$id='123',$pin='456') { return '<shipment-info xmlns="http://www.canadapost.ca/ws/shipment-v8"><shipment-id>'.$id.'</shipment-id><tracking-pin>'.$pin.'</tracking-pin><shipment-status>'.$state.'</shipment-status><po-number>789</po-number></shipment-info>'; }
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
require __DIR__.'/../includes/class-wlp-settings.php';
require __DIR__.'/../includes/class-wlp-manifest-verifier.php';
$options=[WLP_Settings::OPTION_CUSTOMER=>'123456',WLP_Settings::OPTION_CONTRACT=>'123456',WLP_Settings::OPTION_SANDBOX=>'yes',WLP_Settings::OPTION_PICKUP=>'yes',WLP_Settings::OPTION_ORIGIN_POSTAL=>'K1A0B1',WLP_Settings::OPTION_API_USER=>'fake',WLP_Settings::OPTION_API_PASSWORD=>'fake'];
check(WLP_Manifest_Verifier::parse(xmlFixture('created'),'123','456')['state']==='created','PO must not count as transmitted.');
check(WLP_Manifest_Verifier::parse(xmlFixture('transmitted'),'123','456')['state']==='transmitted','Confirmed carrier status accepted.');
foreach ([xmlFixture('transmitted','999'),xmlFixture('transmitted','123','999'),xmlFixture('unknown'),'<bad/>'] as $invalid) {
 try { WLP_Manifest_Verifier::parse($invalid,'123','456'); throw new LogicException('Accepted invalid evidence'); }
 catch (RuntimeException $e) { check(!$e instanceof LogicException,'Reject malformed or mismatched evidence'); }
}
$config=['customer'=>'123456','contract'=>'0000123456','sandbox'=>true,'postal'=>'K1A0B1','pickup'=>true];
$options['wlp_contract_batches_v1']=[['config'=>$config,'shipments'=>[['order'=>1,'state'=>'created','result'=>['shipment_id'=>'123','tracking_number'=>'456']]]]];
WLP_Manifest_Verifier::run();
$key=WLP_Manifest_Verifier::key($config,'123','456');
$row=$options['wlp_manifest_verification_v1']['parcels'][$key] ?? [];
check(count($reads)===1 && $row['state']==='created','Worker checks saved labels.');
check(!isset($options['wlp_manifest_verification_lock']),'Lock released.');
check(!isset(WLP_Manifest_Verifier::public_snapshot()['parcels'][0]['candidate']),'Public evidence excludes account config.');
WLP_Manifest_Verifier::run(); check(count($reads)===1,'No poll before 15-minute due time.');
$options['wlp_manifest_verification_v1']['parcels'][$key]['next_at']=0; $httpFail=true;
WLP_Manifest_Verifier::run(); $row=$options['wlp_manifest_verification_v1']['parcels'][$key];
check($row['state']==='created' && $row['error'] && $row['checked_at'],'Failures retain evidence but expose error.');
check($row['next_at']>=time()+899,'Retry backs off.');
$options['wlp_manifest_verification_v1']['parcels'][$key]['next_at']=0;
WLP_Manifest_Verifier::run();check($options['wlp_manifest_verification_v1']['parcels'][$key]['next_at']>=time()+1799,'Repeated failures increase retry delay.');
check($options['wlp_contract_batches_v1'][0]['shipments'][0]['state']==='created','Verification never rewrites submission history.');
$options['wlp_manifest_verification_lock']=time();$count=count($reads);WLP_Manifest_Verifier::run();check(count($reads)===$count,'Concurrent worker is skipped.');
echo "Manifest verification regressions passed.\n";
