<?php
/** GET-only carrier verification. Evidence is separate from the submission journal. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class WLP_Manifest_Verifier {
 private const STORE = 'wlp_manifest_verification_v1';
 private const HOOK = 'wlp_verify_manifests';
 private const LOCK = 'wlp_manifest_verification_lock';

 /** Registers even when the admin page is closed. Uses WooCommerce's Action Scheduler. */
 public static function register(): void {
  add_action('init', array(self::class, 'schedule'), 20);
  add_action(self::HOOK, array(self::class, 'run'));
  add_action('rest_api_init', static function(): void {
   register_rest_route('wc/v3/wlp-manifests', '/status', array('methods'=>'GET', 'permission_callback'=>static fn()=>current_user_can('manage_woocommerce'), 'callback'=>static fn()=>rest_ensure_response(self::public_snapshot())));
   register_rest_route('wc/v3/wlp-manifests', '/orders/(?P<id>\d+)', array('methods'=>'GET', 'permission_callback'=>static fn()=>current_user_can('manage_woocommerce'), 'callback'=>array(self::class,'order_status')));
  });
 }
 /** Small batches each minute; individual unresolved parcels are checked every 15 minutes. */
 public static function schedule(): void {
  if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action(self::HOOK, array(), 'wlp-manifests')) {
   as_schedule_recurring_action(time()+30, 60, self::HOOK, array(), 'wlp-manifests', true);
  }
 }
 private static function load(): void {
  foreach (array('settings','meta-keys','order-logistics','canada-post-client','contract-shipping') as $file) { require_once __DIR__.'/class-wlp-'.$file.'.php'; }
 }
 /** Identity includes the carrier account and environment. */
 public static function key(array $config, string $id, string $pin): string {
  return hash('sha256', ($config['sandbox'] ? 'test' : 'live').'|'.$config['customer'].'|'.$id.'|'.$pin);
 }
 /** A PO alone is not proof of transmission; require matching shipment and PIN. */
 public static function parse(string $body, string $id, string $pin): array {
  $previous=libxml_use_internal_errors(true);
  try { $xml=simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET); }
  finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
  if (!$xml || $xml->getName() !== 'shipment-info') { throw new RuntimeException('Invalid shipment response.'); }
  $value=static fn(string $name): string => (string)($xml->xpath('./*[local-name()="'.$name.'"]')[0] ?? '');
  if ($value('shipment-id') !== $id || $value('tracking-pin') !== $pin) { throw new RuntimeException('Carrier shipment identity mismatch.'); }
  $state=$value('shipment-status');
  if (!in_array($state,array('created','transmitted','aborted','cancelled'),true)) { throw new RuntimeException('Unknown carrier shipment state.'); }
  return array('state'=>$state,'po'=>$value('po-number'),'checked_at'=>gmdate('c'));
 }
 /** One authenticated GET; no carrier mutation can be invoked by this method. */
 public static function fetch(array $config, string $id, string $pin): array {
  if (!preg_match('/^[0-9]+$/D',$id) || !preg_match('/^[0-9]+$/D',$pin)) { throw new RuntimeException('Missing commercial shipment identity.'); }
  $client=new WLP_Canada_Post_Client();
  if ($client->contract_is_sandbox() !== $config['sandbox']) { throw new RuntimeException('Carrier environment mismatch.'); }
  return self::parse($client->contract_transport('GET','/rs/'.$config['customer'].'/'.$config['customer'].'/shipment/'.$id),$id,$pin);
 }
 private static function snapshot(): array { $data=get_option(self::STORE,array()); return is_array($data) ? $data : array(); }
 /** Exposes minimal verification evidence, never account config or customer details. */
 public static function public_snapshot(): array {
  $data=self::snapshot(); $rows=array();
  foreach ($data['parcels'] ?? array() as $row) { $rows[]=array_intersect_key($row,array_flip(array('order','shipment_id','tracking','state','po','checked_at','attempted_at','error','journal','expected','next_at'))); }
  return array('last_run'=>$data['last_run'] ?? null,'error'=>$data['error'] ?? null,'next_run'=>function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::HOOK,array(),'wlp-manifests') : false,'parcels'=>$rows);
 }
 /** Checks the exact current primary label for external shipping actions. Errors fail closed. */
 public static function order_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
  self::load(); $order=wc_get_order((int)$request['id']);
  if (!$order instanceof WC_Order) { return new WP_Error('not_found','Order not found.',array('status'=>404)); }
  $meta=WLP_Order_Logistics::read($order);
  if ((string)$request->get_param('shipment_id') !== $meta['shipment_id'] || (string)$request->get_param('tracking_number') !== $meta['tracking_number']) { return new WP_Error('label_changed','Label changed. Reload before marking shipped.',array('status'=>409)); }
  try {
   $result=self::fetch(WLP_Contract_Shipping::config(),$meta['shipment_id'],$meta['tracking_number']);
   return rest_ensure_response($result + array('order_id'=>$order->get_id(),'shipment_id'=>$meta['shipment_id'],'tracking_number'=>$meta['tracking_number'],'confirmed'=>$result['state']==='transmitted'));
  } catch (Throwable $error) { return new WP_Error('verification_unavailable','Carrier verification unavailable. Review Woo Shipping manifests before marking shipped.',array('status'=>503)); }
 }
 /** Retains known orphans and discovers a bounded page of recently modified orders. */
 private static function discover(array $config, array &$data, array &$candidates): void {
  foreach ($data['parcels'] ?? array() as $key=>$row) {
   if (!isset($candidates[$key]) && empty($row['journal']) && ($row['seen_at'] ?? 0)>time()-21*DAY_IN_SECONDS) { $candidates[$key]=$row['candidate']; }
  }
  if (($data['discover_after'] ?? 0)>time()) { return; }
  $page=max(1,(int)($data['discovery_page'] ?? 1));
  $orders=wc_get_orders(array('limit'=>50,'page'=>$page,'orderby'=>'ID','order'=>'ASC','date_modified'=>'>'.(time()-21*DAY_IN_SECONDS),'status'=>array_keys(wc_get_order_statuses())));
  foreach ($orders as $order) {
   $meta=WLP_Order_Logistics::read($order); $labels=array($meta); $plan=$order->get_meta('_wlp_split_shipments',true);
   foreach (is_array($plan) ? ($plan['packages'] ?? array()) : array() as $part) { $labels[]=$part['label'] ?? array(); }
   foreach ($labels as $label) {
    $id=(string)($label['shipment_id'] ?? ''); $pin=(string)($label['tracking_number'] ?? ''); if (!$id || !$pin) { continue; }
    $key=self::key($config,$id,$pin);
    if (!isset($candidates[$key])) { $candidates[$key]=array('config'=>$config,'id'=>$id,'pin'=>$pin,'order'=>$order->get_id(),'journal'=>false,'expected'=>'unknown'); }
    // Persist discovery before limiting carrier calls so no page loses unprocessed rows.
    if (!isset($data['parcels'][$key])) { $data['parcels'][$key]=array('candidate'=>$candidates[$key],'journal'=>$candidates[$key]['journal'],'order'=>$order->get_id(),'shipment_id'=>$id,'tracking'=>$pin,'state'=>'unknown','seen_at'=>time()); }
   }
  }
  $data['discovery_page']=count($orders)===50 ? $page+1 : 1; $data['discover_after']=time()+(count($orders)===50 ? 60 : 900);
 }
 /** Processes at most ten due reads, paced one second apart, with exponential retry backoff. */
 public static function run(): void {
  if (!add_option(self::LOCK,time(),'',false)) {
   // Reclaim only the observed expired lease; another worker cannot lose its new lock.
   $lease=get_option(self::LOCK,0);
   if (is_numeric($lease) && (int)$lease<time()-1800) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",self::LOCK,(string)$lease));
    wp_cache_delete(self::LOCK,'options');
   }
   return;
  }
  try {
   self::load(); $config=WLP_Contract_Shipping::config(); $data=self::snapshot(); $data['parcels']=$data['parcels'] ?? array(); $candidates=array();
   foreach (get_option('wlp_contract_batches_v1',array()) as $batch) {
    foreach ($batch['shipments'] as $shipment) {
     if (in_array($shipment['state'],array('voided','rejected'),true)) { continue; }
     $id=(string)($shipment['result']['shipment_id'] ?? ''); $pin=(string)($shipment['result']['tracking_number'] ?? ''); if (!$id || !$pin) { continue; }
     $key=self::key($batch['config'],$id,$pin);
     $candidates[$key]=array('config'=>$batch['config'],'id'=>$id,'pin'=>$pin,'order'=>$shipment['order'],'journal'=>true,'expected'=>$shipment['state']);
    }
   }
   self::discover($config,$data,$candidates);
   uasort($candidates,static fn($a,$b)=>($data['parcels'][self::key($a['config'],$a['id'],$a['pin'])]['attempted_at'] ?? 0)<=>($data['parcels'][self::key($b['config'],$b['id'],$b['pin'])]['attempted_at'] ?? 0));
   $count=0; $started=time();
   foreach ($candidates as $key=>$candidate) {
    $old=$data['parcels'][$key] ?? array();
    if ($old) {
     $old['journal']=$candidate['journal']; $old['expected']=$candidate['expected'];
     $old['candidate']=$candidate; $data['parcels'][$key]=$old;
    }
    if (($old['next_at'] ?? 0)>time() || (($old['state'] ?? '')==='transmitted' && empty($old['error']))) { continue; }
    if ($count>=10 || time()-$started>40) { break; } if ($count++) { usleep(1000000); }
    $row=array_merge(array('state'=>'unknown'),$old,array('order'=>$candidate['order'],'shipment_id'=>$candidate['id'],'tracking'=>$candidate['pin'],'journal'=>$candidate['journal'],'expected'=>$candidate['expected'],'candidate'=>$candidate,'seen_at'=>time(),'attempted_at'=>time()));
    try {
     if ($candidate['config']['customer'] !== $config['customer'] || $candidate['config']['sandbox'] !== $config['sandbox']) { throw new RuntimeException('Account changed.'); }
     $row=array_merge($row,self::fetch($config,$candidate['id'],$candidate['pin']),array('error'=>null,'failures'=>0,'next_at'=>time()+900));
    } catch (Throwable $error) {
     $row['failures']=min(5,($old['failures'] ?? 0)+1); $row['next_at']=time()+min(14400,900*(2**($row['failures']-1)));
     $row['error']='Carrier check failed. Previous evidence is retained; retry is scheduled.';
    }
    $data['parcels'][$key]=$row; $data['last_run']=gmdate('c'); $data['error']=null; update_option(self::STORE,$data,false);
   }
   $data['last_run']=gmdate('c'); $data['error']=null; update_option(self::STORE,$data,false);
  } catch (Throwable $error) {
   $data=self::snapshot();$data['error']='Automatic verification could not run. Review carrier configuration and the scheduled-action queue.';update_option(self::STORE,$data,false);
  } finally { delete_option(self::LOCK); }
 }
 /** Shows mismatches and orphan labels with per-parcel successful-check times. */
 public static function render(): void {
  $data=self::public_snapshot();
  echo '<section class="notice notice-info inline"><h2>Automatic carrier verification</h2><p>Unresolved shipments are checked about every 15 minutes in background batches. Last run: '.esc_html($data['last_run'] ?? 'Waiting for the scheduled worker').'.</p>';
  if ($data['next_run'] === false) { echo '<p role="alert">No automatic verification action is scheduled. Review WooCommerce Scheduled Actions.</p>'; }
  if (($data['last_run'] && strtotime($data['last_run'])<time()-1800) || (!$data['last_run'] && is_numeric($data['next_run']) && (int)$data['next_run']<time()-900)) { echo '<p role="alert">Automatic verification is overdue. Review WooCommerce Scheduled Actions; saved evidence may be stale.</p>'; }
  if ($data['error']) { echo '<p role="alert">'.esc_html($data['error']).'</p>'; }
  echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Carrier status</th><th>Manifest / PO</th><th>Last successful check</th><th>Attention</th></tr></thead><tbody>';
  foreach ($data['parcels'] as $row) {
   $attention=$row['error'] ?? (($row['state'] ?? '')==='created' ? 'Manifest missing at Canada Post.' : '');
   if (empty($row['journal'])) { $attention.=' No matching local journal entry.'; }
   if (($row['expected'] ?? '')==='transmitted' && ($row['state'] ?? '')==='created') { $attention.=' Local confirmation disagrees with Canada Post.'; }
   if (!$attention && ($row['state'] ?? '')==='transmitted' && ($row['expected'] ?? '')==='transmitted') { continue; }
   if (!$attention && ($row['state'] ?? '')!=='transmitted') { $attention='Awaiting verification or carrier review; not confirmed.'; }
   echo '<tr><td>#'.(int)$row['order'].'</td><td>'.esc_html($row['state']).'</td><td>'.esc_html($row['po'] ?? '').'</td><td>'.esc_html($row['checked_at'] ?? 'Not verified').'</td><td>'.esc_html($attention ?: 'Confirmed at Canada Post; local PDF history may need recovery.').'</td></tr>';
  }
  echo '</tbody></table><p>Verification never submits a manifest or changes delivery status. An unavailable check is not proof of a missing manifest.</p></section>';
 }
}
