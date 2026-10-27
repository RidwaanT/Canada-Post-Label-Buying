<?php
/** Flat manifest workspace; presentation only, existing carrier actions remain authoritative. */
declare(strict_types=1);
defined('ABSPATH') || exit;
trait WLP_Manifest_Workspace {
 /** Minimal read-only MCP evidence from the same projection as the admin page; never contacts the carrier. */
 public static function manifest_summary(int $order_id) {
  if (!wc_get_order($order_id)) { return new WP_Error('order_not_found','Order not found.',['status'=>404]); }
  $all=self::read();
  $evidence=(array)get_option('wlp_manifest_verification_v1',[]);
  $evidence['parcels']=array_filter($evidence['parcels'] ?? [],static fn($proof)=>(int)($proof['order'] ?? 0)===$order_id);
  $data=self::workspace_rows($all,$evidence);$parcels=[];
  $append=static function(array $row,string $status,array $references=[],string $scope='none',?string $recorded=null) use (&$parcels,$order_id): void {
   if ((int)$row['order']!==$order_id) { return; }
   $warning=$row['reason'] ?? null;
   if ($status==='awaiting_confirmation') { $warning='Already submitted; await confirmation. Do not resubmit.'; }
   if ($status==='auto_billed') { $warning='Canada Post auto-billed this parcel with a surcharge; no further submission required. Penalty-only amount is not established.'; }
   if ($status==='needs_review' && !$warning) { $warning='Manifest evidence needs review in Woo; do not infer submission from delivery status.'; }
   $parcels[]=['tracking_number'=>(string)$row['tracking'],'package'=>str_starts_with($row['package'],'split:')?'Split parcel':(str_starts_with($row['package'],'primary:')?'Whole order':$row['package']),
    'status'=>$status,'submission_eligible'=>$status==='needs_submission' && !empty($row['token']), 'handed_over'=>$row['handed_over'] ?? null,
    'manifest_references'=>array_values(array_unique($references)),'reference_scope'=>$scope,'carrier_state'=>$row['proof']['state'] ?? null,
    'carrier_checked_at'=>$row['proof']['checked_at'] ?? null,'evidence_at'=>$recorded,'warning'=>$warning ?: null];
  };
  foreach ($data['needs'] as $row) { $append($row,'needs_submission'); }
  foreach ($data['review'] as $group) { foreach ($group['parcels'] as $row) { $append($row,$group['status']==='pending'?'awaiting_confirmation':'needs_review'); } }
  foreach ($data['submitted'] as $group) {
   foreach ($group['parcels'] as $row) {
    $billing=$row['billing'] ?? null;
    $refs=$billing ? [$billing['manifest']] : array_column($group['manifests'],'po');
    $scope=$refs ? ($billing?'parcel':'batch') : 'none';
    if (!$refs && !empty($row['proof']['po'])) { $refs=[$row['proof']['po']];$scope='parcel'; }
    $dates=array_column($group['manifests'],'confirmed_at');
    $append($row,$billing?'auto_billed':'confirmed',$refs,$scope,$billing['notice_date'] ?? ($dates ? max($dates) : ($row['proof']['checked_at'] ?? null)));
   }
  }
  $counts=array_fill_keys(['needs_submission','awaiting_confirmation','needs_review','confirmed','auto_billed'],0);
  foreach ($parcels as $row) { ++$counts[$row['status']]; }
  return ['order_id'=>$order_id,'as_of'=>gmdate('c'),'live_carrier_lookup'=>false,'basis'=>'Current Woo saved manifest journal, verification cache and reviewed auto-billing notices. Delivery status is independent of manifest status.',
   'counts'=>$counts,'parcels'=>array_slice($parcels,0,100),'total_parcels'=>count($parcels),'truncated'=>count($parcels)>100,
   'status'=>!$parcels?'unknown':(count(array_filter($counts))===1 ? array_search(count($parcels),$counts,true) : 'mixed')];
 }
 /** Projects journal and carrier evidence into unsubmitted parcels and submission history. */
 private static function workspace_rows(array $all, array $evidence): array {
  $eligible=self::ready_candidates($all,true); $needs=[]; $submitted=[]; $review=[]; $seen=[]; $autoBilled=[];
  foreach ($all as $key=>$batch) {
   $pending=[]; $confirmed=[];
   foreach ($batch['shipments'] as $rid=>$shipment) {
    $id=(string)($shipment['result']['shipment_id'] ?? ''); $pin=(string)($shipment['result']['tracking_number'] ?? '');
    $identity=WLP_Manifest_Verifier::key($batch['config'],$id,$pin); $seen[$identity]=true;
    $proof=$evidence['parcels'][$identity] ?? []; $state=$shipment['state'];
    $row=['batch'=>$key,'request'=>$rid,'order'=>(int)$shipment['order'],'package'=>$shipment['package'] ?? 'Whole order','tracking'=>$pin,'day'=>$batch['day'],'state'=>$state,'proof'=>$proof,'sandbox'=>!empty($batch['config']['sandbox'])];
    if ($billing=WLP_Manifest_Reconciliation::lookup($batch['config'],$shipment)) {
     $row['billing']=$billing;
     $billingKey=$billing['customer'].'|'.$billing['manifest'];
     if (!isset($autoBilled[$billingKey])) { $autoBilled[$billingKey]=['batch'=>'','day'=>$billing['notice_date'],'status'=>'auto_billed','parcels'=>[],'manifests'=>[],'config'=>[],'billing'=>$billing]; }
     $autoBilled[$billingKey]['parcels'][]=$row;continue;
    }
    if (in_array($state,['voided','rejected'],true)) { continue; }
    $awaiting=in_array($state,['created','transmitted'],true) && (in_array($rid,$batch['pending'] ?? [],true) || ($state==='created' && in_array($batch['state'],['awaiting_manifest','transmitting','uncertain'],true)));
    if ($awaiting) { $pending[]=$row; continue; }
    if ($state==='transmitted' && ($proof['state'] ?? '')==='created') {
     $review[]=['batch'=>$key,'day'=>$batch['day'],'status'=>'mismatch','parcels'=>[$row],'manifests'=>[],'config'=>$batch['config']]; continue;
    }
    if ($state==='transmitted' || (($proof['state'] ?? '')==='transmitted' && empty($proof['error']))) { $confirmed[]=$row; continue; }
    $token=hash('sha256',$key.'|'.$rid); $row['token']=isset($eligible[$token]) ? $token : '';
    $row['handed_over']=!empty($eligible[$token]['handed_over']);
    $row['reason']=$row['token'] ? '' : (self::hold_reason($shipment) ?: ($state==='created' ? 'Already handed over or batch needs review. Check the carrier before submission.' : 'Recover the existing carrier request before continuing.'));
    if ($row['handed_over']) { $row['reason']='Already shipped or delivered - manifest still needs submission.'; }
    if (!empty($proof['error'])) { $row['reason']=$proof['error']; }
    if (isset($proof['state']) && !in_array($proof['state'],['created','unknown'],true)) { $row['token']='';$row['reason']='Carrier status needs review: '.$proof['state'].'.'; }
    if ($batch['day']>wp_date('Y-m-d')) { $row['token']='';$row['reason']='Available on the label dispatch date.'; }
    if (in_array($state,['requesting','uncertain','voiding','void_uncertain'],true)) {
     $review[]=['batch'=>$key,'day'=>$batch['day'],'status'=>'recovery','parcels'=>[$row],'manifests'=>[],'config'=>$batch['config']];
    } elseif (!$row['token']) {
     $review[]=['batch'=>$key,'day'=>$batch['day'],'status'=>'exception','parcels'=>[$row],'manifests'=>[],'config'=>$batch['config']];
    } else { $needs[]=$row; }
   }
   if ($pending) { $review[]=['batch'=>$key,'day'=>$batch['day'],'status'=>'pending','parcels'=>$pending,'manifests'=>[],'config'=>$batch['pending_handoff'] ?? $batch['config']]; }
   if ($confirmed || !empty($batch['manifests'])) {
    $dates=array_column($batch['manifests'] ?? [],'confirmed_at');
    $submitted[]=['batch'=>$key,'day'=>$dates ? substr(max($dates),0,10) : $batch['day'],'status'=>'confirmed','parcels'=>$confirmed,'manifests'=>$batch['manifests'] ?? [],'config'=>$batch['config'],'handoff_text'=>implode('; ',array_unique(array_map(static fn($m)=>WLP_Contract_Handoff::describe($m['handoff'] ?? $batch['config']),$batch['manifests'] ?? [])))];
   }
  }
  foreach ($evidence['parcels'] ?? [] as $identity=>$proof) {
   if (isset($seen[$identity]) || in_array($proof['state'] ?? '',['aborted','cancelled'],true)) { continue; }
   $row=['batch'=>'','request'=>'','order'=>(int)($proof['order'] ?? 0),'package'=>'Saved label','tracking'=>$proof['tracking'] ?? '', 'day'=>'','state'=>'orphan','proof'=>$proof,'sandbox'=>!empty($proof['candidate']['config']['sandbox']),'token'=>'','reason'=>'No matching submission record. Review this order in Logistics.'];
   if (($proof['state'] ?? '')==='transmitted' && empty($proof['error'])) {
    $submitted[]=['batch'=>'','day'=>'','status'=>'external','parcels'=>[$row],'manifests'=>[],'config'=>[]];
   } else { $review[]=['batch'=>'','day'=>'','status'=>'exception','parcels'=>[$row],'manifests'=>[],'config'=>[]]; }
  }
  usort($needs,static fn($a,$b)=>strcmp($b['day'],$a['day']) ?: $b['order']<=>$a['order']);
  $submitted=array_merge($submitted,array_values($autoBilled));
  usort($submitted,static fn($a,$b)=>strcmp($b['day'],$a['day']));
  return ['needs'=>$needs,'submitted'=>$submitted,'review'=>$review];
 }
 /** Reuses Woo order editing and only shows city/province, not full customer addresses. */
 private static function workspace_order(array $row): void {
  $order=wc_get_order($row['order']);
  $url=$order instanceof WC_Order ? $order->get_edit_order_url() : admin_url('admin.php?page=wlp-logistics');
  echo '<a class="wlp-manifests-order" href="'.esc_url($url).'">#'.(int)$row['order'].'</a>';
  if ($row['sandbox']) { echo ' <span class="wlp-manifests-muted">Test</span>'; }
  echo '<span class="wlp-manifests-secondary">'.esc_html(str_starts_with($row['package'],'primary:')?'Whole order':(str_starts_with($row['package'],'split:')?'Split parcel':$row['package'])).'</span>';
 }
 private static function workspace_destination(array $row): void {
  $order=wc_get_order($row['order']);
  if (!$order instanceof WC_Order) { echo '—';return; }
  $destination=implode(', ',array_filter([$order->get_shipping_city(),$order->get_shipping_state(),$order->get_shipping_country()]));
  $meta=WLP_Order_Logistics::read($order);
  echo esc_html($destination ?: 'Not available');
  echo '<span class="wlp-manifests-secondary">'.esc_html($meta['service_name'] ?? 'Canada Post').'</span>';
 }
 /** Keeps existing nonce-protected recovery actions inside a row disclosure. */
 private static function workspace_actions(string $key,string $rid,array $batch,array $shipment): void {
  if (in_array($shipment['state'],['requesting','uncertain'],true)) { self::form('recover_label',$key,$rid);echo '<button class="button">Recover label</button></form>'; }
  if (in_array($shipment['state'],['created','transmitted','voided'],true)) { self::form('restore',$key,$rid);echo '<button class="button">'.($shipment['state']==='voided'?'Clear void from order':'Restore saved label').'</button></form>'; }
  if ($shipment['state']==='created' && $batch['state']==='open') { self::form('void',$key,$rid);echo '<label><input type="checkbox" name="confirm_void" value="yes" required> Confirm this label is unused</label> <button class="button">Void label</button></form>'; }
  if (in_array($shipment['state'],['voiding','void_uncertain'],true)) { self::form('recover_void',$key,$rid);echo '<p>Do not reuse this label until the void is confirmed.</p><button class="button">Check void outcome</button></form>'; }
 }
 private static function workspace_detail(array $row,array $all): void {
  echo '<details class="wlp-manifests-detail"><summary>Details &amp; recovery</summary><div>';
  if ($row['tracking']) { echo '<p>Tracking: <span class="wlp-manifests-tracking">'.esc_html($row['tracking']).'</span></p>'; }
  $proof=$row['proof'];
  if (!empty($row['reason'])) { echo '<p>'.esc_html($row['reason']).'</p>'; }
  if (!empty($row['billing'])) { echo '<p>Auto-billed by Canada Post: '.esc_html($row['billing']['manifest']).' on '.esc_html($row['billing']['notice_date']).'. Carrier notice says no further submission is required. Penalty amount has not been separately reconciled.</p></div></details>';return; }
  echo '<p>Carrier: '.esc_html($proof['state'] ?? 'Not yet verified').'. Last successful check: '.esc_html($proof['checked_at'] ?? 'Not available').'.</p>';
  if (!empty($proof['error'])) { echo '<p>'.esc_html($proof['error']).'</p>'; }
  if ($row['batch']) {
   echo '<p class="wlp-manifests-muted">Request: '.esc_html($row['request']).'</p>';
   self::workspace_actions($row['batch'],$row['request'],$all[$row['batch']],$all[$row['batch']]['shipments'][$row['request']]);
  }
  echo '</div></details>';
 }
 private static function workspace_url(string $tab,int $page=1): string {
  return add_query_arg(['page'=>'wlp-contract-manifests','manifest_tab'=>$tab,'manifest_page'=>$page],admin_url('admin.php'));
 }
 private static function workspace_pagination(string $tab,int $page,int $total): void {
  $pages=max(1,(int)ceil($total/20));
  echo '<nav class="wlp-manifests-pagination" aria-label="Manifest pages"><span>'.($total ? (($page-1)*20+1).'–'.min($page*20,$total).' of '.$total : '0 records').'</span>';
  if ($page>1) { echo '<a class="button" href="'.esc_url(self::workspace_url($tab,$page-1)).'">Previous</a>'; }
  if ($page<$pages) { echo '<a class="button" href="'.esc_url(self::workspace_url($tab,$page+1)).'">Next</a>'; }
  echo '</nav>';
 }
 /** Renders a bounded, flat queue; actions use the original form fields and nonce. */
 private static function workspace_needs(array $rows,array $all): void {
  self::form('finish_day','','','wlp-manifest-selection');
  echo '<input type="hidden" name="pickup_day" value="'.esc_attr(wp_date('Y-m-d')).'"></form>';
  if (!$rows) { echo '<div class="wlp-manifests-empty"><strong>No parcels ready for submission</strong><p>Any uncertain submissions remain in the review table below.</p></div>';return; }
  echo '<div class="wlp-manifests-toolbar"><button type="button" class="button" data-pick-all>Select eligible on this page</button><button type="button" class="button" data-pick-none>Clear</button><span data-pick-count aria-live="polite">0 parcels selected</span></div>';
  echo '<table class="widefat wlp-manifests-table"><caption class="screen-reader-text">Unsubmitted parcels</caption><thead><tr><th scope="col">Select</th><th scope="col">Date</th><th scope="col">Order</th><th scope="col">Destination / service</th><th scope="col">Tracking</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>';
  foreach ($rows as $i=>$row) {
   echo '<tr'.(!empty($row['handed_over'])?' class="wlp-manifest-overdue"':'').'><td data-label="Select"><input type="checkbox" form="wlp-manifest-selection" name="parcels[]" value="'.esc_attr($row['token']).'" aria-label="Select order '.(int)$row['order'].' '.esc_attr($row['package']).'"'.($row['token']?'':' disabled').' aria-describedby="wlp-reason-'.$i.'"></td><td data-label="Date">'.esc_html($row['day'] ?: '—').'</td><td data-label="Order">';self::workspace_order($row);
   echo '</td><td data-label="Destination / service">';self::workspace_destination($row);
   echo '</td><td data-label="Tracking" class="wlp-manifests-tracking">'.esc_html($row['tracking'] ?: 'Not available').'</td><td data-label="Status"><span class="wlp-manifests-badge '.(!empty($row['handed_over'])?'is-overdue':($row['token']?'is-ready':'is-warning')).'">'.(!empty($row['handed_over'])?'! NEEDS SUBMISSION':($row['token']?'Not submitted':'Needs review')).'</span><span class="wlp-manifests-secondary" id="wlp-reason-'.$i.'">'.esc_html($row['reason']).'</span></td><td data-label="Actions">';self::workspace_detail($row,$all);echo '</td></tr>';
  }
  echo '</tbody></table><div class="wlp-manifests-submit-bar"><label for="wlp-handoff">Handoff location<select id="wlp-handoff" name="handoff_id" form="wlp-manifest-selection" required>';
  foreach (WLP_Contract_Handoff::locations() as $id=>$point) { echo '<option value="'.esc_attr($id).'"'.selected($id,WLP_Contract_Handoff::DEFAULT_ID,false).'>'.esc_html(WLP_Contract_Handoff::describe($point)).'</option>'; }
  echo '</select></label><label class="wlp-manifests-readiness"><input type="checkbox" form="wlp-manifest-selection" name="ready" value="yes" required> Selected parcels need manifest submission and are ready for handoff or have already been handed over.</label><button type="submit" form="wlp-manifest-selection" class="button button-primary" data-finish-submit>Submit selected parcels</button></div><p class="wlp-manifests-muted">Use the actual or planned handoff location. Canada Post status is checked again before submission. Submission does not mark orders shipped or book a pickup.</p>';
 }
 /** Flat submission/review table, with protected recovery and PDF links in each row. */
 private static function workspace_submissions(array $rows,array $all,bool $review): void {
  if (!$rows) { echo '<div class="wlp-manifests-empty">No confirmed manifests in the saved records.</div>';return; }
  echo '<table class="widefat wlp-manifests-table"><caption class="screen-reader-text">'.($review?'Submissions needing confirmation or review':'Confirmed manifests').'</caption><thead><tr><th scope="col">Date</th><th scope="col">Manifest</th><th scope="col">Parcels / orders</th><th scope="col">Handoff</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>';
  foreach ($rows as $group) {
   $references=!empty($group['billing']) ? [$group['billing']['manifest']] : array_column($group['manifests'],'po');
   if (!$references) { $references=array_values(array_unique(array_filter(array_map(static fn($r)=>$r['proof']['po'] ?? '',$group['parcels'])))); }
   $labels=['auto_billed'=>'Auto-billed by Canada Post','exception'=>'Needs review','pending'=>'Awaiting confirmation','mismatch'=>'Carrier mismatch','recovery'=>'Needs recovery','external'=>'Carrier confirmed','confirmed'=>'Confirmed'];
   echo '<tr><td data-label="Date">'.esc_html($group['day'] ?: 'Not recorded').'</td><td data-label="Manifest" class="wlp-manifests-tracking">'.esc_html($references ? implode(', ',$references) : 'Not confirmed').'</td><td data-label="Parcels / orders">'.count($group['parcels']).' parcel(s)<span class="wlp-manifests-secondary">';
   $ids=array_values(array_unique(array_column($group['parcels'],'order')));
   echo esc_html(implode(', ',array_map(static fn($id)=>'#'.$id,array_slice($ids,0,4))));
   if (count($ids)>4) { echo ' +'.(count($ids)-4).' more'; }echo '</span></td><td data-label="Handoff">'.esc_html(!empty($group['handoff_text']) ? $group['handoff_text'] : ($group['config'] ? WLP_Contract_Handoff::describe($group['config']) : 'Not recorded')).'</td><td data-label="Status"><span class="wlp-manifests-badge '.(($review || $group['status']==='auto_billed')?'is-warning':'is-ready').'">'.esc_html($labels[$group['status']]).'</span>';
   if ($review) { echo '<span class="wlp-manifests-secondary">Do not resubmit.</span>'; }
   echo '</td><td data-label="Actions">';
   $batch=$group['batch'] ? $all[$group['batch']] : null;
   if ($group['status']==='pending' && $batch) {
    if (!empty($batch['manifest_links'])) { self::form('refresh',$group['batch']);echo '<button class="button">Check confirmation</button></form>'; }
    elseif (in_array($batch['state'],['uncertain','transmitting'],true)) { self::form('recover_transmission',$group['batch']);echo '<button class="button">Recover submission</button></form>'; }
   }
   foreach ($group['manifests'] as $index=>$manifest) {
    $url=wp_nonce_url(add_query_arg(['action'=>'wlp_contract_manifest_pdf','batch'=>$group['batch'],'index'=>$index],admin_url('admin-post.php')),'wlp_contract_manifest_pdf');
    echo '<a class="button" target="_blank" rel="noopener" href="'.esc_url($url).'">PDF '.esc_html($manifest['po']).'</a> ';
   }
   echo '<details class="wlp-manifests-detail"><summary>Parcels &amp; details</summary><div>';
   if (!$group['manifests'] && !$review && $group['status']!=='auto_billed') { echo '<p>Carrier transmission is confirmed. No local manifest PDF is linked.</p>'; }
   foreach ($group['parcels'] as $row) { echo '<div class="wlp-manifests-parcel">';self::workspace_order($row);echo '<p>';self::workspace_destination($row);echo '</p>';self::workspace_detail($row,$all);echo '</div>'; }
   // Preserve cleanup of terminal labels without expanding the normal workspace.
   if ($batch && !$review) { foreach ($batch['shipments'] as $rid=>$shipment) { if ($shipment['state']==='voided') { echo '<p>Voided label · Order #'.(int)$shipment['order'].'</p>';self::workspace_actions($group['batch'],$rid,$batch,$shipment); } } }
   echo '</div></details></td></tr>';
  }
  echo '</tbody></table>';
 }
 /** Two-tab workspace with unresolved submissions kept visible on the main page. */
 private static function workspace_render(array $all,array $evidence,string $tab,int $page,int $review_page=1): void {
  $data=self::workspace_rows($all,$evidence);$tab=$tab==='submitted'?'submitted':'needs';
  $list=$data[$tab==='submitted'?'submitted':'needs'];$page=min(max(1,$page),max(1,(int)ceil(count($list)/20)));
  echo '<div class="wrap wlp-manifests"><header class="wlp-manifests-header"><h1>Shipping manifests</h1><a href="'.esc_url(admin_url('admin.php?page=wlp-logistics')).'">Back to Logistics</a></header>';
  $health=WLP_Manifest_Verifier::public_snapshot();
  $stale=!$health['last_run'] || strtotime($health['last_run'])<time()-1800 || !empty($health['error']) || $health['next_run']===false;
  echo '<p class="wlp-manifests-scheduler'.($stale?' is-warning':'').'">'.($stale?'Background label verification needs attention':'Background label verification active').' · Last worker run: '.esc_html($health['last_run'] ?? 'Not yet run').'</p>';
  echo '<p class="wlp-manifests-muted">Pending confirmations are checked automatically when this page opens (up to 3 batches per load; 60-second cooldown per batch). This works independently of background verification.</p>';
  echo '<nav class="nav-tab-wrapper" aria-label="Manifest views">';
  foreach (['needs'=>'Needs submission','submitted'=>'Submitted / Auto-billed'] as $value=>$label) { echo '<a class="nav-tab'.($value===$tab?' nav-tab-active':'').'"'.($value===$tab?' aria-current="page"':'').' href="'.esc_url(self::workspace_url($value)).'">'.esc_html($label).' <span class="wlp-manifests-tab-count">'.count($data[$value==='needs'?'needs':'submitted']).'</span></a>'; }echo '</nav>';
  if ($tab==='needs') {
   if ($data['review']) { echo '<p class="wlp-manifests-review-link"><a href="#wlp-manifest-review">'.count($data['review']).' record(s) awaiting confirmation or needing review below</a></p>'; }
   self::workspace_needs(array_slice($list,($page-1)*20,20),$all);self::workspace_pagination('needs',$page,count($list));
   if ($data['review']) {
    $review_page=min(max(1,$review_page),max(1,(int)ceil(count($data['review'])/20)));
    echo '<section id="wlp-manifest-review" class="wlp-manifests-review"><h2>Awaiting confirmation / Needs review <span class="wlp-manifests-tab-count">'.count($data['review']).'</span></h2><p>These parcels need review, not a new submission. Expand a row for the reason and recovery options.</p>';
    self::workspace_submissions(array_slice($data['review'],($review_page-1)*20,20),$all,true);
    echo '<nav class="wlp-manifests-pagination" aria-label="Review pages"><span>Page '.$review_page.' of '.max(1,(int)ceil(count($data['review'])/20)).'</span>';
    foreach ([$review_page-1=>'Previous',$review_page+1=>'Next'] as $target=>$label) { if ($target>=1 && $target<=ceil(count($data['review'])/20)) { echo '<a class="button" href="'.esc_url(add_query_arg('review_page',$target,self::workspace_url('needs',$page)).'#wlp-manifest-review').'">'.$label.'</a>'; } }
    echo '</nav></section>';
   }
  } else { self::workspace_submissions(array_slice($list,($page-1)*20,20),$all,false);self::workspace_pagination('submitted',$page,count($list)); }
  if (get_option(self::LOCK) || get_option(self::QUICK_LOCK) || get_option('wlp_manifest_quick_worker_lock')) {
   echo '<details class="wlp-manifests-detail"><summary>Interrupted operation</summary><p>Only release a lock after 15 minutes. Recovery does not resend a carrier request.</p>';
   if (get_option(self::LOCK)) { self::form('unlock');echo '<button class="button">Release operation lock</button></form>'; }
   if (get_option(self::QUICK_LOCK) || get_option('wlp_manifest_quick_worker_lock')) { self::form('release_quick_job');echo '<button class="button">Release quick-submit lock</button></form>'; }echo '</details>';
  }
  $job=get_option(self::QUICK_JOB,[]);
  if (in_array($job['state'] ?? '',['queued','processing','awaiting_confirmation'],true)) { echo '<p role="status">'.esc_html($job['message'] ?? 'A manifest submission is in progress.').'</p>'; }
  echo '</div>';
 }
}
