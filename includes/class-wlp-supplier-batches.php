<?php
/** Private supplier-order evidence. Never sends mail or changes Woo order status. */
declare(strict_types=1);
if(!defined('ABSPATH')){exit;}
final class WLP_Supplier_Batches {
 public const META='_wlp_wholesale_sourcing_v1';
 public const PLACED='_wlp_wholesale_supplier_order_placed_at';
 public static function progress(array $lines,array $state):array {
  $ordered=[];$received=[];$legacy=false;$seen=[];
  foreach(($state['events']??[]) as $event){if(($event['mode']??'')==='order'&&!empty($event['sentAt']))$legacy=true;}
  foreach(($state['batchEvents']??[]) as $event){
   if(($event['mode']??'')!=='order'||empty($event['sentAt'])||isset($seen[$event['key']??'']))continue;
   $seen[$event['key']??'']=true;$source=$event['source']??'';
   if(!in_array($source,['jkl','xp'],true))continue;
   if(!isset($event['quantities'])){$legacy=true;continue;}
   foreach($event['quantities'] as $key=>$qty){
    $ordered[$key][$source]=($ordered[$key][$source]??0)+max(0,(int)$qty);
    if(!empty($event['receivedAt']))$received[$key][$source]=($received[$key][$source]??0)+max(0,(int)$qty);
   }
  }
  $out=['total'=>0,'ordered'=>0,'received'=>0,'local'=>0,'unassigned'=>0,'pendingTotal'=>0,'pending'=>[],'legacyReview'=>$legacy];
  foreach($lines as $line){$key=$line['key'];$qty=max(0,(int)$line['quantity']);$assigned=0;$out['total']+=$qty;
   foreach(['jkl','xp','hamilton'] as $source){$n=max(0,(int)($line['allocations'][$source]??0));$assigned+=$n;
    if($source==='hamilton'){$out['local']+=$n;continue;}
    $placed=min($n,$ordered[$key][$source]??0);$arrived=min($placed,$received[$key][$source]??0);
    $out['ordered']+=$placed;$out['received']+=$arrived;$out['pending'][$key][$source]=max(0,$n-$placed);$out['pendingTotal']+=max(0,$n-$placed);
   }
   $out['unassigned']+=max(0,$qty-$assigned);
  }
  $out['allReady']=$out['total']>0&&!$legacy&&$out['received']===$out['total'];
  return $out;
 }
 public static function quantities(array $lines,string $source,string $mode):array {
  $q=[];foreach($lines as $line){$qty=(int)($mode==='order'?($line['pendingAllocations'][$source]??$line['allocations'][$source]??0):($line['allocations'][$source]??0));if($qty>0)$q[$line['key']]=$qty;}ksort($q);return $q;
 }
 public static function token(array $lines,string $source,string $mode):string {
  $values=[];$q=self::quantities($lines,$source,$mode);foreach($lines as $line){if(isset($q[$line['key']]))$values[$line['key']]=[$line['name'],$line['unit']??'vials',$q[$line['key']]];}ksort($values);return hash('sha256',json_encode([5,$source,$mode,$values]));
 }
 /** Combine customer demand before rounding supplier peptide requests to whole ten-vial kits. */
 public static function requested_items(array $items):array {
  $products=[];
  foreach($items as $item){
   $name=(string)$item['name'];$unit=$item['unit']??'vials';$key=json_encode([$name,$unit]);
   if(!isset($products[$key]))$products[$key]=['name'=>$name,'unit'=>$unit,'required'=>0];
   $products[$key]['required']+=max(0,(int)$item['quantity']);
  }
  foreach($products as &$product){$required=$product['required'];$product['quantity']=$product['unit']==='vials'?(int)(ceil($required/10)*10):$required;$product['extra']=$product['quantity']-$required;}unset($product);
  return array_values($products);
 }
 public static function batch_key(string $source,string $mode,array $tokens):string {ksort($tokens,SORT_NUMERIC);return 'batch-'.$source.'-'.$mode.'-'.hash('sha256',json_encode($tokens));}
 /** Validate every order before any save, retain exact quantities, and restore private metadata if a save fails. */
 public static function record(array $orders,string $source,string $mode,string $step,array $tokens,string $batchKey,callable $read,callable $stage,int $draftVersion=4):array {
  if($step==='sent'&&$draftVersion!==5)throw new RuntimeException('The supplier draft format changed. Reload Logistics and review the updated request before recording it.');
  $ids=array_map(static fn($o)=>(int)$o->get_id(),$orders);sort($ids);$locks=[];$before=[];$plans=[];$now=current_time('mysql');
  try{
   foreach($orders as $o){$lock='wlp_supplier_batch_'.$o->get_id();if(!add_option($lock,time(),'',false))throw new RuntimeException('A supplier update is already running. Reopen the batch.');$locks[]=$lock;}
   if($step==='sent'){$tokenIds=array_map('intval',array_keys($tokens));sort($tokenIds);if($tokenIds!==$ids)throw new RuntimeException('Review the exact included orders before recording this batch.');$batchKey=self::batch_key($source,$mode,$tokens);}
   foreach($orders as $o){$o->read_meta_data(true);$payload=$read($o);if(!empty($payload['error'])||array_filter($payload['lines']??[],static fn($line)=>!empty($line['invalid'])))throw new RuntimeException('Reconcile order #'.$o->get_id().' before continuing.');
    $state=$o->get_meta(self::META,true);$state=is_array($state)?$state:[];$events=$state['batchEvents']??[];
    if($step==='sent'){
     $prior=null;foreach($events as $event){if(($event['key']??'')===$batchKey&&($event['orderIds']??[])===$ids&&($event['source']??'')===$source&&($event['mode']??'')===$mode&&!empty($event['sentAt'])){$prior=$event;break;}}
     if($prior){$plans[]=['order'=>$o,'state'=>$state,'quantities'=>$prior['quantities']??[],'items'=>$prior['items']??[],'token'=>$prior['token']??''];continue;}
     if($mode==='order'&&!empty($payload['supplierProgress']['legacyReview']))throw new RuntimeException('Order #'.$o->get_id().' has an older supplier record. Review it before placing another supplier order.');
     if($mode==='order'&&!$o->is_paid())throw new RuntimeException('Every supplier order must be paid before recording it as placed.');
     $token=self::token($payload['lines'],$source,$mode);
     if(!isset($tokens[(string)$o->get_id()])||!hash_equals($token,(string)$tokens[(string)$o->get_id()]))throw new RuntimeException('Source quantities changed on order #'.$o->get_id().'. Reopen and review the supplier message.');
     $quantities=self::quantities($payload['lines'],$source,$mode);if(!$quantities)throw new RuntimeException('No outstanding '.$source.' quantities remain on order #'.$o->get_id().'.');
     $items=[];foreach($payload['lines'] as $line){if(isset($quantities[$line['key']]))$items[]=['key'=>$line['key'],'name'=>$line['name'],'unit'=>$line['unit']??'vials','quantity'=>$quantities[$line['key']]];}
     $plans[]=['order'=>$o,'state'=>$state,'quantities'=>$quantities,'items'=>$items,'token'=>$token];
    }else{
     $found=null;foreach($events as $event){if(($event['key']??'')===$batchKey){$found=$event;break;}}
     if(!$found||($found['source']??'')!==$source||($found['mode']??'')!==$mode||empty($found['sentAt'])||($found['orderIds']??[])!==$ids)throw new RuntimeException('The supplier batch changed. Reopen it before recording the next step.');
     $plans[]=['order'=>$o,'state'=>$state,'quantities'=>$found['quantities']??[]];
    }
   }
   $requestedItems=self::requested_items(array_merge([], ...array_column($plans,'items')));
   // The supplied fingerprint also makes a retry of the same recorded batch idempotent.
   foreach($plans as $plan){$o=$plan['order'];$state=$plan['state'];$events=$state['batchEvents']??[];$index=null;foreach($events as $i=>$event){if(($event['key']??'')===$batchKey){$index=$i;break;}}
    if($index===null){$events[]=['key'=>$batchKey,'source'=>$source,'mode'=>$mode,'orderIds'=>$ids,'quantities'=>$plan['quantities'],'items'=>$plan['items']??[],'token'=>$plan['token']??'','sentAt'=>$now,'draftVersion'=>5,'requestedItems'=>$requestedItems];$index=count($events)-1;}
    $field=['sent'=>'sentAt','confirmed'=>'confirmedAt','received'=>'receivedAt'][$step];if(empty($events[$index][$field]))$events[$index][$field]=$now;
    $before[$o->get_id()]=['order'=>$o,'meta'=>[]];foreach([self::META,self::PLACED,'_wlp_wholesale_workflow_stage','_wlp_wholesale_workflow_stage_at','_wlp_wholesale_inspected_at'] as $key)$before[$o->get_id()]['meta'][$key]=$o->get_meta($key,true);
    $state['batchEvents']=$events;$o->update_meta_data(self::META,$state);
    if($mode==='order'){
     if(!$o->get_meta(self::PLACED,true))$o->update_meta_data(self::PLACED,$now);
     $current=$stage($o);$progress=$read($o)['supplierProgress'];
     if($step==='received'&&$progress['allReady']&&in_array($current,['supplier_order','inbound_hamilton'],true)){
      $o->update_meta_data('_wlp_wholesale_workflow_stage','ready_to_ship');$o->update_meta_data('_wlp_wholesale_workflow_stage_at',$now);$o->update_meta_data('_wlp_wholesale_inspected_at',$now);
     }
    }
    $o->save();
   }
   return ['key'=>$batchKey,'field'=>['sent'=>'sentAt','confirmed'=>'confirmedAt','received'=>'receivedAt'][$step],'at'=>$now,'orderIds'=>$ids];
  }catch(Throwable $e){$restoreFailed=false;foreach($before as $saved){try{foreach($saved['meta'] as $key=>$value)$saved['order']->update_meta_data($key,$value);$saved['order']->save();}catch(Throwable $restore){$restoreFailed=true;}}if($restoreFailed)throw new RuntimeException('The batch update failed and could not be fully restored. Reopen all included orders and review supplier history before retrying.',0,$e);throw $e;}
  finally{foreach($locks as $lock)delete_option($lock);}
 }
}
