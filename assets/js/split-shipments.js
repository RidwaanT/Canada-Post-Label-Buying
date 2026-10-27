(function () {
  'use strict';
  if (typeof wlpAdmin === 'undefined') return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const dialog = document.createElement('dialog');
  dialog.className = 'wlp-split-dialog';
  document.body.appendChild(dialog);
  let data = null, editing = '', splitting = false, previousFocus = null, busy = false, changed = false;
  const wholesaleBox = {weight:1.5, length:31, width:23.5, height:12};
  const smallBox = {weight:1.5, length:28, width:22, height:6};
  const boxes = {large:wholesaleBox, small:smallBox, medium:{weight:0.5, length:17, width:11, height:6}};
  const matchesBox = (parcel, box) => ['length','width','height'].every(key=>Number(parcel[key])===box[key]);
  const today = () => data.today || new Date().toLocaleDateString('en-CA');
  const parcelValues = section => {
    const values = {dispatchDate:section.querySelector('[data-dispatch]').value,
      signature:section.querySelector('[data-signature]').checked?'yes':'no',
      pickup:section.querySelector('[data-pickup]').checked?'yes':'no'};
    section.querySelectorAll('[data-measure]').forEach(el=>values[el.dataset.measure]=el.value);
    return values;
  };
  const parcelKey = section => JSON.stringify(parcelValues(section));
  const clearRates = section => {
    section?.querySelector('[data-rates]')?.replaceChildren();
    if(section) delete section.dataset.quote;
  };
  const labelFields = p => `<div class="wlp-split-label" data-label-panel>
    <h4>Canada Post label</h4>
    <label>Dispatch date<input type="date" data-dispatch min="${esc(today())}" value="${esc(p.dispatchDate && p.dispatchDate>=today()?p.dispatchDate:today())}" required></label>
    <fieldset class="wlp-split-boxes"><legend>Box</legend>${[['large','Large box'],['small','Small box'],['medium','Medium retail box']].map(([id,name])=>`<label><input type="radio" name="box-${esc(p.id)}" data-split-box="${id}" ${matchesBox({...wholesaleBox,...p.package},boxes[id])?'checked':''}> <strong>${name}</strong><span>${boxes[id].length} x ${boxes[id].width} x ${boxes[id].height} cm</span></label>`).join('')}</fieldset>
    <div class="wlp-split-measures">${[['weight','Packed weight (kg)',30],['length','Length (cm)',200],['width','Width (cm)',200],['height','Height (cm)',200]].map(([key,label,max])=>`<label>${label}<input type="number" min="0.001" max="${max}" step="0.001" data-measure="${key}" value="${esc(p.package?.[key] ?? wholesaleBox[key])}" required></label>`).join('')}</div>
    <label><input type="checkbox" data-signature checked> Signature required</label><label><input type="checkbox" data-pickup> Card for Pickup</label>
    <p class="wlp-split-error" data-label-error role="alert"></p>
    <button type="button" class="button button-primary" data-split-rates>Get Canada Post rates</button>
    <div data-rates aria-live="polite"></div></div>`;
  const scheduleFields = (p = {}) => {
    const first = !data.packages.length || p.id === data.packages[0]?.id;
    const dispatch = p.dispatchDate || (first ? today() : '');
    return `<div class="wlp-split-schedule"><label>Dispatch estimate<input type="date" data-schedule="dispatchDate" value="${esc(dispatch)}"></label><label>Delivery estimate<input type="date" readonly data-schedule="deliveryDate" value="${esc(p.deliveryDate || '')}"></label></div>`;
  };
  const updateSelection = () => {
    const rows = [...dialog.querySelectorAll('[data-selection-row]')];
    const selected = [], later = [];
    rows.forEach(row => {
      const input = row.querySelector('[data-item]'), check = row.querySelector('[data-item-check]');
      const qty = check.checked ? Number(input.value) : 0;
      const name = data.items[input.dataset.item].name;
      if (qty > 0) selected.push(`${esc(name)} × ${qty}`);
      if (Number(input.max) > qty) later.push(`${esc(name)} × ${Number(input.max) - qty}`);
    });
    dialog.querySelector('[data-selection-summary]').innerHTML = `<strong>In this package</strong><ul>${selected.map(s=>`<li>${s}</li>`).join('') || '<li>Check the items you want to include.</li>'}</ul><strong>Left for later</strong><ul>${later.map(s=>`<li>${s}</li>`).join('') || '<li>Nothing left over.</li>'}</ul>`;
  };
  const request = async (operation, values = {}) => {
    const form = new FormData();
    Object.entries({action:'wlp_split', nonce:wlpAdmin.nonce, orderId:data.orderId, revision:data.revision, operation, ...values}).forEach(([k,v])=>form.append(k,v));
    let response;
    try { response = await fetch(wlpAdmin.ajaxUrl, {method:'POST',credentials:'same-origin',body:form}); }
    catch (err) { throw new Error('Connection interrupted. Check your connection, then refresh package status.'); }
    if(response.status===403)throw new Error('Your session expired or access was denied. Reload Logistics and reopen this order.');
    let result;
    try { result = await response.json(); }
    catch (err) { throw new Error('The server response was interrupted. Refresh package status before trying again.'); }
    if (!result?.success) {
      const err = new Error(response.status===403 || result===-1 ? 'Your session expired. Reload Logistics and reopen this order.' : result?.data?.message || 'The package action failed. Refresh package status before retrying.');
      err.shipment=result?.data?.shipment;throw err;
    }
    return result.data;
  };
  const render = () => {
    const s = data.summary;
    const current = data.packages.find(p=>p.id===editing);
    const status = s.allDelivered ? 'All packages delivered' : s.allShipped ? 'All packages shipped' : s.shipped ? 'Partially shipped' : 'Prepare packages';
    dialog.innerHTML = `<header><div><h2 id="wlp-split-title">Order #${esc(data.orderId)} · ${data.packages.length === 1 ? 'Single package' : 'Shipments'}</h2><div class="wlp-split-recipient" aria-label="Shipping recipient"><span>SHIP TO</span><strong>${esc(data.shipTo?.name || 'Shipping recipient missing')}</strong><address>${(data.shipTo?.lines || []).map(esc).join('<br>')}</address>${data.shipTo?.missing?.length ? `<p class="wlp-split-error">Missing shipping details: ${data.shipTo.missing.map(esc).join(', ')}. Update the order before buying a label.</p>` : ''}</div><p>${esc(status)} · ${s.shipped} / ${s.total} items shipped · ${s.remaining} remaining</p></div><button type="button" data-split-close aria-label="Close shipments">×</button></header>
      <p class="wlp-split-error" role="alert" data-split-error></p>
      <button type="button" class="button" data-split-refresh>Refresh package status</button>
      <button type="button" class="button" data-split-sync>Check Canada Post delivery</button>
      <p>${data.packages.length ? 'Your saved packages are below. Review the customer update before sharing it.' : 'Send the complete order in one package, or select items below to split it.'} Saving does not buy a label, send an email or mark anything shipped.</p>
      ${!data.packages.length && s.valid ? '<button type="button" class="button button-primary" data-single-package>Send everything in one package</button>' : ''}
      ${data.packages.length && data.emailPreview ? '<button type="button" class="button" data-split-preview>View customer email preview</button>' : ''}
      ${!s.valid ? '<p class="wlp-split-error">Order quantities changed. Review package allocations before fulfillment.</p>' : ''}
      <div class="wlp-split-packages">${data.packages.map((p,i)=>{
        const labelled = !!p.label?.tracking_number;
        return `<section class="wlp-split-package" data-pid="${esc(p.id)}"><h3>Package ${i+1} <small>${p.deliveredAt ? 'Delivered' : p.shippedAt ? 'Shipped' : labelled ? 'Tracking ready' : p.purchaseAttempt ? 'Purchase needs review' : 'Planned'}</small></h3>
          <ul>${Object.entries(p.items).map(([k,q])=>`<li>${esc(data.items[k]?.name || 'Order item changed')} <strong>× ${q}</strong></li>`).join('')}</ul>
          ${p.note ? `<p class="wlp-split-note">${esc(p.note)}</p>` : ''}
          ${!labelled && !p.purchaseAttempt && !p.shippedAt && !p.deliveredAt ? `<p><button type="button" class="button button-primary" data-split-divide="${esc(p.id)}">Split this package</button></p>` : ''}
          ${p.dispatchDate || p.deliveryDate ? `<p class="wlp-split-saved-dates">Saved dispatch: ${esc(p.dispatchDate || 'Not set')} · Delivery estimate: ${esc(p.deliveryDate || 'Not available')}</p>` : ''}
          ${labelled ? `<p>${esc(p.label.service_name || 'Carrier')}: <strong>${esc(p.label.tracking_number)}</strong></p>` : ''}
          <div class="wlp-split-actions"><a class="button" href="${esc(p.packingUrl)}" target="_blank" rel="noopener">Packing list</a>
          ${data.invoiceUrl ? `<a class="button" href="${esc(data.invoiceUrl)}" target="_blank" rel="noopener" title="Full order invoice and guide">Invoice + guide</a>` : ''}
          ${p.label?.shipment_id ? `<a class="button" href="${esc(p.printUrl)}" target="_blank" rel="noopener">Print label</a>` : ''}
          ${!labelled && !p.purchaseAttempt && !p.shippedAt && !p.deliveredAt ? `<button type="button" class="button" data-split-edit="${esc(p.id)}">Edit items</button><button type="button" class="button" data-split-remove>Remove draft</button>` : ''}
          ${labelled && !p.shippedAt && !p.deliveredAt ? '<button type="button" class="button button-primary" data-split-shipped>Mark package shipped</button>' : ''}
          ${p.shippedAt && !p.deliveredAt ? '<button type="button" class="button" data-split-delivered>Mark package delivered</button>' : ''}</div>
          ${!labelled ? `<details><summary>Save existing tracking</summary><p>For a label bought elsewhere, or recovered from Canada Post after a purchase error.</p><label>Carrier <input data-carrier value="Canada Post"></label><label>Tracking number <input data-tracking></label><button type="button" class="button" data-split-tracking>Save tracking</button></details>` : ''}
          ${!labelled && !p.purchaseAttempt && !p.shippedAt && !p.deliveredAt ? labelFields(p) : ''}
          ${p.purchaseAttempt && !labelled ? `<p class="wlp-split-error">Label purchase needs checking. The previous request may have succeeded.</p><a class="button" href="${esc(data.manifestsUrl)}" target="_blank" rel="noopener">Review Canada Post request</a>` : ''}
          ${labelled && !p.shippedAt && !p.deliveredAt ? `<a class="button" href="${esc(data.manifestsUrl)}" target="_blank" rel="noopener">Shipping manifests</a>` : ''}
        </section>`;
      }).join('')}</div>
      <section class="wlp-split-editor"><h3>${current ? 'Edit package contents' : data.packages.length ? 'Add another package' : '1. Check what goes in the first package'}</h3>
      <p>Checking a product includes its available quantity. Lower the quantity if only part is going.</p>
      <table><thead><tr><th>Include product</th><th>Available</th><th>Quantity</th></tr></thead><tbody>${Object.entries(data.items).map(([key,item])=>{
        const available = splitting ? (current?.items[key] || 0) : item.quantity - (s.assigned[key] || 0) + (current?.items[key] || 0);
        return `<tr data-selection-row><td><label><input type="checkbox" data-item-check="${esc(key)}" ${current?.items[key] ? 'checked' : ''} ${available ? '' : 'disabled'}> ${esc(item.name)}</label></td><td>${available}</td><td><input aria-label="Quantity of ${esc(item.name)}" type="number" min="1" max="${available}" step="1" value="${current?.items[key] || available}" data-item="${esc(key)}" ${current?.items[key] ? '' : 'disabled'}></td></tr>`;
      }).join('')}</tbody></table><div class="wlp-split-selection" data-selection-summary aria-live="polite"></div>
      ${!data.packages.length || splitting ? '<div class="wlp-split-schedule"><label style="grid-column:1 / -1">Next package estimated send date (optional)<input type="date" data-schedule="later_dispatchDate"></label></div>' : ''}
      <details><summary>Optional dates and packing note</summary><p>Dates are customer-facing estimates, not guarantees. Packing notes stay internal.</p><strong>This package</strong>${scheduleFields(current)}<label>Internal packing note<textarea data-split-note placeholder="Case labels, cap colours, complimentary items and preparation notes">${esc(current?.note || '')}</textarea></label></details>
      <div class="wlp-split-actions"><button type="button" class="button" data-split-fill>Select all available</button><button type="button" class="button button-primary" data-split-save>${current ? 'Save changes' : data.packages.length ? 'Save package' : 'Save split plan'}</button>${current ? '<button type="button" class="button" data-split-cancel>Cancel edit</button>' : ''}</div></section>
      ${data.packages.length && data.emailPreview ? `<section class="wlp-split-email"><h3>Customer email preview</h3><p>Review this saved update, then send it directly below. Label purchases refresh tracking and send a label-ready note when notifications are enabled; saving a plan alone does not email the customer.</p><p><strong>To:</strong> ${esc(data.emailPreview.recipient || 'Missing customer email')}<br><strong>From:</strong> ${esc(data.emailPreview.from)}</p><label>Subject<input readonly value="${esc(data.emailPreview.subject)}"></label><label>Message<textarea readonly rows="18" data-split-email>${esc(data.emailPreview.body)}</textarea></label><button type="button" class="button button-primary" data-split-send ${data.emailPreview.sendState || !data.emailPreview.recipient ? 'disabled' : ''}>${data.emailPreview.sendState === 'sent' ? 'Update sent' : 'Send shipment update'}</button> <button type="button" class="button" data-split-copy>Copy customer update</button><p role="status">${data.emailPreview.sendState === 'sent' ? `Accepted by mailer ${esc(data.emailPreview.sentAt)}. Inbox delivery is not yet verified.` : data.emailPreview.sendState ? 'Previous send needs review. Check the mail log before retrying.' : ''}</p><span role="status" data-copy-status></span></section>` : ''}
      <p>Shipped status means actual carrier handoff. Confirming shipment automatically emails tracking and the account link. Record total shipping expense in the order profit panel.</p>`;
    if(data.shipmentNotification) { const notice=document.createElement('p'); notice.setAttribute('role','status'); notice.textContent=data.shipmentNotification.message; if(data.shipmentNotification.state==='review')notice.className='wlp-split-error'; dialog.querySelector('header').after(notice); }
    dialog.setAttribute('aria-labelledby','wlp-split-title');
    if(data.joinedOrders?.length > 1) dialog.querySelector('#wlp-split-title').textContent = `Orders #${data.joinedOrders.join(' + #')} · Joined fulfillment`;
    if(splitting) {
      dialog.querySelector('.wlp-split-editor h3').textContent='Choose what goes now';
      dialog.querySelector('.wlp-split-editor > p').textContent='Keep the items going in this package checked. Unchecked items and remaining quantities automatically become a separate package. Other packages stay unchanged.';
      dialog.querySelector('[data-split-save]').textContent='Save split plan';
    }
    updateSelection();
    if (!current && Object.entries(data.items).every(([key,item]) => (s.assigned[key] || 0) >= item.quantity)) {
      dialog.querySelector('.wlp-split-editor').hidden = true;
    }
  };
  const showError = (err, section) => { const box = section?.querySelector('[data-label-error]') || dialog.querySelector('[data-split-error]'); if (box) { box.textContent = err.message; box.scrollIntoView({block:'nearest'}); } };
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-wlp-join-order]');if(!button||busy)return;
    if(!window.confirm(`Join orders #${button.dataset.orderId} and #${button.dataset.otherOrderId} into ONE package containing all items?\n\nThe server will recheck customer, address and existing labels. Original totals and payments stay unchanged. No label is purchased and no email is sent.`))return;
    previousFocus=button;busy=true;data={orderId:button.dataset.orderId,revision:0};
    dialog.innerHTML='<p role="status">Joining shipment…</p>';dialog.showModal();
    try{data=await request('join',{otherOrderId:button.dataset.otherOrderId});editing='';splitting=false;changed=true;render();}
    catch(err){dialog.innerHTML=`<p role="alert">${esc(err.message)}</p><button type="button" data-split-close>Close</button>`;}
    finally{busy=false;}
  });
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-wlp-split-open]');
    if (!button || busy) return;
    previousFocus = button; busy = true;
    data = {orderId:button.dataset.orderId,revision:0};
    dialog.innerHTML = '<p role="status">Loading packages…</p>'; dialog.showModal();
    try {
      data = await request('read'); editing = ''; splitting = false;
      if(button.dataset.splitDirect) {
        const target=data.packages.find(p=>p.id===button.dataset.splitDirect);
        if(!target || Object.keys(target.label || {}).length || target.purchaseAttempt || target.shippedAt || target.deliveredAt) throw new Error('This package changed or already has a label. Reopen the shipment to review it.');
        editing=target.id;splitting=true;
      }
      render();
      if(splitting) dialog.querySelector('.wlp-split-editor').scrollIntoView({block:'start'});
      if(button.dataset.packageFocus) [...dialog.querySelectorAll('[data-pid]')].find(el=>el.dataset.pid===button.dataset.packageFocus)?.scrollIntoView({block:'start'});
    }
    catch(err) { dialog.innerHTML = `<p role="alert">${esc(err.message)}</p><button type="button" data-split-close>Close</button>`; }
    finally { busy = false; }
  });
  dialog.addEventListener('close', ()=>{ if(changed) window.location.reload(); else previousFocus?.focus(); });
  dialog.addEventListener('cancel', event=>{if(busy)event.preventDefault();});
  dialog.addEventListener('change',event=>{
    if(event.target.matches('[data-split-box]')) {
      const section=event.target.closest('[data-pid]'), box=boxes[event.target.dataset.splitBox];
      section.querySelectorAll('[data-measure]').forEach(el=>{el.value=box[el.dataset.measure];});clearRates(section);
    }
    if(event.target.matches('[data-measure], [data-signature], [data-pickup], [data-dispatch]'))clearRates(event.target.closest('[data-pid]'));
  });
  dialog.addEventListener('input', event=>{
    if(event.target.matches('[data-schedule="dispatchDate"]'))dialog.querySelector('[data-schedule="deliveryDate"]').value='';
    if(event.target.matches('[data-item-check]')) {
      const qty = event.target.closest('[data-selection-row]').querySelector('[data-item]');
      qty.disabled = !event.target.checked;
      if(event.target.checked && Number(qty.value) < 1) qty.value = qty.max;
      updateSelection();
    }
    if(event.target.matches('[data-item]')) updateSelection();
    if(event.target.matches('[data-measure], [data-signature], [data-pickup], [data-dispatch]'))clearRates(event.target.closest('[data-pid]'));
  });
  dialog.addEventListener('click', async event=>{
    const btn = event.target.closest('button'); if(!btn || busy) return;
    if(btn.hasAttribute('data-split-refresh') || btn.hasAttribute('data-split-sync')) {
      if(editing && !window.confirm('Discard unsaved item edits and reload saved packages?'))return;
      busy=true;btn.disabled=true;
      try {const sync=btn.hasAttribute('data-split-sync');data=await request(sync?'sync_delivery':'read');if(sync)changed=true;editing='';splitting=false;render();} catch(err){showError(err);} finally {busy=false;btn.disabled=false;}
      return;
    }
    if(btn.hasAttribute('data-split-close')) { dialog.close(); return; }
    if(btn.hasAttribute('data-split-divide') || btn.hasAttribute('data-split-edit')) { splitting=btn.hasAttribute('data-split-divide');editing=btn.dataset.splitDivide || btn.dataset.splitEdit; render(); dialog.querySelector('.wlp-split-editor').scrollIntoView(); return; }
    if(btn.hasAttribute('data-split-cancel')) { editing='';splitting=false;render();return; }
    if(btn.hasAttribute('data-split-preview')) { dialog.querySelector('.wlp-split-email').scrollIntoView({block:'start'});return; }
    if(btn.hasAttribute('data-split-send')) {
      if(editing) { showError(new Error('Save or cancel item edits before sending the update.'));return; }
      if([...dialog.querySelectorAll('[data-pid] [data-schedule]')].some(el=>el.value !== (data.packages.find(p=>p.id===el.closest('[data-pid]').dataset.pid)?.[el.dataset.schedule] || ''))) { showError(new Error('Save your delivery estimates before sending the update.'));return; }
      if(!window.confirm(`Send this displayed shipment update to ${data.emailPreview.recipient}?\nFrom: ${data.emailPreview.from}\nSubject: ${data.emailPreview.subject}\n\nThis sends a customer email. It does not mark any package shipped.`))return;
      busy=true;btn.disabled=true;
      try { data=await request('send_update',{previewToken:data.emailPreview.token});render();dialog.querySelector('.wlp-split-email').scrollIntoView({block:'start'}); }
      catch(err) { showError(err); }
      finally { busy=false; }
      return;
    }
    if(btn.hasAttribute('data-split-copy')) { try { await navigator.clipboard.writeText(`${data.emailPreview.subject}\n\n${data.emailPreview.body}`);dialog.querySelector('[data-copy-status]').textContent=' Copied. Not sent.'; } catch(err) { dialog.querySelector('[data-split-email]').select();dialog.querySelector('[data-copy-status]').textContent=' Select and copy the message above.'; } return; }
    if(btn.hasAttribute('data-split-fill')) { dialog.querySelectorAll('[data-item]').forEach(el=>{el.value=el.max;el.disabled=Number(el.max)===0;el.closest('[data-selection-row]').querySelector('[data-item-check]').checked=Number(el.max)>0;});updateSelection();return; }
    const section = btn.closest('[data-pid]');
    const values = {packageId:section?.dataset.pid || editing};
    let op='', quoteKey='';
    if(btn.hasAttribute('data-single-package')) {
      if(data.packages.length || !data.summary.valid) { showError(new Error('Shipment changed. Refresh package status before creating a package.'));return; }
      values.items=JSON.stringify(Object.fromEntries(Object.entries(data.items).map(([key,item])=>[key,item.quantity])));
      values.note=''; values.dispatchDate=''; values.splitRemainder='yes'; op='save';
    }
    if(btn.hasAttribute('data-split-save')){
      const inputs=[...dialog.querySelectorAll('[data-item]')].filter(el=>!el.disabled);
      if(inputs.some(el=>!el.reportValidity()))return;
      values.items=JSON.stringify(Object.fromEntries(inputs.map(el=>[el.dataset.item,Number(el.value)])));
      values.note=dialog.querySelector('[data-split-note]').value; op='save';
      if(!data.packages.length || splitting) values.splitRemainder='yes';
      dialog.querySelector('.wlp-split-editor').querySelectorAll('[data-schedule]').forEach(el=>values[el.dataset.schedule]=el.value);
    }
    if(btn.hasAttribute('data-split-schedule')){section.querySelectorAll('[data-schedule]').forEach(el=>values[el.dataset.schedule]=el.value);op='schedule';}
    if(btn.hasAttribute('data-split-tracking')){ values.tracking=section.querySelector('[data-tracking]').value;values.carrier=section.querySelector('[data-carrier]').value;op='tracking'; }
    if(btn.hasAttribute('data-split-remove')){if(!window.confirm('Remove this unlabelled draft package and return its quantities to available?'))return;op='remove';}
    if(btn.hasAttribute('data-split-shipped')){ if(!window.confirm('Confirm every listed item is packed and this package has been handed to the carrier? This automatically emails the customer a shipment update with tracking and their account link.'))return;op='shipped'; }
    if(btn.hasAttribute('data-split-delivered')){ if(!window.confirm('Confirm this package was delivered? No email is sent.'))return;op='delivered'; }
    if(btn.hasAttribute('data-split-rates') || btn.hasAttribute('data-split-buy')){
      const measures=[...section.querySelectorAll('[data-measure]')];if(measures.some(el=>!el.reportValidity()))return;
      if(!section.querySelector('[data-dispatch]').reportValidity())return;
      Object.assign(values,parcelValues(section));op='rates';quoteKey=parcelKey(section);
      if(btn.hasAttribute('data-split-buy')){
        values.service=btn.dataset.service;values.quotedCost=btn.dataset.cost;values.quotedDelivery=btn.dataset.delivery;
        if(section.dataset.quote!==quoteKey){clearRates(section);showError(new Error('Package details changed. Get updated rates.'),section);return;}
        if(!window.confirm(`Buy this package's ${btn.dataset.name} label for C$${btn.dataset.cost}?\nPlanned dispatch: ${values.dispatchDate}\nCarrier delivery estimate: ${values.quotedDelivery || 'Not provided'}\n\nThis saves these estimates and charges the shipping account. When label notifications are enabled, the customer receives tracking and the saved delivery estimate. This does not mark the parcel shipped.`))return;op='buy';
      }
    }
    if(!op)return;
    busy=true;btn.disabled=true;const originalText=btn.textContent;
    dialog.querySelector('[data-split-error]').textContent='';
    if(section?.querySelector('[data-label-error]'))section.querySelector('[data-label-error]').textContent='';
    if(op==='rates'){clearRates(section);btn.textContent='Checking Canada Post...';section.querySelector('[data-rates]').textContent='Getting available services and prices...';}
    if(op==='buy')btn.textContent='Creating label...';
    // Lock this parcel's inputs while its quote or purchase is in flight.
    const lockedInputs=['rates','buy'].includes(op)?[...section.querySelectorAll('[data-label-panel] input')]:[];
    lockedInputs.forEach(el=>el.disabled=true);
    try {
      const result=await request(op,values);
      if(op==='rates'){
        const unchanged=quoteKey===parcelKey(section);
        section.dataset.quote=unchanged?quoteKey:'';
        section.querySelector('[data-rates]').innerHTML=!unchanged ? '<p>Package details changed. Get updated rates.</p>' : result.rates.length ? `<p class="wlp-split-connected">Canada Post connected</p>${result.rates.map(r=>`<div class="wlp-split-rate"><div><strong>${esc(r.service_name || r.service_code)}</strong><span>Delivery estimate: ${esc(r.expected_delivery_date || 'Not provided')}</span></div><strong>C$${esc(r.due)}</strong><button type="button" class="button button-primary" data-split-buy data-name="${esc(r.service_name || r.service_code)}" data-service="${esc(r.service_code)}" data-cost="${esc(r.due)}" data-delivery="${esc(r.expected_delivery_date || '')}">Buy label</button></div>`).join('')}`:'<p>No services returned. Check the dispatch date, address and package measurements, then try again.</p>';
      } else {
        data=result;editing='';splitting=false;changed=true;render();dialog.scrollTop=0;
        if(op==='buy')document.dispatchEvent(new CustomEvent('wlp:label-purchased'));
      }
    } catch(err){
      if(op==='rates')clearRates(section);
      if(op==='buy'){
        // Read persisted state after every failure, including lost/non-JSON responses.
        // Never repeat the purchase request automatically.
        try {
          data=err.shipment || await request('read');changed=true;render();
          const restored=[...dialog.querySelectorAll('[data-pid]')].find(el=>el.dataset.pid===values.packageId);
          if(restored?.querySelector('[data-label-panel]')){
            restored.querySelector('[data-dispatch]').value=values.dispatchDate;
            restored.querySelectorAll('[data-measure]').forEach(el=>el.value=values[el.dataset.measure]);
            restored.querySelectorAll('[data-split-box]').forEach(el=>{
              el.checked=matchesBox(values,boxes[el.dataset.splitBox]);
            });
            restored.querySelector('[data-signature]').checked=values.signature==='yes';
            restored.querySelector('[data-pickup]').checked=values.pickup==='yes';
          }
          if(data.packages.find(p=>p.id===values.packageId)?.label?.tracking_number && restored){
            const notice=document.createElement('p');notice.setAttribute('role','status');notice.className='wlp-split-connected';
            notice.textContent='Label saved. Use Print label for this package. No second purchase was made.';restored.prepend(notice);notice.scrollIntoView({block:'nearest'});
            document.dispatchEvent(new CustomEvent('wlp:label-purchased'));
          } else showError(err,restored);
        } catch(readError){
          clearRates(section);
          section.querySelector('[data-split-rates]').disabled=true;
          showError(new Error('Label result could not be confirmed. Use Refresh package status or review Shipping manifests before another purchase.'),section);
        }
      } else showError(err,op==='rates'?section:null);
    }
    finally{ busy=false;btn.disabled=false;btn.textContent=originalText;lockedInputs.forEach(el=>el.disabled=false); }
  });
}());
