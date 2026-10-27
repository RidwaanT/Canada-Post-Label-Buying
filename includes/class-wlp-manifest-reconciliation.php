<?php
/** Records reviewed carrier auto-billing notices separately from submission and accounting state. */
declare(strict_types=1);
defined('ABSPATH') || exit;
final class WLP_Manifest_Reconciliation {
 /** Reads evidence for this exact account, environment, shipment and tracking number. */
 public static function lookup(array $config,array $shipment): array {
  $key=WLP_Manifest_Verifier::key($config,(string)($shipment['result']['shipment_id'] ?? ''),(string)($shipment['result']['tracking_number'] ?? ''));
  return (array)get_option('wlp_auto_billed_'.$key,[]);
 }
 /** POST /wc/v3/wlp-manifests/auto-billed; manager-authenticated, exact journal match, no carrier or accounting writes. */
 public static function record(WP_REST_Request $request) {
  $body=$request->get_json_params();
  foreach (['customer','tracking','manifest','notice_date','message_id'] as $field) {
   if (!is_array($body) || !isset($body[$field]) || !is_string($body[$field])) { return new WP_Error('invalid_evidence','Complete carrier notice evidence is required.',['status'=>400]); }
  }
  if (!preg_match('/^\d{6,10}$/D',$body['customer']) || !preg_match('/^\d{16}$/D',$body['tracking']) || !preg_match('/^P\d{9}$/D',$body['manifest']) || !preg_match('/^[a-f0-9]{16}$/D',$body['message_id']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$body['notice_date']) || !is_int($body['order'] ?? null)) {
   return new WP_Error('invalid_evidence','Invalid carrier notice fields.',['status'=>400]);
  }
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$body['notice_date']);
  if (!$date || $date->format('Y-m-d')!==$body['notice_date'] || $body['notice_date']>wp_date('Y-m-d')) { return new WP_Error('invalid_date','Invalid notice date.',['status'=>400]); }
  $matches=[];
  foreach ((array)get_option('wlp_contract_batches_v1',[]) as $batch) {
   if (!empty($batch['config']['sandbox']) || (string)$batch['config']['customer']!==$body['customer']) { continue; }
   foreach ($batch['shipments'] as $shipment) {
    if ((int)$shipment['order']!==$body['order'] || ($shipment['result']['tracking_number'] ?? '')!==$body['tracking'] || empty($shipment['result']['shipment_id'])) { continue; }
    $matches[WLP_Manifest_Verifier::key($batch['config'],(string)$shipment['result']['shipment_id'],$body['tracking'])]=true;
   }
  }
  if (count($matches)!==1) { return new WP_Error('ambiguous_shipment','An exact unique production shipment match is required.',['status'=>409]); }
  $option='wlp_auto_billed_'.array_key_first($matches);
  $record=array_intersect_key($body,array_flip(['customer','tracking','manifest','notice_date','message_id','order']));
  $existing=get_option($option,[]);
  if ($existing) {
   foreach ($record as $key=>$value) { if (($existing[$key] ?? null)!==$value) { return new WP_Error('evidence_conflict','Existing reconciliation differs; review it before changing evidence.',['status'=>409]); } }
  } else {
   $record['recorded_at']=gmdate('c');$record['recorded_by']=get_current_user_id();
   if (!add_option($option,$record,'',false)) { return new WP_Error('record_conflict','Evidence changed concurrently. Retry to verify.',['status'=>409]); }
  }
  return rest_ensure_response(['recorded'=>true,'order'=>$body['order'],'manifest'=>$body['manifest']]);
 }
}
