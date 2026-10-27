(function () {
  'use strict';
  if (typeof wlpAdmin === 'undefined' || !wlpAdmin.manifestsUrl) return;
  const dialog = document.createElement('dialog');
  dialog.className = 'wlp-manifest-reminder';
  dialog.setAttribute('aria-labelledby', 'wlp-manifest-reminder-title');
  dialog.setAttribute('aria-describedby', 'wlp-manifest-reminder-status');
  dialog.innerHTML = `<h2 id="wlp-manifest-reminder-title">Don't forget the manifest</h2>
    <p id="wlp-manifest-reminder-status" role="status"></p>
    <p data-manifest-loading role="status" hidden></p>
    <form data-manifest-form>
      <label for="wlp-manifest-location">Pickup / drop-off address</label>
      <select id="wlp-manifest-location" required><option value="">Choose an address</option></select>
      <details data-manifest-parcels><summary data-manifest-count>Checking eligible parcels…</summary><div data-manifest-list></div></details>
      <label class="wlp-manifest-ready"><input type="checkbox" data-manifest-ready> These selected parcels are packed, labelled and ready for handoff today.</label>
      <p class="description">Quick submit sends your selection in the background. Wait for Canada Post confirmation before handoff. Pickup booking is separate.</p>
      <button class="button button-primary" type="submit" data-manifest-submit disabled>Quick submit</button>
    </form>
    <p data-manifest-job aria-live="polite" hidden></p>
    <div class="wlp-manifest-reminder-actions"><a class="button" data-manifest-review target="_blank" rel="noopener">Review manifests</a><button class="button" type="button" data-manifest-dismiss>Keep packing</button></div>`;
  document.body.appendChild(dialog);
  const status = dialog.querySelector('[role="status"]');
  const loading = dialog.querySelector('[data-manifest-loading]');
  let batchRemaining = 0;
  const form = dialog.querySelector('[data-manifest-form]');
  const location = dialog.querySelector('#wlp-manifest-location');
  const addressPreference = 'wlp-manifest-last-handoff';
  const savedAddress = () => { try { return localStorage.getItem(addressPreference); } catch { return null; } };
  const rememberAddress = () => { try { localStorage.setItem(addressPreference, location.value); } catch { /* Preferences are optional when browser storage is unavailable. */ } };
  const list = dialog.querySelector('[data-manifest-list]');
  const count = dialog.querySelector('[data-manifest-count]');
  const ready = dialog.querySelector('[data-manifest-ready]');
  const submit = dialog.querySelector('[data-manifest-submit]');
  const jobText = dialog.querySelector('[data-manifest-job]');
  const review = dialog.querySelector('[data-manifest-review]');
  review.href = wlpAdmin.manifestsUrl;
  let generation = 0, dismissed = 0, running = false, previousFocus = null;
  let snapshot = null, statusValid = false, submitting = false, requestId = null, pollTimer = null, pollCount = 0, submissionWarning = null;
  const activeJob = job => job && ['queued','processing','awaiting_confirmation'].includes(job.state);
  const picks = () => [...list.querySelectorAll('input:checked')].map(input => input.value);
  const update = () => {
    const total = picks().length;
    count.textContent = `${total} ${total === 1 ? 'parcel' : 'parcels'} selected — review selection`;
    submit.disabled = submitting || batchRemaining > 0 || !statusValid || !!submissionWarning || !snapshot?.queue_available || activeJob(snapshot?.job) || !total || !location.value || !ready.checked;
    location.disabled = ready.disabled = submitting || activeJob(snapshot?.job);
    list.querySelectorAll('input').forEach(input => { input.disabled = submitting || activeJob(snapshot?.job); });
    submit.textContent = submitting ? 'Queuing…' : activeJob(snapshot?.job) ? 'Processing in background…' : 'Quick submit';
  };
  const close = () => { dismissed = generation; dialog.close(); };
  dialog.querySelector('[data-manifest-dismiss]').addEventListener('click', close);
  review.addEventListener('click', close);
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  dialog.addEventListener('close', () => { if (previousFocus?.isConnected) previousFocus.focus(); });
  const show = () => {
    if (dismissed >= generation || dialog.open) return;
    previousFocus = document.activeElement;
    dialog.showModal();
    (submit.disabled ? review : submit).focus();
  };
  const countsText = data => {
    const parts = [];
    if (data.ready) parts.push(`${data.ready} ${data.ready === 1 ? 'parcel needs' : 'parcels need'} a manifest.`);
    if (data.pending) parts.push(`${data.pending} awaiting Canada Post confirmation.`);
    if (data.review) parts.push(`${data.review} carrier ${data.review === 1 ? 'request needs' : 'requests need'} review.`);
    return parts.join(' ');
  };
  const render = data => {
    const oldTokens = snapshot?.parcels.map(p => p.token).join('|');
    const newTokens = data.parcels.map(p => p.token).join('|');
    const oldLocation = location.value;
    if (!snapshot) {
      data.locations.forEach(point => {
        const option = document.createElement('option'); option.value = point.id; option.textContent = point.label; location.appendChild(option);
      });
    }
    if (oldTokens !== newTokens || snapshot?.day !== data.day) {
      list.replaceChildren();
      data.parcels.forEach(parcel => {
        const label = document.createElement('label');
        const input = document.createElement('input'); input.type = 'checkbox'; input.value = parcel.token; input.checked = true;
        label.append(input, document.createTextNode(' ' + parcel.label)); list.appendChild(label);
      });
      ready.checked = false; requestId = null;
    }
    snapshot = data; statusValid = true;
    if (!batchRemaining) loading.hidden = true;
    if (data.job?.id === requestId) submissionWarning = null;
    const preferred = oldLocation || savedAddress() || 'hamilton-pickup';
    location.value = data.locations.some(point => point.id === preferred) ? preferred : (data.locations.some(point => point.id === 'hamilton-pickup') ? 'hamilton-pickup' : '');
    status.textContent = countsText(data) || 'No saved parcels are waiting for a manifest.';
    form.hidden = false;
    jobText.hidden = !data.job && data.queue_available && !submissionWarning;
    jobText.textContent = submissionWarning || data.job?.message || (!data.queue_available ? 'Background processing is unavailable. Use Review manifests.' : '');
    if (data.job?.id === requestId) { ready.checked = false; }
    update();
    clearTimeout(pollTimer);
    if (activeJob(data.job) && pollCount++ < 60) pollTimer = setTimeout(refresh, 5000);
  };
  const post = async (action, fields = {}) => {
    const body = new FormData(); body.append('action',action); body.append('nonce',wlpAdmin.nonce);
    for (const [key,value] of Object.entries(fields)) {
      if (Array.isArray(value)) value.forEach(item => body.append(key+'[]',item)); else body.append(key,value);
    }
    const response = await fetch(wlpAdmin.ajaxUrl,{method:'POST',credentials:'same-origin',body});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.data?.message || 'Manifest request could not be checked.');
    return result.data;
  };
  const refresh = async () => {
    if (running || submitting) return;
    running = true;
    let checked = 0;
    try {
      do {
        checked = generation;
        try {
          const data = await post('wlp_manifest_status');
          if (!data || !['ready','pending','review'].every(key => Number.isInteger(data[key]) && data[key]>=0) || !Array.isArray(data.parcels) || !Array.isArray(data.locations)) throw new Error('Status unavailable');
          if (checked !== generation) continue;
          render(data); show();
        } catch (error) {
          if (checked !== generation) continue;
          statusValid = false; submit.disabled = true; ready.checked = false;
          status.textContent = 'Manifest status could not be checked. Review Shipping manifests before handoff.';
          show();
        }
      } while (checked !== generation);
    } finally { running = false; }
  };
  form.addEventListener('change',event => {
    if (event.target === location) rememberAddress();
    if (event.target !== ready) { ready.checked = false; requestId = null; }
    update();
  });
  form.addEventListener('submit',async event => {
    event.preventDefault();
    if (submit.disabled || !snapshot) return;
    submitting = true; clearTimeout(pollTimer); update();
    // Preserve this ID on an ambiguous response. Never auto-repeat a submission.
    requestId ||= crypto.randomUUID();
    try {
      const data = await post('wlp_manifest_quick_submit',{request_id:requestId,parcels:picks(),handoff_id:location.value,pickup_day:snapshot.day,ready:'yes'});
      pollCount = 0; render(data);
    } catch (error) {
      ready.checked = false;
      jobText.hidden = false;
      submissionWarning = 'Submission could not be confirmed. Check manifests before retrying; some parcels may already be submitted.';
      jobText.textContent = submissionWarning;
      status.textContent = error.message;
    } finally {
      submitting = false; update();
      // Saved job state is authoritative even if the queue response was lost.
      refresh();
    }
  });
  const remind = () => {
    ++generation; pollCount = 0;
    statusValid = false; ready.checked = false; update();
    loading.hidden = false;
    loading.textContent = batchRemaining ? `${batchRemaining} more ${batchRemaining === 1 ? 'label is' : 'labels are'} being created. Manifest counts update as parcels finish.` : 'Checking newly saved parcels…';
    if (!snapshot) status.textContent = 'Checking manifest status…';
    show(); refresh();
  };
  document.addEventListener('wlp:label-purchased',remind);
  document.addEventListener('wlp:label-batch-progress', event => {
    batchRemaining = Math.max(0, Number(event.detail?.remaining) || 0);
    loading.hidden = !batchRemaining;
    loading.textContent = `${batchRemaining} more ${batchRemaining === 1 ? 'label is' : 'labels are'} being created. Manifest counts update as parcels finish.`;
    update();
  });
  document.addEventListener('click',event => { if (event.target.closest('[data-wlp-manifest-reminder]')) remind(); });
  // Prepare the popup before it is opened; this read never submits to the carrier.
  refresh();
}());
