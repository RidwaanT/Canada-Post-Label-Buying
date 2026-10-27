(function () {
  const drawer = document.getElementById('wlp-label-drawer');
  const content = document.getElementById('wlp-drawer-content');

  if (!drawer || !content || typeof wlpAdmin === 'undefined') {
    return;
  }

  const text = wlpAdmin.i18n || {};
  let drawerMode = 'label';
  const packingSaves = new Map();
  const packingFailures = new Map();

  const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const copyTextToClipboard = async (value) => {
    const copyValue = String(value || '').trim();
    if (!copyValue) throw new Error('Nothing to copy.');

    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(copyValue);
      return;
    }

    const fallback = document.createElement('textarea');
    fallback.value = copyValue;
    fallback.setAttribute('readonly', 'readonly');
    fallback.style.position = 'fixed';
    fallback.style.opacity = '0';
    document.body.appendChild(fallback);
    fallback.select();
    const copied = document.execCommand('copy');
    fallback.remove();

    if (!copied) throw new Error('Copy failed.');
  };

  const cssEscape = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }

    return String(value).replace(/["\\]/g, '\\$&');
  };

  const post = async (action, data) => {
    const form = new FormData();
    form.append('action', action);
    form.append('nonce', wlpAdmin.nonce);

    Object.entries(data).forEach(([key, value]) => {
      form.append(key, value);
    });

    const response = await fetch(wlpAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: form,
    });

    const result = await response.json();
    if (result.success && ['wlp_create_label', 'wlp_quick_buy_label'].includes(action)) {
      document.dispatchEvent(new CustomEvent('wlp:label-purchased'));
    }
    return result;
  };

  const sourcingHtml = (orderId, sourcing = {}, bulk = false) => {
    if (sourcing.error) return `<section class="wlp-sourcing"><h3>Fulfillment sources</h3><p>${escapeHtml(sourcing.error)}</p></section>`;
    const lines = Array.isArray(sourcing.lines) ? sourcing.lines : [];
    const messages = Array.isArray(sourcing.messages) ? sourcing.messages.filter(message => message.sentAt) : [];
    return `<section class="wlp-sourcing" data-wlp-sourcing data-order-id="${escapeHtml(orderId)}" data-expected-sources="${escapeHtml(JSON.stringify(Object.fromEntries(lines.map(line => [line.key, Object.fromEntries(['jkl','xp','hamilton'].map(source => [source, Number(line.allocations?.[source] || 0)]))]))))}">
      <h3>${bulk ? `Order #${escapeHtml(orderId)}` : 'Fulfillment sources'}</h3>
      ${!bulk ? `<p>Check JKL, XP, or Hamilton to assign the unassigned vials. Adjust quantities if a product comes from multiple sources. Save + add each order, then copy one combined message per supplier. Saving does not contact suppliers.</p>` : ''}
      <div class="wlp-sourcing-lines">
        ${lines.map((line) => `<div class="wlp-sourcing-line" data-wlp-source-line data-product-name="${escapeHtml(line.name)}" data-product-unit="${escapeHtml(line.unit || 'vials')}" data-key="${escapeHtml(line.key)}" data-quantity="${escapeHtml(line.quantity)}">
          <strong>${escapeHtml(line.name)} — ${escapeHtml(line.quantity)} ${escapeHtml(line.unit || 'vials')}</strong>
          <div class="wlp-sourcing-inputs">${['jkl', 'xp', 'hamilton'].map((source) => `<div class="wlp-source-choice"><label><input type="checkbox" data-wlp-source-pick="${source}" ${Number(line.allocations?.[source] || 0) > 0 ? 'checked' : ''}> ${source === 'hamilton' ? 'Hamilton' : source.toUpperCase()}</label><input aria-label="${escapeHtml(line.name)} quantity from ${source.toUpperCase()}" type="number" min="0" max="${escapeHtml(line.quantity)}" step="1" inputmode="numeric" data-wlp-source-qty="${source}" value="${escapeHtml(String(line.allocations?.[source] ?? 0))}"></div>`).join('')}</div>
          <small data-wlp-source-remaining>${escapeHtml(String(line.remaining))} unassigned${line.invalid ? ' — saved allocation exceeds current quantity; correct it now' : ''}</small>
        </div>`).join('')}
      </div>
      ${!bulk ? `<div class="wlp-sourcing-actions"><button class="button button-primary" type="button" data-wlp-save-sourcing data-add-to-batch>Save + add to supplier order</button><button class="button" type="button" data-wlp-save-sourcing>Save source plan only</button><span data-wlp-sourcing-status>Save the plan, then open the combined supplier order to send and track it.</span></div>
      <div class="wlp-sourcing-actions"><span data-wlp-source-batch-count>${selectedCards().filter(card => card.getAttribute('data-is-wholesale') === 'yes').length} orders selected for supplier request</span><button class="button" type="button" data-wlp-open-source-batch ${selectedCards().some(card => card.getAttribute('data-is-wholesale') === 'yes') ? '' : 'disabled'}>View combined supplier order</button></div>` : '<span data-wlp-sourcing-status></span>'}
      ${!bulk && Array.isArray(sourcing.hamilton) && sourcing.hamilton.length ? `<div class="wlp-sourcing-pick"><strong>Hamilton pick list</strong><pre>${escapeHtml(sourcing.hamilton.join('\n'))}</pre></div>` : ''}
      ${!bulk && messages.length ? '<details><summary>Earlier individual supplier records</summary>' : ''}
      ${(bulk ? [] : messages).map((message) => `<div class="wlp-sourcing-message" data-wlp-sourcing-message data-source="${escapeHtml(message.source)}" data-mode="${escapeHtml(message.mode)}" data-hash="${escapeHtml(message.hash)}">
        <strong>${escapeHtml(message.source.toUpperCase())} — ${message.mode === 'stock' ? 'stock check' : 'order request'}</strong>
        <textarea readonly rows="6" aria-label="${escapeHtml(message.source.toUpperCase())} ${escapeHtml(message.mode)} message">${escapeHtml(message.text)}</textarea>
        <div class="wlp-sourcing-actions">
          <button class="button" type="button" data-wlp-copy-sourcing ${message.mode === 'order' && !message.canOrder ? 'disabled' : ''}>Copy message</button>
          <button class="button" type="button" data-wlp-sourcing-step="sent" ${message.sentAt || (message.mode === 'order' && !message.canOrder) ? 'disabled' : ''}>I sent this</button>
          ${message.mode === 'stock' ? `<button class="button" type="button" data-wlp-sourcing-step="confirmed" ${!message.sentAt || message.confirmedAt ? 'disabled' : ''}>Supplier confirmed</button>` : `<button class="button" type="button" data-wlp-sourcing-step="received" ${!message.sentAt || message.receivedAt ? 'disabled' : ''}>Received + inspected</button>`}
        </div>
        <small>${message.sentAt ? `Sent ${escapeHtml(message.sentAt)}` : 'Not recorded as sent'}${message.confirmedAt ? ` · Confirmed ${escapeHtml(message.confirmedAt)}` : ''}${message.receivedAt ? ` · Received ${escapeHtml(message.receivedAt)}` : ''}${message.mode === 'order' && !message.canOrder ? ' · Record payment before placing a supplier order.' : ''}</small>
      </div>`).join('')}
      ${!bulk && messages.length ? '</details>' : ''}
    </section>`;
  };

  const openDrawer = () => {
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
  };

  const setDrawerTitle = (title) => {
    const target = drawer.querySelector('[data-wlp-drawer-title]');
    if (target) {
      target.textContent = title || 'Logistics';
    }
  };

  const closeDrawer = () => {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
  };

  const unpackedQuickViewSurface = () => {
    const surface = content.querySelector('.wlp-quick-view[data-wlp-order-box]');
    return surface && surface.getAttribute('data-packed') !== 'yes'
      && packingSaves.get(surface.getAttribute('data-order-id')) !== 'pending' ? surface : null;
  };

  const selectedCards = () => Array.from(document.querySelectorAll('[data-wlp-order-select]:checked'))
    .map((input) => input.closest('[data-wlp-order-card]'))
    .filter(Boolean);

  const orderCards = () => Array.from(document.querySelectorAll('[data-wlp-order-card]'));

  const visibleOrderCards = () => orderCards().filter((card) => card.offsetParent !== null);

  const orderCheckboxes = () => Array.from(document.querySelectorAll('[data-wlp-order-select]'));

  const orderSurface = (element) => element
    ? element.closest('[data-wlp-order-card], [data-wlp-order-box]')
    : null;

  const surfaceForOrderId = (orderId) => document.querySelector(`[data-wlp-order-card][data-order-id="${cssEscape(orderId || '')}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId || '')}"]`);

  const deliveryProtectionDefaults = (surface) => ({
    signature: surface && surface.getAttribute('data-wlp-signature-default') !== null
      ? surface.getAttribute('data-wlp-signature-default') === 'yes'
      : wlpAdmin.signatureRequired === 'yes',
    cardForPickup: surface && surface.getAttribute('data-wlp-card-for-pickup-default') !== null
      ? surface.getAttribute('data-wlp-card-for-pickup-default') === 'yes'
      : false,
  });

  const isPackedSurface = (surface) => surface && surface.getAttribute('data-packed') === 'yes';

  const prelabelStorageKey = 'wlpPrelabelDoneKeys';

  const readPrelabelDoneKeys = () => {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(prelabelStorageKey) || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  };

  const writePrelabelDoneKeys = (keys) => {
    try {
      window.localStorage.setItem(prelabelStorageKey, JSON.stringify(Array.from(new Set(keys))));
    } catch (error) {
      // Browser storage can be unavailable in private or restricted sessions.
    }
  };

  const formatPrelabelCount = (type, count) => {
    const safeCount = Math.max(0, Number(count || 0));
    if (type === 'bac') {
      return `${safeCount} BAC water${safeCount === 1 ? '' : 's'}`;
    }
    if (type === 'case') {
      return `${safeCount} vial case${safeCount === 1 ? '' : 's'}`;
    }

    return `${safeCount} peptide vial${safeCount === 1 ? '' : 's'}`;
  };

  const updatePrelabelCounts = () => {
    const totals = { peptide: 0, case: 0, bac: 0 };
    document.querySelectorAll('[data-wlp-prelabel-row]').forEach((row) => {
      if (row.hidden) {
        return;
      }

      const requestedType = row.getAttribute('data-prelabel-type');
      const type = ['peptide', 'case', 'bac'].includes(requestedType) ? requestedType : 'peptide';
      totals[type] += Number(row.getAttribute('data-prelabel-quantity') || 0);
    });

    Object.entries(totals).forEach(([type, count]) => {
      const target = document.querySelector(`[data-wlp-prelabel-count="${type}"]`);
      if (!target) {
        return;
      }

      target.setAttribute('data-count', String(count));
      target.textContent = formatPrelabelCount(type, count);
    });
  };

  const applyPrelabelDoneState = () => {
    const done = new Set(readPrelabelDoneKeys());
    document.querySelectorAll('[data-wlp-prelabel-row]').forEach((row) => {
      const key = row.getAttribute('data-prelabel-key');
      const isDone = Boolean(key && done.has(key));
      row.hidden = isDone;
      row.classList.toggle('is-prelabel-done', isDone);
      row.style.display = isDone ? 'none' : '';
    });
    updatePrelabelCounts();
  };

  const markPrelabelRowDone = (row) => {
    const key = row ? row.getAttribute('data-prelabel-key') : '';
    if (!key) {
      return;
    }

    writePrelabelDoneKeys([...readPrelabelDoneKeys(), key]);
    row.hidden = true;
    row.classList.add('is-prelabel-done');
    row.style.display = 'none';
    updatePrelabelCounts();
  };

  const resetPrelabelRows = () => {
    const activeKeys = Array.from(document.querySelectorAll('[data-wlp-prelabel-row]'))
      .map((row) => row.getAttribute('data-prelabel-key'))
      .filter(Boolean);
    const active = new Set(activeKeys);
    writePrelabelDoneKeys(readPrelabelDoneKeys().filter((key) => !active.has(key)));
    document.querySelectorAll('[data-wlp-prelabel-row]').forEach((row) => {
      row.hidden = false;
      row.classList.remove('is-prelabel-done');
      row.style.display = '';
    });
    updatePrelabelCounts();
  };

  const confirmDialog = (message, options = {}) => new Promise((resolve) => {
    const existing = document.querySelector('[data-wlp-confirm-modal]');
    if (existing) {
      existing.remove();
    }

    const modal = document.createElement('div');
    modal.className = 'wlp-confirm';
    modal.setAttribute('data-wlp-confirm-modal', 'true');
    modal.innerHTML = `
      <div class="wlp-confirm__panel" role="dialog" aria-modal="true" aria-labelledby="wlp-confirm-title">
        <div class="wlp-confirm__icon">!</div>
        <div class="wlp-confirm__body">
          <h2 id="wlp-confirm-title">${escapeHtml(options.title || text.confirmMarkPackedTitle || 'Mark order as packed?')}</h2>
          <div class="wlp-confirm__message">
            ${String(message || '').split(/\n{2,}/).map((line) => `<p>${escapeHtml(line)}</p>`).join('')}
          </div>
          <div class="wlp-confirm__actions">
            <button class="button" type="button" data-wlp-confirm-cancel>${escapeHtml(options.cancelLabel || text.confirmMarkPackedCancel || 'Cancel')}</button>
            <button class="button button-primary" type="button" data-wlp-confirm-ok>${escapeHtml(options.confirmLabel || text.confirmMarkPackedContinue || 'Mark packed')}</button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const ok = modal.querySelector('[data-wlp-confirm-ok]');
    const cancel = modal.querySelector('[data-wlp-confirm-cancel]');
    const close = (answer) => {
      document.removeEventListener('keydown', onKeydown);
      modal.remove();
      resolve(answer);
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        close(false);
      }
    };

    ok.addEventListener('click', () => close(true));
    cancel.addEventListener('click', () => close(false));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        close(false);
      }
    });
    document.addEventListener('keydown', onKeydown);
    ok.focus();
  });

  const reshipAddressDialog = (address = {}) => new Promise((resolve) => {
    const existing = document.querySelector('[data-wlp-reship-address-modal]');
    if (existing) {
      existing.remove();
    }

    const modal = document.createElement('div');
    modal.className = 'wlp-confirm';
    modal.setAttribute('data-wlp-reship-address-modal', 'true');
    modal.innerHTML = `
      <div class="wlp-confirm__panel wlp-reship-address__panel" role="dialog" aria-modal="true" aria-labelledby="wlp-reship-address-title">
        <div class="wlp-confirm__body">
          <h2 id="wlp-reship-address-title">Review shipping address</h2>
          <p>Update the address before the old label and tracking are cleared. The new label will use these details.</p>
          <div class="wlp-reship-address__grid">
            <label><span>First name *</span><input type="text" data-wlp-reship-field="shippingFirstName" value="${escapeHtml(address.firstName || '')}" required></label>
            <label><span>Last name</span><input type="text" data-wlp-reship-field="shippingLastName" value="${escapeHtml(address.lastName || '')}"></label>
            <label class="is-wide"><span>Company</span><input type="text" data-wlp-reship-field="shippingCompany" value="${escapeHtml(address.company || '')}"></label>
            <label class="is-wide"><span>Street address *</span><input type="text" data-wlp-reship-field="shippingAddress1" value="${escapeHtml(address.address1 || '')}" required></label>
            <label class="is-wide"><span>Apartment, suite, unit</span><input type="text" data-wlp-reship-field="shippingAddress2" value="${escapeHtml(address.address2 || '')}" placeholder="Apt 305"></label>
            <label><span>City *</span><input type="text" data-wlp-reship-field="shippingCity" value="${escapeHtml(address.city || '')}" required></label>
            <label><span>Province / state *</span><input type="text" data-wlp-reship-field="shippingState" value="${escapeHtml(address.state || '')}" required></label>
            <label><span>Postal code *</span><input type="text" data-wlp-reship-field="shippingPostcode" value="${escapeHtml(address.postcode || '')}" required></label>
            <label><span>Country code *</span><input type="text" maxlength="2" data-wlp-reship-field="shippingCountry" value="${escapeHtml(address.country || 'CA')}" required></label>
          </div>
          <div class="wlp-confirm__actions">
            <button class="button" type="button" data-wlp-reship-cancel>Cancel</button>
            <button class="button button-primary" type="button" data-wlp-reship-confirm>Save address &amp; prepare reship</button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const confirm = modal.querySelector('[data-wlp-reship-confirm]');
    const cancel = modal.querySelector('[data-wlp-reship-cancel]');
    const close = (answer) => {
      document.removeEventListener('keydown', onKeydown);
      modal.remove();
      resolve(answer);
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        close(null);
      }
    };

    confirm.addEventListener('click', () => {
      const fields = Array.from(modal.querySelectorAll('[data-wlp-reship-field]'));
      const missing = fields.find((field) => field.required && !field.value.trim());
      if (missing) {
        missing.focus();
        missing.reportValidity();
        return;
      }

      close(Object.fromEntries(fields.map((field) => [field.getAttribute('data-wlp-reship-field'), field.value.trim()])));
    });
    cancel.addEventListener('click', () => close(null));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        close(null);
      }
    });
    document.addEventListener('keydown', onKeydown);
    modal.querySelector('[data-wlp-reship-field="shippingFirstName"]').focus();
  });

  const quickBuyOptionsDialog = (count, orderIds = []) => new Promise((resolve) => {
    const existing = document.querySelector('[data-wlp-quick-buy-modal]');
    if (existing) {
      existing.remove();
    }

    const modal = document.createElement('div');
    modal.className = 'wlp-confirm wlp-quick-buy-modal';
    modal.setAttribute('data-wlp-quick-buy-modal', 'true');
    modal.innerHTML = `
      <div class="wlp-confirm__panel wlp-quick-buy-modal__panel" role="dialog" aria-modal="true" aria-labelledby="wlp-quick-buy-title">
        <div class="wlp-confirm__body">
          <h2 id="wlp-quick-buy-title">Quick buy ${escapeHtml(count)} label${count === 1 ? '' : 's'}?</h2>
          <p class="wlp-quick-buy-modal__lead">Choose one package/service for all selected orders, or use the smart suggestions for each order.</p>
          <fieldset class="wlp-quick-buy-modal__group">
            <legend>Box size</legend>
            <label><input type="radio" name="wlpQuickPreset" value="" checked> Use suggested box per order</label>
            <label><input type="radio" name="wlpQuickPreset" value="small-box"> All Small Box</label>
            <label><input type="radio" name="wlpQuickPreset" value="medium-box"> All Medium Box</label>
          </fieldset>
          <fieldset class="wlp-quick-buy-modal__group">
            <legend>Shipping service</legend>
            <label><input type="radio" name="wlpQuickService" value="" checked> Use suggested service per order</label>
            <label><input type="radio" name="wlpQuickService" value="DOM.EP"> All Expedited Parcel</label>
            <label><input type="radio" name="wlpQuickService" value="DOM.XP"> All Xpresspost</label>
          </fieldset>
          <label class="wlp-quick-buy-modal__threshold">
            <span>Xpresspost upgrade max</span>
            <span class="wlp-quick-buy-modal__money">$</span>
            <output data-wlp-xpresspost-upgrade-value>1.00</output>
            <input type="range" min="1" max="10" step="1" value="1" data-wlp-xpresspost-upgrade-limit>
          </label>
          <div class="wlp-quick-buy-impact" data-wlp-quick-buy-impact>
            <strong>Checking upgrade impact...</strong>
            <p>Move the slider to see how many labels switch to Xpresspost and how many delivery days you save.</p>
          </div>
          <p class="wlp-quick-buy-modal__note">Suggested service upgrades to Xpresspost only when it is faster and within this dollar amount. Customers are not charged again; this is only your label-buying decision.</p>
          <div class="wlp-confirm__actions">
            <button class="button" type="button" data-wlp-quick-buy-cancel>Cancel</button>
            <button class="button button-primary" type="button" data-wlp-quick-buy-confirm>Buy labels</button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(modal);
    let previewSummary = [];

    const impactTarget = modal.querySelector('[data-wlp-quick-buy-impact]');
    const limitInput = modal.querySelector('[data-wlp-xpresspost-upgrade-limit]');
    const limitOutput = modal.querySelector('[data-wlp-xpresspost-upgrade-value]');
    const selectedPreset = () => modal.querySelector('input[name="wlpQuickPreset"]:checked').value;
    const currentLimit = () => Math.max(1, Math.min(10, Number(limitInput && limitInput.value ? limitInput.value : 1) || 1));
    const renderImpact = () => {
      const limit = currentLimit();
      if (limitOutput) {
        limitOutput.textContent = Number(limit).toFixed(2);
      }

      if (!impactTarget) {
        return;
      }

      const bucket = previewSummary.find((entry) => Number(entry.threshold) === limit);
      if (!bucket) {
        impactTarget.innerHTML = `
          <strong>Checking upgrade impact...</strong>
          <p>Canada Post rates are loading for the selected orders.</p>
        `;
        return;
      }

      const orders = Array.isArray(bucket.orders) ? bucket.orders : [];
      const orderRows = orders.slice(0, 5).map((order) => `
        <li>
          <span>#${escapeHtml(order.orderNumber || order.orderId)}</span>
          <strong>+$${escapeHtml(Number(order.extraCost || 0).toFixed(2))}</strong>
          <em>${escapeHtml(order.daysSaved || 0)} day${Number(order.daysSaved || 0) === 1 ? '' : 's'} saved</em>
        </li>
      `).join('');
      impactTarget.innerHTML = `
        <strong>${escapeHtml(bucket.upgradedOrders || 0)} of ${escapeHtml(bucket.ordersChecked || count)} orders upgrade to Xpresspost at $${escapeHtml(Number(limit).toFixed(2))} max.</strong>
        <p>${escapeHtml(bucket.daysSaved || 0)} total business day${Number(bucket.daysSaved || 0) === 1 ? '' : 's'} saved, about $${escapeHtml(Number(bucket.extraCost || 0).toFixed(2))} extra label cost.</p>
        ${orderRows ? `<ul>${orderRows}</ul>` : '<p>No upgrades at this amount.</p>'}
        ${bucket.unavailableCount ? `<p>${escapeHtml(bucket.unavailableCount)} order${Number(bucket.unavailableCount) === 1 ? '' : 's'} could not be quoted in preview.</p>` : ''}
      `;
    };
    const loadPreview = async () => {
      if (!orderIds.length) {
        renderImpact();
        return;
      }

      impactTarget.innerHTML = '<strong>Checking upgrade impact...</strong><p>Loading Canada Post rates for selected orders.</p>';
      const result = await post('wlp_quick_buy_preview', {
        orderIds: orderIds.join(','),
        presetId: selectedPreset(),
        thresholds: '1,2,3,4,5,6,7,8,9,10',
      });

      if (!result.success) {
        impactTarget.innerHTML = `<strong>Preview unavailable</strong><p>${escapeHtml(result.data && result.data.message ? result.data.message : 'Could not load upgrade impact.')}</p>`;
        return;
      }

      previewSummary = result.data && Array.isArray(result.data.summary) ? result.data.summary : [];
      renderImpact();
    };

    const close = (answer) => {
      document.removeEventListener('keydown', onKeydown);
      modal.remove();
      resolve(answer);
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        close(null);
      }
    };

    modal.querySelector('[data-wlp-quick-buy-confirm]').addEventListener('click', () => {
      const limit = currentLimit();
      close({
        presetId: selectedPreset(),
        serviceCode: modal.querySelector('input[name="wlpQuickService"]:checked').value,
        xpresspostUpgradeLimit: limit.toFixed(2),
      });
    });
    modal.querySelectorAll('input[name="wlpQuickPreset"]').forEach((input) => {
      input.addEventListener('change', loadPreview);
    });
    if (limitInput) {
      limitInput.addEventListener('input', renderImpact);
    }
    modal.querySelector('[data-wlp-quick-buy-cancel]').addEventListener('click', () => close(null));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        close(null);
      }
    });
    document.addEventListener('keydown', onKeydown);
    loadPreview();
    modal.querySelector('[data-wlp-quick-buy-confirm]').focus();
  });

  const confirmPackedAction = (surface, message) => {
    if (!isPackedSurface(surface)) {
      return true;
    }

    return window.confirm(message);
  };

  const confirmMarkPacked = (surface) => {
    const options = {
      title: text.confirmMarkPackedTitle || 'Mark order as packed?',
      confirmLabel: text.confirmMarkPackedContinue || 'Mark packed',
      cancelLabel: text.confirmMarkPackedCancel || 'Cancel',
    };
    const alcoholPadsLine = surface && surface.getAttribute('data-alcohol-pads-due') === 'yes'
      ? `\n\n${text.alcoholPadsReminder || 'Fulfillment reminder: add complimentary alcohol pads.'}`
      : '';

    if (surface && surface.getAttribute('data-invoice-printed') !== 'yes' && surface.getAttribute('data-has-label') !== 'yes') {
      return confirmDialog(`${text.confirmMarkPackedNoInvoiceNoLabel || 'Packing slip + reconstitution guide has not been marked printed, and this order does not have a shipping label yet.\n\nMark as packed anyway?'}${alcoholPadsLine}`, options);
    }

    if (surface && surface.getAttribute('data-invoice-printed') !== 'yes') {
      return confirmDialog(`${text.confirmMarkPackedNoInvoice || 'Packing slip + reconstitution guide has not been marked printed yet.\n\nMark as packed anyway?'}${alcoholPadsLine}`, options);
    }

    if (surface && surface.getAttribute('data-has-label') !== 'yes') {
      return confirmDialog(`${text.confirmMarkPackedNoLabel || 'This order does not have a shipping label yet.\n\nMark as packed anyway?'}${alcoholPadsLine}`, options);
    }

    return confirmDialog(`${text.confirmMarkPacked || 'Mark this order as packed?\n\nOnly continue if the items were checked, packed, and the packing slip/reconstitution guide was included.'}${alcoholPadsLine}`, options);
  };

  const confirmLeaveUnpackedQuickView = () => {
    const surface = unpackedQuickViewSurface();
    if (!surface) {
      return true;
    }

    return confirmDialog(
      text.confirmLeaveUnpacked || 'Did you forget to mark this order packed?\n\nThis order is still showing as unpacked. Leave this order anyway?',
      {
        title: text.confirmLeaveUnpackedTitle || 'Order not marked packed',
        confirmLabel: text.confirmLeaveUnpackedContinue || 'Leave anyway',
        cancelLabel: text.confirmLeaveUnpackedCancel || 'Go back',
      }
    );
  };

  const updateSelectionCount = () => {
    const target = document.querySelector('[data-wlp-selection-count]');
    const selected = selectedCards();
    const labeled = selected.filter((card) => card.getAttribute('data-has-label') === 'yes');
    if (target) target.textContent = `${selected.length} selected${labeled.length ? `, ${labeled.length} labeled` : ''}`;
    const batch = document.querySelector('button[data-wlp-batch-sourcing]');
    if (batch) {
      const wholesaleSelected = selected.filter((card) => card.getAttribute('data-is-wholesale') === 'yes');
      batch.hidden = wholesaleSelected.length === 0;
      batch.disabled = wholesaleSelected.length === 0;
      batch.textContent = wholesaleSelected.length ? `Batch supplier request (${wholesaleSelected.length})` : 'Batch supplier request';
    }
  };

  const batchSourcingHtml = (orders) => {
    const selected = new Set(orders.map(order => Number(order.orderId)));
    const requests = (source, mode) => {
      const products = new Map(), included = [], tokens = {};
      orders.forEach(order => {
        const sourcing = order.wholesale?.sourcing || {};
        if (mode === 'order' && sourcing.supplierProgress?.legacyReview) return;
        let hasSource = false;
        (sourcing.lines || []).forEach(line => {
          const quantity = Number((mode === 'order' ? (line.pendingAllocations || line.allocations) : line.allocations)?.[source] || 0);
          if (quantity <= 0) return;
          hasSource = true;
          const key = `${line.name}|${line.unit || 'vials'}`;
          const product = products.get(key) || {name:line.name, unit:line.unit || 'vials', quantity:0};
          product.quantity += quantity; products.set(key, product);
        });
        if (hasSource) { included.push(order); tokens[order.orderId] = sourcing.batchTokens?.[source]?.[mode] || ''; }
      });
      if (!included.length) return '';
      const ids = included.map(order => Number(order.orderId)).sort((a,b) => a-b);
      const requested = [...products.values()].map(product => ({...product, required:product.quantity, quantity:product.unit === 'vials' ? Math.ceil(product.quantity / 10) * 10 : product.quantity}));
      const productsText = requested.map(product => `• ${product.name} — ${product.quantity} ${product.unit}`).join('\n');
      const extra = requested.filter(product => product.quantity > product.required);
      const roundingNote = extra.length ? `<p class="description">Whole kits: ${escapeHtml(extra.map(product => `${product.name}: ${product.required} needed → ${product.quantity} requested (${product.quantity - product.required} extra vials, unallocated)`).join('; '))}.</p>` : '';
      const value = mode === 'stock' ? `Hi, can you confirm current availability for:\n\n${productsText}` : `${productsText}${source === 'xp' ? '\n\nPlease use priority shipping.' : ''}`;
      const canOrder = mode === 'stock' || included.every(order => Boolean(order.wholesale?.paid));
      const currentFormat = included.every(order => Number(order.wholesale?.sourcing?.draftVersion) === 5);
      const stockSent = mode === 'stock' && included.every(order => (order.wholesale?.sourcing?.batchEvents || []).some(event => event.mode === mode && event.source === source && event.token === tokens[order.orderId] && event.sentAt && JSON.stringify(event.orderIds) === JSON.stringify(ids)));
      return `<section class="wlp-sourcing-message" data-wlp-batch-message data-source="${source}" data-mode="${mode}" data-order-ids="${ids.join(',')}" data-expected-tokens="${escapeHtml(JSON.stringify(tokens))}"><h3>${source.toUpperCase()} — ${mode === 'stock' ? 'stock check' : 'order request'}</h3><p>Orders: ${escapeHtml(included.map(order => `#${order.orderNumber}`).join(', '))}</p><textarea readonly rows="6" aria-label="${source.toUpperCase()} ${mode} batch message">${escapeHtml(value)}</textarea>${roundingNote}<div class="wlp-sourcing-actions"><button class="button" type="button" data-wlp-copy-sourcing ${canOrder && currentFormat ? '' : 'disabled'}>Copy message</button><button class="button button-primary" type="button" data-wlp-batch-step="sent" ${canOrder && currentFormat && !stockSent ? '' : 'disabled'}>I sent this</button></div><p role="status" data-wlp-batch-status>${canOrder ? (!currentFormat ? 'Reload Logistics to load the updated supplier request format.' : stockSent ? 'Stock check recorded. Use Receive + inspect to record the supplier reply.' : mode === 'order' ? 'Records Supplier order placed on every included order. Next: receive + inspect.' : 'Records this stock check on every included order.') : 'Record payment for every included order before placing this supplier order.'}</p></section>`;
    };
    const histories = new Map();
    orders.forEach(order => (order.wholesale?.sourcing?.batchEvents || []).forEach(event => {
      if (!event.sentAt) return;
      if (!histories.has(event.key)) histories.set(event.key, {event, items:new Map(), seen:new Set(), received:true});
      const history = histories.get(event.key);
      if (history.seen.has(order.orderId)) return;
      history.seen.add(order.orderId); history.received = history.received && Boolean(event.receivedAt);
      (event.items || []).forEach(item => { const key = `${item.name}|${item.unit}`; const product = history.items.get(key) || {...item,quantity:0}; product.quantity += Number(item.quantity); history.items.set(key,product); });
    }));
    const receipt = [...histories.values()].map(({event,items,received}) => {
      const ids = (event.orderIds || []).map(Number), complete = ids.length > 0 && ids.every(id => selected.has(id));
      const legacy = !event.quantities || !event.items;
      const isStock = event.mode === 'stock', done = isStock ? Boolean(event.confirmedAt) : received;
      const requestedItems = Array.isArray(event.requestedItems) ? event.requestedItems : [...items.values()];
      return `<section class="wlp-sourcing-message" data-wlp-batch-message data-source="${escapeHtml(event.source)}" data-mode="${escapeHtml(event.mode)}" data-order-ids="${ids.join(',')}" data-batch-key="${escapeHtml(event.key)}"><h3>${escapeHtml(String(event.source).toUpperCase())} — ${isStock ? (done ? 'Stock confirmed' : 'Stock check sent') : (done ? 'Received + inspected' : 'Supplier order placed')}</h3><p>Orders: ${escapeHtml(ids.map(id => `#${id}`).join(', '))} · Sent ${escapeHtml(event.sentAt)}</p>${requestedItems.length ? `<ul>${requestedItems.map(item => `<li>${escapeHtml(item.name)} — ${item.quantity} ${escapeHtml(item.unit || 'vials')}${item.extra > 0 ? ` (${item.required} for customer orders; ${item.extra} extra, unallocated)` : ''}</li>`).join('')}</ul>` : ''}<p role="status" data-wlp-batch-status>${legacy ? 'Older supplier record: review quantities on each order before continuing.' : !complete ? 'Select all orders listed in this supplier batch to record receipt together.' : done ? (isStock ? 'Stock check confirmed. Next: send the supplier order.' : 'Next: open Shipment / packages on each ready order.') : isStock ? 'Record confirmation after the supplier replies.' : 'Next: record receipt only after the stock is physically here and inspected.'}</p>${!done && !legacy ? `<button class="button button-primary" type="button" data-wlp-batch-step="${isStock ? 'confirmed' : 'received'}" ${complete ? '' : 'disabled'}>${isStock ? 'Supplier confirmed' : 'Received + inspected'}</button>` : ''}</section>`;
    }).join('');
    const orderRequests = ['xp','jkl'].map(source => requests(source,'order')).join('');
    const review = orders.filter(order => order.wholesale?.sourcing?.supplierProgress?.legacyReview);
    return `<section class="wlp-sourcing" data-wlp-batch-sourcing><section data-wlp-batch-phase="send" hidden><h2>Send supplier order</h2><p>Copy the combined request into your supplier chat. After sending it, click I sent this once to update every included order.</p>${orderRequests || '<p>No new supplier quantities are waiting to be ordered. Check Receive + inspect for placed orders, or Assign sources for unassigned products.</p>'}${review.length ? `<p role="alert">Review older supplier records on ${escapeHtml(review.map(order => `#${order.orderNumber}`).join(', '))} before placing more stock.</p>` : ''}<details><summary>Optional stock checks</summary>${['xp','jkl'].map(source => requests(source,'stock')).join('') || '<p>Assign a supplier first.</p>'}</details></section><section data-wlp-batch-phase="receive" hidden><h2>Receive + inspect</h2>${receipt || '<p>No supplier orders recorded yet. Use Send order after assigning sources.</p>'}</section><section data-wlp-batch-phase="pack" hidden><h2>Pack + label</h2>${orders.map(order => `<section class="wlp-sourcing-line"><strong>Order #${escapeHtml(order.orderNumber)}</strong><p>${escapeHtml(order.wholesale?.workflow?.label || 'Review fulfillment')}</p><p>Next: ${escapeHtml(order.wholesale?.workflow?.task || 'Receive and inspect stock, then prepare the shipment.')}</p><button class="button" type="button" data-wlp-split-open data-order-id="${escapeHtml(order.orderId)}">Shipment / packages</button></section>`).join('')}</section></section>`;
  };

  const showBatchPhase = phase => {
    content.querySelectorAll('[data-wlp-batch-phase]').forEach(panel => { panel.hidden = panel.dataset.wlpBatchPhase !== phase; });
    content.querySelectorAll('[data-wlp-batch-phase-button]').forEach(button => {
      const active = button.dataset.wlpBatchPhaseButton === phase;
      button.setAttribute('aria-pressed', String(active)); button.classList.toggle('button-primary', active);
    });
  };

  const loadBatchSourcing = async (phase = '') => {
    const ids = selectedCards().filter((card) => card.getAttribute('data-is-wholesale') === 'yes').map((card) => card.getAttribute('data-order-id')).filter(Boolean);
    if (!ids.length) { bulkStatus('Select at least one wholesale order first.'); return; }
    openDrawer();
    setDrawerTitle('Batch supplier request');
    content.innerHTML = '<div class="wlp-loader"><div class="wlp-spinner"></div><p>Combining selected orders...</p></div>';
    const results = await Promise.all(ids.map(async orderId => { try { return await post('wlp_quick_view_order', { orderId }); } catch (error) { return {success:false}; } }));
    if (results.some(result => !result.success || !result.data?.isWholesale)) { content.innerHTML = renderError('One or more selected orders could not be loaded. No partial supplier request was created. Close this panel and try again.'); return; }
    const orders = results.map(result => result.data);
    if (!orders.length) { content.innerHTML = renderError('Could not load the selected wholesale orders.'); return; }
    setDrawerTitle(`Batch supplier request · ${orders.length} orders`);
    const invalidOrders = orders.filter(order => order.wholesale?.sourcing?.error);
    if (invalidOrders.length) { content.innerHTML = renderError(`Product quantities could not be read for order(s) #${invalidOrders.map(order => order.orderNumber || order.orderId).join(', #')}. Open Fulfillment sources on those cards to review their product lists. No partial supplier draft was created.`, false); return; }
    content.innerHTML = `<nav class="wlp-batch-steps" aria-label="Supplier batch steps">${[['assign','1. Assign'],['send','2. Send order'],['receive','3. Receive + inspect'],['pack','4. Pack + label']].map(([key,label]) => `<button type="button" class="button" data-wlp-batch-phase-button="${key}" aria-pressed="false">${label}</button>`).join('')}</nav><section data-wlp-source-batch-editor data-wlp-batch-phase="assign" hidden><h2>Assign sources across ${orders.length} orders</h2><p>Choose XP, JKL, or Hamilton once per product across all selected orders.</p><details><summary>Assign all remaining products at once</summary><div class="wlp-sourcing-actions">${['xp','jkl','hamilton'].map(source => `<button class="button" type="button" data-wlp-bulk-source="${source}">Assign unassigned to ${source.toUpperCase()}</button>`).join('')}</div></details><div class="wlp-combined-products" data-wlp-combined-products></div><details data-wlp-source-order-details><summary>Adjust individual orders</summary>${orders.map(order => sourcingHtml(order.orderId, order.wholesale?.sourcing || {}, true)).join('')}</details><div class="wlp-sourcing-actions"><button class="button button-primary" type="button" data-wlp-save-source-batch>Save + continue to supplier order</button><span role="status" data-wlp-source-batch-status></span></div></section>${batchSourcingHtml(orders)}`;
    const progress = orders.map(order => order.wholesale?.sourcing?.supplierProgress || {});
    const defaultPhase = progress.some(item => item.unassigned > 0) ? 'assign' : progress.some(item => item.pendingTotal > 0) ? 'send' : progress.some(item => item.ordered > item.received) ? 'receive' : progress.every(item => item.allReady) ? 'pack' : 'assign';
    showBatchPhase(phase || defaultPhase);
    refreshCombinedSources();
    drawer.scrollTop = 0;
  };

  const setCardSelected = (card, selected) => {
    const checkbox = card.querySelector('[data-wlp-order-select]');
    if (checkbox) {
      checkbox.checked = selected;
    }
  };

  const formatError = (message) => message || 'Unknown error.';

  const settingsLink = () => wlpAdmin.settingsUrl
    ? ` <a href="${escapeHtml(wlpAdmin.settingsUrl)}">Open Logistics Settings</a>.`
    : '';

  const renderError = (message, showSettings = true) => `
    <div class="wlp-error" role="alert">
      <strong>Action needed</strong>
      <p>${escapeHtml(formatError(message))}${showSettings ? settingsLink() : ''}</p>
    </div>
  `;

  const bulkStatus = (message, failures = []) => {
    const target = document.querySelector('[data-wlp-bulk-status]');
    if (target) {
      const failureList = failures.length
        ? `<ul>${failures.map((failure) => `<li><strong>Order #${escapeHtml(failure.orderNumber || failure.orderId)}:</strong> ${escapeHtml(formatError(failure.message))}</li>`).join('')}</ul>`
        : '';

      target.innerHTML = `
        <div class="${failures.length ? 'wlp-bulk-status__message is-error' : 'wlp-bulk-status__message'}">
          ${escapeHtml(message)}${failures.length ? settingsLink() : ''}
          ${failureList}
        </div>
      `;
    }
  };

  const trackingLinkHtml = (trackingNumber, trackingUrl) => {
    if (!trackingNumber) {
      return '-';
    }

    if (!trackingUrl) {
      return escapeHtml(trackingNumber);
    }

    return `<a target="_blank" href="${escapeHtml(trackingUrl)}">${escapeHtml(trackingNumber)}</a>`;
  };

  const ensurePrintButton = (card, printUrl) => {
    if (!printUrl) {
      return;
    }

    const actions = card.querySelector('[data-wlp-ship-actions]') || card.querySelector('.wlp-actions');
    if (!actions) {
      return;
    }

    const existing = actions.querySelector('[data-wlp-print-label]');
    if (existing) {
      existing.setAttribute('href', printUrl);
      return;
    }

    actions.insertAdjacentHTML('beforeend', `<a class="button" target="_blank" data-wlp-print-label href="${escapeHtml(printUrl)}">${escapeHtml(text.print || 'Print')}</a>`);
  };

  const ensureCustomerNoteButton = (card) => {
    const actions = card.querySelector('[data-wlp-ship-actions]') || card.querySelector('.wlp-actions');
    if (!actions || actions.querySelector('[data-wlp-send-customer-note]')) {
      return;
    }

    const orderId = card.getAttribute('data-order-id') || '';
    actions.insertAdjacentHTML('beforeend', `<button class="button" type="button" data-wlp-send-customer-note data-order-id="${escapeHtml(orderId)}">${escapeHtml(text.sendNote || 'Send customer note')}</button>`);
  };

	  const ensureReturnButtons = (card, printUrl) => {
	    const actions = card.querySelector('[data-wlp-return-actions]') || card.querySelector('.wlp-actions, .wlp-quick-view__header-actions');
	    if (!actions) {
	      return;
	    }

    const orderId = card.getAttribute('data-order-id') || '';
    if (printUrl && !actions.querySelector('[data-wlp-print-return-label]')) {
      actions.insertAdjacentHTML('beforeend', `<a class="button" target="_blank" data-wlp-print-return-label href="${escapeHtml(printUrl)}">${escapeHtml(text.printReturnLabel || 'Print return label')}</a>`);
    }

    if (!actions.querySelector('[data-wlp-send-return-note]')) {
      actions.insertAdjacentHTML('beforeend', `<button class="button" type="button" data-wlp-send-return-note data-order-id="${escapeHtml(orderId)}">${escapeHtml(text.sendReturnNote || 'Email return label + instructions')}</button>`);
    }
  };

  const wholesaleConfirmText = (stage) => ({
    shipping_details_request: text.confirmWholesaleShippingDetails || 'Ask this wholesale customer for shipping name, shipping address, and phone number?',
    payment_instructions: text.confirmWholesalePaymentInstructions || 'Send the wholesale quote/payment-instructions email to this customer?',
    payment_received: text.confirmWholesalePaymentReceived || 'Confirm payment received and email the customer that fulfillment is starting?',
    hamilton_hub: text.confirmWholesaleHub || 'Email the customer that fulfillment is in progress?',
    inspected_label: text.confirmWholesaleInspected || 'Email the customer that tracking is ready?',
    shipped_tracking: text.confirmWholesaleShipped || 'Email the customer that the order has shipped with tracking?',
    delivered_complete: text.confirmWholesaleDelivered || 'Mark this wholesale order delivered, complete WooCommerce, and email the customer?',
    refund_etransfer: text.confirmWholesaleRefundEtransfer || 'Send the wholesale refund update for Interac e-Transfer?',
    refund_wire: text.confirmWholesaleRefundWire || 'Send the wholesale refund update for wire/EFT?',
  }[stage] || 'Send this wholesale update?');

  const updateWholesaleStage = (surface, result = {}) => {
    if (!surface || !result.stageLabel) {
      return;
    }

    const label = surface.querySelector('[data-wlp-wholesale-stage-label]');
    if (label) {
      label.textContent = result.stageLabel;
    }

    let stageAt = surface.querySelector('[data-wlp-wholesale-stage-at]');
    if (!stageAt && result.stageAt) {
      const summary = surface.querySelector('.wlp-wholesale-summary');
      if (summary) {
        summary.insertAdjacentHTML('beforeend', `<div><span>Updated</span><strong data-wlp-wholesale-stage-at>${escapeHtml(result.stageAt)}</strong></div>`);
        stageAt = summary.querySelector('[data-wlp-wholesale-stage-at]');
      }
    }

    if (stageAt && result.stageAt) {
      stageAt.textContent = result.stageAt;
    }
  };

  const loadWholesalePreview = async (control) => {
    if (!control) {
      return;
    }

    const select = control.querySelector('[data-wlp-wholesale-update-select]');
    const button = control.querySelector('[data-wlp-wholesale-followup]');
    const subject = control.querySelector('[data-wlp-wholesale-preview-subject]');
    const body = control.querySelector('[data-wlp-wholesale-message-body]');
    const orderId = button ? button.getAttribute('data-order-id') : '';
    const stage = select ? select.value : '';

    if (!select || !button || !subject || !body || !orderId || !stage) {
      return;
    }

    if (control.dataset.previewStage === stage && body.value.trim() !== '') {
      return;
    }

    subject.textContent = 'Loading message...';
    body.value = '';

    const result = await post('wlp_wholesale_followup_preview', { orderId, stage });
    if (!result.success) {
      const message = result.data && result.data.message ? result.data.message : 'Could not load message preview.';
      subject.textContent = message;
      body.value = '';
      delete control.dataset.previewStage;
      return;
    }

    subject.textContent = result.data && result.data.subject ? `Subject: ${result.data.subject}` : 'Subject:';
    body.value = result.data && result.data.body ? result.data.body : '';
    control.dataset.previewStage = stage;
  };

  const openWholesaleEmailPreview = async (surface, stage) => {
    const communications = surface ? surface.querySelector('.wlp-wholesale-communications') : null;
    const control = communications ? communications.querySelector('.wlp-wholesale-note-control') : null;
    const select = control ? control.querySelector('[data-wlp-wholesale-update-select]') : null;

    if (!communications || !control || !select || !stage) {
      window.alert('Could not open the customer email preview for this order.');
      return false;
    }

    communications.open = true;
    select.value = stage;
    communications.scrollIntoView({ behavior: 'smooth', block: 'center' });
    await loadWholesalePreview(control);

    const body = control.querySelector('[data-wlp-wholesale-message-body]');
    if (body && body.value.trim() !== '') {
      body.focus({ preventScroll: true });
      body.setSelectionRange(0, 0);
    }

    communications.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
  };

  const outboundTrackingPayload = (form) => ({
    carrier: form && form.querySelector('[data-wlp-outbound-carrier]') ? form.querySelector('[data-wlp-outbound-carrier]').value.trim() : '',
    trackingNumber: form && form.querySelector('[data-wlp-outbound-number]') ? form.querySelector('[data-wlp-outbound-number]').value.trim() : '',
    trackingUrl: form && form.querySelector('[data-wlp-outbound-url]') ? form.querySelector('[data-wlp-outbound-url]').value.trim() : '',
    expectedDeliveryDate: form && form.querySelector('[data-wlp-outbound-date]') ? form.querySelector('[data-wlp-outbound-date]').value.trim() : '',
  });

  const canadaPostTrackingUrl = (trackingNumber) => {
    const number = String(trackingNumber || '').trim();
    return number
      ? `https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=${encodeURIComponent(number)}`
      : '';
  };

  const isCanadaPostCarrier = (carrier) => /canada\s*post|postes\s*canada/i.test(String(carrier || '').trim());

  const updateInboundTrackingUrl = (form) => {
    if (!form) return;
    const carrier = form.querySelector('[data-wlp-inbound-carrier]');
    const trackingNumber = form.querySelector('[data-wlp-inbound-number]');
    const trackingUrl = form.querySelector('[data-wlp-inbound-url]');
    if (!carrier || !trackingNumber || !trackingUrl || !isCanadaPostCarrier(carrier.value)) return;
    const currentUrl = trackingUrl.value.trim();
    const currentIsGenerated = /^https:\/\/www\.canadapost-postescanada\.ca\/track-reperage\/en#\/search\?searchFor=/i.test(currentUrl);
    if (currentUrl && !currentIsGenerated) return;
    trackingUrl.value = canadaPostTrackingUrl(trackingNumber.value);
  };

  const pendingWholesaleNotificationKey = 'wlp_pending_wholesale_notification';

  const reloadAfterWholesaleUpdate = (message, options = {}) => {
    bulkStatus(message || 'Wholesale workflow updated.');
    const orderId = String(options.orderId || '').trim();
    const notificationStage = String(options.notificationStage || '').trim();
    const state = String(options.state || '').trim();

    if (orderId && notificationStage) {
      window.sessionStorage.setItem(pendingWholesaleNotificationKey, JSON.stringify({ orderId, notificationStage }));
    }

    window.setTimeout(() => {
      if (!state) {
        window.location.reload();
        return;
      }

      const url = new URL(window.location.href);
      url.searchParams.set('wlp_status', state);
      window.location.assign(url.toString());
    }, 450);
  };

  const updateCardAfterLabel = (card, printUrl, shipment = {}, packageDetails = {}, estimate = {}, rate = {}) => {
    card.setAttribute('data-has-label', 'yes');
    card.setAttribute('data-print-url', printUrl || '');
    const tracking = card.querySelector('[data-wlp-card-tracking]');
    if (tracking && shipment.tracking_number) {
      tracking.innerHTML = trackingLinkHtml(shipment.tracking_number, shipment.tracking_url);
    }
    const service = card.querySelector('[data-wlp-card-service]');
    if (service && (shipment.service_name || rate.service_name)) {
      service.textContent = shipment.service_name || rate.service_name;
    }
    const inTransit = card.getAttribute('data-logistics-state') === 'in_transit';
    const detailLabel = card.querySelector('[data-wlp-card-detail-label]');
    if (detailLabel && inTransit && estimate.label) {
      detailLabel.textContent = estimate.label;
    }
    const detailTarget = card.querySelector('[data-wlp-card-detail]');
    if (detailTarget) {
      detailTarget.textContent = inTransit ? (estimate.value || packageDetails.preset || '-') : (packageDetails.preset || estimate.value || '-');
    }
    card.querySelectorAll('[data-wlp-create-label]').forEach((createButton) => {
      createButton.textContent = text.viewOptions || 'View options';
    });

    const workflow = card.querySelector('[data-wlp-wholesale-workflow][data-workflow-stage="ready_to_ship"]');
    const outboundForm = workflow ? workflow.querySelector('[data-wlp-wholesale-outbound-form]') : null;
    if (outboundForm && shipment.tracking_number) {
      const setField = (selector, value) => {
        const field = outboundForm.querySelector(selector);
        if (field && value) field.value = value;
      };
      setField('[data-wlp-outbound-carrier]', shipment.service_name || rate.service_name || 'Canada Post');
      setField('[data-wlp-outbound-number]', shipment.tracking_number);
      setField('[data-wlp-outbound-url]', shipment.tracking_url || '');

      const status = outboundForm.querySelector('[data-wlp-outbound-auto-status]');
      if (status) {
        status.hidden = false;
        status.textContent = 'Tracking from the new label was added automatically.';
      }

      const task = workflow.querySelector('.wlp-wholesale-current-task');
      const taskCopy = task ? task.querySelector('div:first-child') : null;
      const taskDescription = taskCopy ? taskCopy.querySelector('p') : null;
      const taskNext = taskCopy ? taskCopy.querySelector('small') : null;
      if (taskDescription) taskDescription.textContent = 'Tracking is ready. Hand the package to the carrier, then confirm shipment.';
      if (taskNext) taskNext.textContent = 'Next: Monitor tracking until delivery.';

      const actions = workflow.querySelector('.wlp-wholesale-current-task__actions');
      const actionButton = actions ? actions.querySelector('[data-wlp-create-label]') : null;
      if (actionButton) {
        actionButton.removeAttribute('data-wlp-create-label');
        actionButton.setAttribute('data-wlp-wholesale-advance', 'confirm_shipped');
        actionButton.textContent = 'Confirm shipped';
        if (!actions.querySelector('[data-wlp-workflow-notify]')) {
          const notify = document.createElement('label');
          notify.className = 'wlp-wholesale-notify';
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.setAttribute('data-wlp-workflow-notify', '');
          checkbox.setAttribute('data-wlp-workflow-notify-stage', 'shipped_tracking');
          const label = document.createElement('span');
          label.append('Notify customer by email ');
          const preview = document.createElement('small');
          preview.textContent = 'Preview first';
          label.append(preview);
          notify.append(checkbox, label);
          actions.append(notify);
        }
      }
    }
    ensurePrintButton(card, printUrl);
    ensureCustomerNoteButton(card);
    updateSelectionCount();
  };

  const updateCardAfterReturnLabel = (card, printUrl, shipment = {}) => {
    if (!card) {
      return;
    }

    const tracking = card.querySelector('[data-wlp-card-return-tracking]');
    if (tracking && shipment.tracking_number) {
      tracking.innerHTML = trackingLinkHtml(shipment.tracking_number, shipment.tracking_url);
    }

    ensureReturnButtons(card, printUrl);
  };

  const updateCardAfterPacked = (surface) => {
    if (!surface) {
      return;
    }

    surface.setAttribute('data-packed', 'yes');

    const packedStatus = surface.querySelector('[data-wlp-card-packed]');
    if (packedStatus) {
      packedStatus.textContent = 'Yes';
    }

    const button = surface.querySelector('[data-wlp-mark-packed]');
    if (button) {
      button.disabled = true;
      button.textContent = text.packed || 'Packed';
    }
  };

  const packingSurfaces = (orderId) => document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId)}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId)}"]`);

  const syncPackingSave = (orderId) => {
    const state = packingSaves.get(String(orderId));
    packingSurfaces(orderId).forEach((surface) => {
      if (state === 'saved') {
        updateCardAfterPacked(surface);
        updateCardAfterInvoicePrinted(surface);
        return;
      }
      const button = surface.querySelector('[data-wlp-mark-packed]');
      if (button && (state === 'pending' || packingFailures.has(String(orderId)))) {
        button.disabled = state === 'pending';
        button.textContent = state === 'pending' ? 'Saving packed…' : 'Retry mark packed';
      }
    });
  };

  const showPackingFailures = () => {
    let notice = document.getElementById('wlp-packing-save-errors');
    if (!packingFailures.size) { notice?.remove(); return; }
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'wlp-packing-save-errors';
      notice.className = 'notice notice-error';
      notice.setAttribute('role', 'alert');
      Object.assign(notice.style, {position: 'fixed', top: '48px', right: '16px', zIndex: '1000000', maxWidth: 'min(28rem, calc(100vw - 48px))', maxHeight: '50vh', overflow: 'auto'});
      drawer.parentNode.insertBefore(notice, drawer);
    }
    notice.innerHTML = `<p><strong>Packing save needs attention</strong></p><ul>${[...packingFailures].map(([id, message]) => `<li>Order #${escapeHtml(id)}: ${escapeHtml(message)} Reopen the order to check its saved state before retrying.</li>`).join('')}</ul>`;
  };

  // Drawer navigation keeps requests alive; leaving the actual page may cancel them.
  window.addEventListener('beforeunload', (event) => {
    if ([...packingSaves.values()].includes('pending')) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  const updateCardAfterInvoicePrinted = (surface) => {
    if (!surface) {
      return;
    }

    surface.setAttribute('data-invoice-printed', 'yes');

    const invoiceStatus = surface.querySelector('[data-wlp-card-invoice]');
    if (invoiceStatus) {
      invoiceStatus.textContent = 'Printed';
    }
  };

  const updateCardAfterShipped = (surface) => {
    if (!surface) {
      return;
    }

    surface.setAttribute('data-logistics-state', 'in_transit');
    updateCardAfterPacked(surface);
    updateCardAfterInvoicePrinted(surface);

    const logisticsStatus = Array.from(surface.querySelectorAll('dt'))
      .find((node) => node.textContent.trim().toLowerCase() === 'logistics');
    if (logisticsStatus && logisticsStatus.nextElementSibling) {
      logisticsStatus.nextElementSibling.textContent = 'In transit';
    }

    const pill = surface.querySelector('.wlp-pill');
    if (pill) {
      pill.classList.remove('is-pending');
      pill.classList.add('is-ready');
      pill.textContent = 'In transit';
    }

    const markShippedButton = surface.querySelector('[data-wlp-mark-shipped]');
    if (markShippedButton) {
      markShippedButton.disabled = true;
      markShippedButton.textContent = text.shipped || 'Shipped';
    }
  };

  const updateCardAfterReshipPrepared = (surface) => {
    if (!surface) {
      return;
    }

    surface.setAttribute('data-has-label', 'no');
    surface.setAttribute('data-invoice-printed', 'no');
    surface.setAttribute('data-packed', 'no');
    surface.setAttribute('data-print-url', '');

    const tracking = surface.querySelector('[data-wlp-card-tracking]');
    if (tracking) {
      tracking.textContent = '-';
    }

    const service = surface.querySelector('[data-wlp-card-service]');
    if (service) {
      service.textContent = '-';
    }

    const invoiceStatus = surface.querySelector('[data-wlp-card-invoice]');
    if (invoiceStatus) {
      invoiceStatus.textContent = 'Not printed';
    }

    const packedStatus = surface.querySelector('[data-wlp-card-packed]');
    if (packedStatus) {
      packedStatus.textContent = 'No';
    }

    const createButton = surface.querySelector('[data-wlp-create-label]');
    if (createButton) {
      createButton.textContent = 'Create label';
    }

    const markPackedButton = surface.querySelector('[data-wlp-mark-packed]');
    if (markPackedButton) {
      markPackedButton.disabled = false;
      markPackedButton.textContent = text.markPacked || 'Mark packed';
    }

    surface.querySelectorAll('[data-wlp-print-label], [data-wlp-send-customer-note]').forEach((element) => {
      element.remove();
    });

    updateSelectionCount();
    updatePrelabelCounts();
  };

  const removeOrderSurfaces = (orderId) => {
    document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId || '')}"]`).forEach((card) => {
      card.remove();
    });

    const quickSurface = content.querySelector(`[data-wlp-order-box][data-order-id="${cssEscape(orderId || '')}"]`);
    if (quickSurface) {
      closeDrawer();
      content.innerHTML = '';
    }

    updateSelectionCount();
    updatePrelabelCounts();
  };

  const renderExistingLabel = (payload, orderId) => {
    if (!payload.hasLabel || !payload.printUrl) {
      return '';
    }

    return `
      <div class="wlp-existing-label">
        <p>${escapeHtml(payload.message || '')}</p>
        <a class="button button-primary" target="_blank" data-wlp-print-label data-order-id="${escapeHtml(orderId)}" href="${escapeHtml(payload.printUrl)}">${escapeHtml(text.reprint || 'Reprint existing label')}</a>
      </div>
    `;
  };

  const signatureEnabled = () => {
    const checkbox = content.querySelector('[data-wlp-signature-required]');
    if (checkbox) {
      return checkbox.checked;
    }

    return wlpAdmin.signatureRequired === 'yes';
  };

  const renderSignatureControl = (orderId, checked) => `
    <label class="wlp-drawer-option">
      <input type="checkbox" data-wlp-signature-required data-order-id="${escapeHtml(orderId)}" ${checked ? 'checked' : ''}>
      <span>${escapeHtml(text.requireSignature || 'Require signature')}</span>
    </label>
  `;

  const cardForPickupEnabled = () => {
    const checkbox = content.querySelector('[data-wlp-card-for-pickup]');
    return checkbox ? checkbox.checked : false;
  };

  const renderCardForPickupControl = (orderId, checked = false) => `
    <label class="wlp-drawer-option">
      <input type="checkbox" data-wlp-card-for-pickup data-order-id="${escapeHtml(orderId)}" ${checked ? 'checked' : ''}>
      <span>${escapeHtml(text.cardForPickup || 'Card for pickup')}</span>
    </label>
  `;

  const customPackageFromForm = () => {
    const values = {};
    ['length', 'width', 'height', 'weight'].forEach((field) => {
      const input = content.querySelector(`[data-wlp-custom-${field}]`);
      values[field] = input ? input.value.trim() : '';
    });

    return values;
  };

  const hasCustomPackageValues = (customPackage = {}) => ['length', 'width', 'height', 'weight']
    .every((field) => customPackage[field] && Number(customPackage[field]) > 0);

  const customPackagePostData = (customPackage = customPackageFromForm()) => {
    if (!hasCustomPackageValues(customPackage)) {
      return null;
    }

    return {
      customPackage: 'yes',
      customLength: customPackage.length,
      customWidth: customPackage.width,
      customHeight: customPackage.height,
      customWeight: customPackage.weight,
    };
  };

  const renderCustomPackageControl = (orderId, customPackage = {}) => {
    const isActive = hasCustomPackageValues(customPackage);

    return `
    <section class="wlp-custom-package ${isActive ? 'is-active' : ''}" data-wlp-custom-package-control data-custom-active="${isActive ? 'yes' : 'no'}">
      <div class="wlp-custom-package__header">
        <div>
          <strong>Custom package ${isActive ? '<span class="wlp-custom-package__status" data-wlp-custom-status>Active package</span>' : ''}</strong>
          <span>${isActive ? 'These measurements are locked to the rates shown below.' : 'Use this when the order does not fit a saved Small or Medium box.'}</span>
        </div>
        <div class="wlp-custom-package__actions">
          ${isActive ? `<button class="button" type="button" data-wlp-use-saved-rates data-order-id="${escapeHtml(orderId)}">Use saved boxes instead</button>` : ''}
          <button class="button ${isActive ? '' : 'button-primary'}" type="button" data-wlp-custom-rates data-order-id="${escapeHtml(orderId)}">${isActive ? 'Refresh custom rates' : 'Use custom package'}</button>
        </div>
      </div>
      <div class="wlp-custom-package__grid">
        <label><span>Length cm</span><input type="number" min="1" max="200" step="0.1" inputmode="decimal" data-wlp-custom-length value="${escapeHtml(customPackage.length || '')}" placeholder="24"></label>
        <label><span>Width cm</span><input type="number" min="1" max="200" step="0.1" inputmode="decimal" data-wlp-custom-width value="${escapeHtml(customPackage.width || '')}" placeholder="16"></label>
        <label><span>Height cm</span><input type="number" min="1" max="200" step="0.1" inputmode="decimal" data-wlp-custom-height value="${escapeHtml(customPackage.height || '')}" placeholder="8"></label>
        <label><span>Weight kg</span><input type="number" min="0.01" max="30" step="0.01" inputmode="decimal" data-wlp-custom-weight value="${escapeHtml(customPackage.weight || '')}" placeholder="0.75"></label>
      </div>
      <p class="wlp-custom-package__note" data-wlp-custom-note>${isActive ? 'Only this custom package can be purchased. Change a measurement and refresh rates before buying.' : 'Enter all four measurements. Saved-box rates will be hidden while this custom package is active.'}</p>
    </section>
  `;
  };

  const parseTransitDays = (value) => {
    if (typeof value === 'number' && Number.isFinite(value)) {
      return Math.max(0, Math.round(value));
    }

    if (typeof value === 'string') {
      const match = value.match(/\d+/);
      if (match) {
        const parsed = Number(match[0]);
        if (Number.isFinite(parsed)) {
          return Math.max(0, Math.round(parsed));
        }
      }
    }

    return null;
  };

  const formatTransitRange = (rate) => {
    const expected = parseTransitDays(rate.expected_transit_time);
    const guaranteed = parseTransitDays(rate.guaranteed_transit_time);
    const minTransit = parseTransitDays(rate.min_transit_time);
    const maxTransit = parseTransitDays(rate.max_transit_time);

    if (minTransit !== null || maxTransit !== null) {
      let minDays = minTransit ?? expected ?? guaranteed;
      let maxDays = maxTransit ?? guaranteed ?? expected ?? minTransit;

      if (minDays === null && maxDays === null) {
        return 'Transit time unavailable';
      }

      if (minDays === null) {
        minDays = maxDays;
      }

      if (maxDays === null) {
        maxDays = minDays;
      }

      if (minDays > maxDays) {
        const currentMin = minDays;
        minDays = maxDays;
        maxDays = currentMin;
      }

      return minDays === maxDays ? `${minDays} business days` : `${minDays}-${maxDays} business days`;
    }

    if (expected !== null) {
      return `${expected} business days`;
    }

    if (guaranteed !== null) {
      return `${guaranteed} business days`;
    }

    return 'Transit time unavailable';
  };

  const renderTransitEstimate = (rate) => {
    return `<div class="wlp-rate__delivery">${escapeHtml(formatTransitRange(rate))}</div>`;
  };

  const serviceLabel = (serviceCode) => {
    const labels = {
      'DOM.RP': 'Regular Parcel',
      'DOM.EP': 'Expedited Parcel',
      'DOM.XP': 'Xpresspost',
      'DOM.PC': 'Priority',
    };

    return labels[serviceCode] || serviceCode || '';
  };

  const renderLabelSummary = (payload, customPackage = {}) => {
    const orderShipping = payload.orderShipping || {};
    const packageSuggestion = payload.packageSuggestion || {};
    const customPackageActive = hasCustomPackageValues(customPackage);
    const pickedLabel = orderShipping.label || serviceLabel(orderShipping.serviceCode) || 'No customer shipping service found';
    const pickedService = orderShipping.serviceCode ? serviceLabel(orderShipping.serviceCode) : '';

    return `
      <div class="wlp-label-summary ${customPackageActive ? 'is-custom-package' : ''}">
        <div class="wlp-label-summary__shipping">
          <span>Customer picked</span>
          <strong>${escapeHtml(pickedLabel)}</strong>
          ${pickedService && pickedService !== pickedLabel ? `<em>${escapeHtml(pickedService)}</em>` : ''}
        </div>
        <div class="wlp-label-summary__package">
          <span>${customPackageActive ? 'Active package' : 'Packing recommendation'}</span>
          <strong>${escapeHtml(customPackageActive ? 'Custom measurements' : (packageSuggestion.label || '-'))}</strong>
          <em>${escapeHtml(customPackageActive ? `${customPackage.length} × ${customPackage.width} × ${customPackage.height} cm • ${customPackage.weight} kg` : `${packageSuggestion.peptideVials || 0} peptide vial${Number(packageSuggestion.peptideVials || 0) === 1 ? '' : 's'} + ${packageSuggestion.bacWaters || 0} BAC water${Number(packageSuggestion.bacWaters || 0) === 1 ? '' : 's'}`)}</em>
          ${customPackageActive ? '<em class="wlp-label-summary__package-reason">Saved boxes are hidden to prevent a package mismatch.</em>' : (packageSuggestion.reason ? `<em class="wlp-label-summary__package-reason">${escapeHtml(packageSuggestion.reason)}</em>` : '')}
        </div>
      </div>
    `;
  };

  const renderReturnSummary = (payload, customPackage = {}) => {
    const address = payload.returnAddress || {};
    const lines = Array.isArray(address.lines) ? address.lines : [];
    const packageSuggestion = payload.packageSuggestion || {};
    const customPackageActive = hasCustomPackageValues(customPackage);

    return `
      <div class="wlp-label-summary ${customPackageActive ? 'is-custom-package' : ''}">
        <div>
          <span>Return label</span>
          <strong>Customer sends package back</strong>
          <em>Original shipping address becomes sender</em>
        </div>
        <div>
          <span>Return to</span>
          <strong>${escapeHtml(address.label || 'Return address')}</strong>
          <em>${escapeHtml(lines.join(' • ') || 'Open settings to confirm return address')}</em>
        </div>
        <div class="wlp-label-summary__package">
          <span>${customPackageActive ? 'Active package' : 'Packing recommendation'}</span>
          <strong>${escapeHtml(customPackageActive ? 'Custom measurements' : (packageSuggestion.label || '-'))}</strong>
          <em>${escapeHtml(customPackageActive ? `${customPackage.length} × ${customPackage.width} × ${customPackage.height} cm • ${customPackage.weight} kg` : `${packageSuggestion.peptideVials || 0} peptide vial${Number(packageSuggestion.peptideVials || 0) === 1 ? '' : 's'} + ${packageSuggestion.bacWaters || 0} BAC water${Number(packageSuggestion.bacWaters || 0) === 1 ? '' : 's'}`)}</em>
          ${customPackageActive ? '<em class="wlp-label-summary__package-reason">Saved boxes are hidden to prevent a package mismatch.</em>' : (packageSuggestion.reason ? `<em class="wlp-label-summary__package-reason">${escapeHtml(packageSuggestion.reason)}</em>` : '')}
        </div>
      </div>
    `;
  };

  const statusPill = (value) => value ? '<span class="wlp-quick-pill is-yes">Yes</span>' : '<span class="wlp-quick-pill">No</span>';

  const quickViewNavigation = (orderId) => {
    const ids = visibleOrderCards()
      .map((card) => card.getAttribute('data-order-id'))
      .filter(Boolean);
    const current = String(orderId || '');
    const index = ids.indexOf(current);

    if (index === -1) {
      return {
        index: 0,
        total: ids.length,
        previousOrderId: '',
        nextOrderId: '',
      };
    }

    return {
      index,
      total: ids.length,
      previousOrderId: index > 0 ? ids[index - 1] : '',
      nextOrderId: index < ids.length - 1 ? ids[index + 1] : '',
    };
  };

  const formatProfitCurrency = (value) => new Intl.NumberFormat('en-CA', {
    style: 'currency',
    currency: 'CAD',
    minimumFractionDigits: 2,
  }).format(Number(value || 0));

  const normalizeSupplierKey = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');

  const pricingTargetMargin = (modal) => {
    const input = modal ? modal.querySelector('[data-wlp-pricing-target-margin]') : null;
    return Math.min(80, Math.max(20, Number(input ? input.value : 50) || 50));
  };

  const roundUpToFive = (value) => Math.ceil(Math.max(0, Number(value || 0)) / 5) * 5;

  const updatePricingRow = (row, targetMargin) => {
    if (!row) return;
    const costInput = row.querySelector('[data-wlp-catalog-supplier-cost]');
    const cost = Math.max(0, Number(costInput ? costInput.value : 0));
    const price = Math.max(0, Number(row.getAttribute('data-wholesale-price') || 0));
    const marginCell = row.querySelector('[data-wlp-pricing-current-margin]');
    const floorCell = row.querySelector('[data-wlp-pricing-floor]');
    const signal = row.querySelector('[data-wlp-pricing-signal]');

    if (!cost || !price) {
      if (marginCell) marginCell.textContent = price ? 'Add cost' : '—';
      if (floorCell) floorCell.textContent = cost ? formatProfitCurrency(roundUpToFive(cost / (1 - (targetMargin / 100)))) : '—';
      if (signal) {
        signal.className = 'wlp-pricing-signal is-missing';
        signal.textContent = price ? 'Cost needed' : 'Cost only';
      }
      return;
    }

    const currentMargin = ((price - cost) / price) * 100;
    const floor = roundUpToFive(cost / (1 - (targetMargin / 100)));
    const room = Math.max(0, price - floor);
    if (marginCell) marginCell.textContent = `${currentMargin.toFixed(1)}%`;
    if (floorCell) floorCell.textContent = formatProfitCurrency(floor);

    if (!signal) return;
    if (currentMargin < targetMargin || floor >= price) {
      signal.className = 'wlp-pricing-signal is-risk';
      signal.textContent = 'Do not lower';
      signal.title = `Starter margin is below the ${targetMargin}% product-margin floor.`;
      return;
    }

    if (currentMargin >= targetMargin + 10 && room >= 20) {
      const testPrice = Math.max(floor, Math.round((price * 0.9) / 5) * 5);
      signal.className = 'wlp-pricing-signal is-opportunity';
      signal.textContent = `Test ${formatProfitCurrency(testPrice)}`;
      signal.title = `${formatProfitCurrency(room)} of room above the ${targetMargin}% product-margin floor. Validate every quantity tier before changing a price.`;
      return;
    }

    signal.className = 'wlp-pricing-signal is-hold';
    signal.textContent = 'Hold';
    signal.title = `Limited room above the ${targetMargin}% product-margin floor.`;
  };

  const updatePricingModal = (modal) => {
    if (!modal) return;
    const targetMargin = pricingTargetMargin(modal);
    const heading = modal.querySelector('[data-wlp-pricing-floor-heading]');
    if (heading) heading.textContent = `${targetMargin}% floor`;
    modal.querySelectorAll('[data-wlp-pricing-row]').forEach((row) => updatePricingRow(row, targetMargin));
  };

  const updatePricingSaveState = (modal) => {
    if (!modal) return;
    const dirtyInputs = [...modal.querySelectorAll('[data-wlp-catalog-supplier-cost]')].filter((input) => {
      const original = Number(input.getAttribute('data-original-cost') || 0);
      return Number(input.value || 0) > 0 && Math.abs(Number(input.value || 0) - original) >= 0.005;
    });
    const saveButton = modal.querySelector('[data-wlp-pricing-save]');
    const status = modal.querySelector('[data-wlp-pricing-status]');
    if (saveButton) saveButton.disabled = dirtyInputs.length === 0;
    if (status && dirtyInputs.length) status.textContent = `${dirtyInputs.length} unsaved supplier-cost change${dirtyInputs.length === 1 ? '' : 's'}.`;
  };

  const profitPanelValues = (panel) => {
    const read = (name) => Math.max(0, Number(panel.querySelector(`[data-wlp-profit-input="${name}"]`)?.value || 0));
    const revenue = read('actualRevenue');
    const supplierCost = read('supplierCost');
    const shippingCost = read('shippingCost');
    const paymentFees = read('paymentFees');
    const taxCost = read('taxCost');
    const otherCosts = read('otherCosts');
    const totalCost = supplierCost + shippingCost + paymentFees + taxCost + otherCosts;
    const profit = revenue - totalCost;
    const margin = revenue > 0 ? (profit / revenue) * 100 : 0;

    return { revenue, supplierCost, shippingCost, paymentFees, taxCost, otherCosts, totalCost, profit, margin };
  };

  const updateProfitPreview = (panel) => {
    if (!panel) return;
    const values = profitPanelValues(panel);
    const profitCard = panel.querySelector('[data-wlp-profit-card]');
    const setText = (selector, value) => {
      const target = panel.querySelector(selector);
      if (target) target.textContent = value;
    };

    setText('[data-wlp-profit-revenue]', formatProfitCurrency(values.revenue));
    setText('[data-wlp-profit-cost]', formatProfitCurrency(values.totalCost));
    setText('[data-wlp-profit-value]', formatProfitCurrency(values.profit));
    setText('[data-wlp-profit-margin]', `${values.margin.toFixed(1)}%`);
    if (profitCard) profitCard.classList.toggle('is-negative', values.profit < 0);
  };

  const renderProfitPanel = (orderId, profit = {}) => {
    const missingSupplierCosts = Array.isArray(profit.missingSupplierCosts) ? profit.missingSupplierCosts : [];
    const supplierCostProducts = Array.isArray(profit.supplierCostProducts) ? profit.supplierCostProducts : [];
    const supplierMode = profit.supplierCostMode === 'manual' ? 'manual' : 'auto';
    const supplierCostNote = missingSupplierCosts.length
      ? `<span class="is-warning">Missing supplier costs: ${escapeHtml(missingSupplierCosts.join(', '))}. Enter and save the permanent per-kit costs below.</span>`
      : `<span>Auto-filled from your supplier table for ${escapeHtml(profit.mappedSupplierItems || 0)} product line${Number(profit.mappedSupplierItems || 0) === 1 ? '' : 's'}.</span>`;
    const supplierCostDefaults = supplierCostProducts.length ? `
      <div class="wlp-supplier-defaults">
        <div class="wlp-supplier-defaults__heading">
          <strong>Permanent supplier costs</strong>
          <span>Cost per 10-vial kit. Saving here updates automatic profit across every wholesale order.</span>
        </div>
        <div class="wlp-supplier-defaults__rows">
          ${supplierCostProducts.map((product) => `
            <label class="${product.missing ? 'is-missing' : ''}">
              <span><strong>${escapeHtml(product.name || '')}</strong><small>${escapeHtml(product.quantity || 0)} vial${Number(product.quantity || 0) === 1 ? '' : 's'} in this order</small></span>
              <input type="number" min="0.01" max="100000" step="0.01" inputmode="decimal" data-wlp-supplier-cost data-supplier-cost-key="${escapeHtml(product.key || '')}" value="${escapeHtml(product.costPerKit || '')}" placeholder="0.00" aria-label="Supplier cost per 10-vial kit for ${escapeHtml(product.name || '')}">
            </label>
          `).join('')}
        </div>
        <div class="wlp-supplier-defaults__actions">
          <button class="button" type="button" data-wlp-save-supplier-costs data-order-id="${escapeHtml(orderId)}">Save permanent supplier costs</button>
          <span data-wlp-supplier-cost-status>Private store-wide defaults. No customer notification.</span>
        </div>
      </div>
    ` : '';

    return `
    <section class="wlp-quick-section wlp-profit-panel" data-wlp-profit-panel data-order-id="${escapeHtml(orderId)}" data-auto-supplier-cost="${escapeHtml(profit.autoSupplierCost || '0.00')}">
      <div class="wlp-profit-panel__heading">
        <div>
          <h4>Internal profit</h4>
          <p>Visible only inside Logistics. Profit equals collected revenue minus all entered costs.</p>
        </div>
        <span>Private</span>
      </div>
      <div class="wlp-profit-summary">
        <div><span>Revenue</span><strong data-wlp-profit-revenue>${escapeHtml(profit.formattedRevenue || formatProfitCurrency(profit.actualRevenue))}</strong></div>
        <div><span>Total costs</span><strong data-wlp-profit-cost>${escapeHtml(profit.formattedCost || formatProfitCurrency(profit.totalCost))}</strong></div>
        <div data-wlp-profit-card class="${Number(profit.profit || 0) < 0 ? 'is-negative' : ''}"><span>Profit</span><strong data-wlp-profit-value>${escapeHtml(profit.formattedProfit || formatProfitCurrency(profit.profit))}</strong></div>
        <div><span>Margin</span><strong data-wlp-profit-margin>${escapeHtml(profit.margin || '0.0')}%</strong></div>
      </div>
      <div class="wlp-profit-fields">
        <label><span>Actual customer payment</span><input type="number" min="0" step="0.01" inputmode="decimal" data-wlp-profit-input="actualRevenue" value="${escapeHtml(profit.actualRevenue || '0.00')}"></label>
        <div class="wlp-profit-supplier-cost"><label><span>Supplier product cost</span><input type="number" min="0" step="0.01" inputmode="decimal" data-wlp-profit-input="supplierCost" value="${escapeHtml(profit.supplierCost || '0.00')}" ${supplierMode === 'auto' ? 'readonly' : ''}></label>${supplierCostNote}<label class="wlp-profit-override"><input type="checkbox" data-wlp-profit-override ${supplierMode === 'manual' ? 'checked' : ''}> Override automatic supplier cost</label></div>
        <label><span>Shipping / fulfilment</span><input type="number" min="0" step="0.01" inputmode="decimal" data-wlp-profit-input="shippingCost" value="${escapeHtml(profit.shippingCost || '0.00')}"></label>
        <label><span>Payment fees</span><input type="number" min="0" step="0.01" inputmode="decimal" data-wlp-profit-input="paymentFees" value="${escapeHtml(profit.paymentFees || '0.00')}"></label>
        <label><span>Sales tax to remit</span><input type="number" min="0" step="0.01" inputmode="decimal" data-wlp-profit-input="taxCost" value="${escapeHtml(profit.taxCost || '0.00')}"></label>
        <label><span>Other costs / credits</span><input type="number" min="0" step="0.01" inputmode="decimal" data-wlp-profit-input="otherCosts" value="${escapeHtml(profit.otherCosts || '0.00')}"></label>
      </div>
      ${supplierCostDefaults}
      <label class="wlp-profit-notes"><span>Internal cost note</span><textarea rows="3" data-wlp-profit-notes placeholder="Supplier deal, backorder, substitutions or missing cost details">${escapeHtml(profit.notes || '')}</textarea></label>
      <div class="wlp-profit-actions">
        <button class="button button-primary" type="button" data-wlp-save-profit data-order-id="${escapeHtml(orderId)}">Save profit calculation</button>
        <span data-wlp-profit-status>${profit.updatedAt ? `Saved ${escapeHtml(profit.updatedAt)}` : 'Not saved yet'}</span>
      </div>
    </section>
  `;
  };

  const wholesaleWorkflowHtml = (orderId, workflow = {}) => {
    const currentNumber = Number(workflow.number || 1);
    const payment = workflow.payment || {};
    const steps = ['Quote', 'Stock', 'Payment', 'Hamilton', 'Pack + label', 'Shipped'];
    const progress = steps.map((label, index) => {
      const number = index + 1;
      const stepClass = number < currentNumber ? 'is-complete' : (number === currentNumber ? 'is-current' : '');
      return `<li class="${stepClass}"><span>${number}</span>${escapeHtml(label)}</li>`;
    }).join('');
    const action = workflow.action
      ? `<button class="button button-primary" type="button" data-wlp-wholesale-advance="${escapeHtml(workflow.action)}" data-order-id="${escapeHtml(orderId)}">${escapeHtml(workflow.actionLabel || 'Complete step')}</button>`
      : (workflow.emailStage
        ? `<button class="button button-primary" type="button" data-wlp-open-wholesale-email="${escapeHtml(workflow.emailStage)}" data-order-id="${escapeHtml(orderId)}">${escapeHtml(workflow.actionLabel || 'Review customer email')}</button>`
        : '');
    const openSources = workflow.openSourcing ? `<button class="button button-primary" type="button" data-wlp-open-sourcing data-order-id="${escapeHtml(orderId)}">Review supplier progress</button>` : '';
    const openLabel = workflow.openLabel
      ? `<button class="button button-primary" type="button" data-wlp-split-open data-order-id="${escapeHtml(orderId)}">Shipment / packages</button>`
      : '';
    const notification = workflow.action && workflow.notificationStage
      ? `<label class="wlp-wholesale-notify"><input type="checkbox" data-wlp-workflow-notify data-wlp-workflow-notify-stage="${escapeHtml(workflow.notificationStage)}"><span>Notify customer by email <small>Preview first</small></span></label>`
      : '';
    const paymentEmailOption = workflow.paymentEmailOption
      ? '<label class="wlp-wholesale-notify"><input type="checkbox" data-wlp-payment-notify-email checked><span>Email payment received confirmation <small>Uncheck for internal-only Processing</small></span></label>'
      : '';
    const partialPayment = ['final_quote', 'awaiting_payment'].includes(workflow.stage) ? `
      <div class="wlp-wholesale-partial-payment" data-wlp-wholesale-partial-payment>
        <div class="wlp-wholesale-payment-summary">
          <div><span>Recorded</span><strong>${escapeHtml(payment.paidFormatted || 'C$0.00')}</strong></div>
          <div><span>Balance due</span><strong>${escapeHtml(payment.balanceFormatted || workflow.quoteTotal || '')}</strong></div>
        </div>
        ${Number(payment.balanceCents || 0) > 0 ? `
          <div class="wlp-wholesale-partial-payment__entry">
            <label><span>Partial payment received (CAD)</span><input type="number" min="0.01" max="${escapeHtml(payment.balance || '')}" step="0.01" inputmode="decimal" data-wlp-partial-payment-amount placeholder="0.00"></label>
            <button class="button" type="button" data-wlp-record-partial-payment data-order-id="${escapeHtml(orderId)}">Record partial payment</button>
          </div>
        ` : '<p>The full quoted total is recorded. Use “Record payment verified” only after final reconciliation.</p>'}
        <small>Internal only. This does not change the order status, email the customer, start fulfillment, or report a purchase.</small>
      </div>
    ` : '';
    const shippedTracking = workflow.stage === 'shipped' ? `
      <div class="wlp-wholesale-shipped-tracking">
        <span>Customer tracking</span>
        ${workflow.trackingNumber && workflow.trackingUrl
          ? `<a target="_blank" href="${escapeHtml(workflow.trackingUrl)}">${escapeHtml(workflow.trackingNumber)}</a>`
          : `<strong>${escapeHtml(workflow.trackingNumber || 'Tracking unavailable')}</strong>`}
        ${workflow.carrier ? `<small>${escapeHtml(workflow.carrier)}</small>` : ''}
      </div>
    ` : '';
    const workflowOptions = [
      ['awaiting_customer', 'Quotes — Waiting on customer'],
      ['stock_check', 'Quotes — Supplier check'],
      ['final_quote', 'Quotes — Final quote ready'],
      ['awaiting_payment', 'Quotes — Awaiting payment'],
      ['supplier_order', 'Wholesale to ship — Supplier / stock on hand'],
      ['inbound_hamilton', 'Wholesale to ship — Inbound to Hamilton'],
      ['ready_to_ship', 'Wholesale to ship — Ready to ship (records inspection)'],
    ].map(([value, label]) => `<option value="${value}" ${workflow.stage === value ? 'selected' : ''}>${label}</option>`).join('');
    const inbound = ['supplier_order', 'inbound_hamilton'].includes(workflow.stage) ? `
      <details class="wlp-wholesale-tracking-form wlp-tidy-details" data-wlp-wholesale-inbound-form>
        <summary>Supplier to Hamilton tracking</summary>
        <p>Internal only. This tracking is never included in the customer email.</p>
        <div class="wlp-wholesale-tracking-grid">
          <label><span>Carrier</span><input type="text" data-wlp-inbound-carrier value="${escapeHtml(workflow.inboundCarrier || '')}" placeholder="Canada Post"></label>
          <label><span>Inbound tracking</span><input type="text" data-wlp-inbound-number value="${escapeHtml(workflow.inboundTrackingNumber || '')}"></label>
          <label class="is-wide"><span>Tracking link (auto for Canada Post)</span><input type="url" data-wlp-inbound-url value="${escapeHtml(workflow.inboundTrackingUrl || '')}"></label>
        </div>
        <button class="button" type="button" data-wlp-save-inbound-tracking data-order-id="${escapeHtml(orderId)}">Add inbound tracking</button>
      </details>
    ` : '';
    const outbound = workflow.stage === 'ready_to_ship' ? `
      <div class="wlp-wholesale-tracking-form" data-wlp-wholesale-outbound-form>
        <strong>Customer shipment</strong>
        <p>A Canada Post label automatically adds its tracking here. You can edit it before sending.</p>
        <p class="wlp-wholesale-tracking-status" data-wlp-outbound-auto-status hidden></p>
        <div class="wlp-wholesale-tracking-grid">
          <label><span>Carrier</span><input type="text" data-wlp-outbound-carrier value="${escapeHtml(workflow.carrier || '')}" placeholder="Canada Post"></label>
          <label><span>Customer tracking</span><input type="text" data-wlp-outbound-number value="${escapeHtml(workflow.trackingNumber || '')}"></label>
          <label class="is-wide"><span>Tracking link (optional)</span><input type="url" data-wlp-outbound-url value="${escapeHtml(workflow.trackingUrl || '')}"></label>
          <label><span>Expected delivery (optional)</span><input type="date" data-wlp-outbound-date value="${escapeHtml(workflow.expectedDeliveryDate || '')}"></label>
        </div>
        <div class="wlp-wholesale-tracking-actions">
          <button class="button" type="button" data-wlp-save-outbound-tracking data-order-id="${escapeHtml(orderId)}">Save tracking changes</button>
          <button class="button button-primary" type="button" data-wlp-preview-outbound-tracking data-order-id="${escapeHtml(orderId)}">Preview tracking email</button>
        </div>
        <div class="wlp-wholesale-tracking-preview" data-wlp-tracking-preview hidden>
          <strong data-wlp-tracking-preview-subject></strong>
          <span data-wlp-tracking-preview-recipient></span>
          <textarea rows="12" data-wlp-tracking-preview-body aria-label="Customer tracking email body"></textarea>
          <button class="button button-primary" type="button" data-wlp-confirm-outbound-ship data-order-id="${escapeHtml(orderId)}">Mark shipped + send this email</button>
        </div>
      </div>
    ` : '';

    return `
      <section class="wlp-wholesale-workflow" data-wlp-wholesale-workflow data-workflow-stage="${escapeHtml(workflow.stage || '')}">
        <ol class="wlp-wholesale-progress" aria-label="Wholesale order progress">${progress}</ol>
        <div class="wlp-wholesale-current-task">
          <div>
            <span class="wlp-wholesale-current-task__eyebrow">Do this now</span>
            <strong>${escapeHtml(workflow.label || 'Wholesale follow-up')}</strong>
            <p>${escapeHtml(workflow.task || '')}</p>
            <small><b>Next:</b> ${escapeHtml(workflow.next || '')}</small>
          </div>
          <div class="wlp-wholesale-current-task__actions">${action}${openLabel}${openSources}${notification}${paymentEmailOption}</div>
        </div>
        ${partialPayment}
        ${shippedTracking}
        <details class="wlp-wholesale-workflow-adjust" data-wlp-wholesale-workflow-adjust>
          <summary>Adjust internal workflow step</summary>
          <p>Use only to correct the order board. This never emails the customer; Shipped still requires the blue Confirm shipped action.</p>
          <div>
            <select data-wlp-workflow-stage-select aria-label="Wholesale workflow step">${workflowOptions}</select>
            <button class="button" type="button" data-wlp-workflow-set-step data-order-id="${escapeHtml(orderId)}">Set step</button>
          </div>
        </details>
        ${inbound}
        ${outbound}
      </section>
    `;
  };

  const renderQuickView = (payload, navigation = {}) => {
    const logistics = payload.logistics || {};
    const wholesale = payload.wholesale || {};
    const isWholesale = !!payload.isWholesale;
    const packingCatalog = Array.isArray(payload.packingCatalog) ? payload.packingCatalog : [];
    const items = (payload.items || []).map((item) => `
      <tr>
        <td>
          <strong>${escapeHtml(item.name)}</strong>
          ${item.sku ? `<span>${escapeHtml(item.sku)}</span>` : ''}
        </td>
        <td>${escapeHtml(item.quantity)}</td>
        <td>${escapeHtml(item.total || '')}</td>
      </tr>
    `).join('');
    const packingItems = (payload.items || []).map((item) => `
      <li>
        <div>
          <strong>${escapeHtml(item.name)}</strong>
          ${item.sku ? `<span>${escapeHtml(item.sku)}</span>` : ''}
        </div>
        <b aria-label="Quantity ${escapeHtml(item.quantity)}">&times;${escapeHtml(item.quantity)}</b>
      </li>
    `).join('');
    const address = (payload.shippingLines || []).length
      ? payload.shippingLines.map((line) => `<div>${escapeHtml(line)}</div>`).join('')
      : '<div>-</div>';
    const notes = (payload.notes || []).length
      ? payload.notes.map((note) => `
        <li>
          <strong>${escapeHtml(note.customerNote ? 'Customer note' : 'Internal note')}</strong>
          <span>${escapeHtml(note.date || '')}</span>
          <p>${escapeHtml(note.note || '')}</p>
        </li>
      `).join('')
      : '<li><p>No recent notes.</p></li>';
    const tracking = logistics.trackingNumber
      ? trackingLinkHtml(logistics.trackingNumber, logistics.trackingUrl)
      : '-';
    const returnTracking = logistics.returnTrackingNumber
      ? trackingLinkHtml(logistics.returnTrackingNumber, logistics.returnTrackingUrl)
      : '-';
    const wholesaleLines = Array.isArray(wholesale.lines) ? wholesale.lines : [];
    const wholesaleLineItems = wholesaleLines.length ? `
      <div class="wlp-wholesale-line-items">
        <span>Requested items</span>
        <div class="wlp-wholesale-line-items__scroll">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>Qty</th>
                <th>Price / vial</th>
                <th>Line total</th>
              </tr>
            </thead>
            <tbody>
              ${wholesaleLines.map((line) => `
                <tr>
                  <td>${escapeHtml(line.name || '')}</td>
                  <td>${escapeHtml(line.quantity || '')}</td>
                  <td>${escapeHtml(line.unitPrice || '')}</td>
                  <td><strong>${escapeHtml(line.lineTotal || '')}</strong></td>
                </tr>
              `).join('')}
            </tbody>
            ${wholesale.quoteSubtotal ? `
              <tfoot>
                ${Number(wholesale.quoteCreditAmount || 0) !== 0 ? `
                  <tr>
                    <th colspan="3">Merchandise subtotal</th>
                    <td>${escapeHtml(wholesale.quoteMerchandiseSubtotal || wholesale.quoteSubtotal)}</td>
                  </tr>
                  <tr>
                    <th colspan="3">Wholesale credit</th>
                    <td>${escapeHtml(wholesale.quoteCredit || '')}</td>
                  </tr>
                  <tr>
                    <th colspan="3">Subtotal after credit</th>
                    <td>${escapeHtml(wholesale.quoteSubtotal)}</td>
                  </tr>
                ` : `
                  <tr>
                    <th colspan="3">Subtotal</th>
                    <td>${escapeHtml(wholesale.quoteSubtotal)}</td>
                  </tr>
                `}
                ${Number(wholesale.quoteTaxAmount || 0) > 0 ? (
                  Array.isArray(wholesale.quoteTaxRows) && wholesale.quoteTaxRows.length
                    ? wholesale.quoteTaxRows
                    : [{ label: wholesale.quoteTaxLabel || 'Estimated tax', formatted: wholesale.quoteTax || '' }]
                ).map((row) => `<tr><th colspan="3">${escapeHtml(row.label)}</th><td>${escapeHtml(row.formatted)}</td></tr>`).join('') : ''}
                <tr class="wlp-wholesale-line-items__total">
                  <th colspan="3">Total</th>
                  <td><strong>${escapeHtml(wholesale.quoteTotal || wholesale.quoteSubtotal)}</strong></td>
                </tr>
              </tfoot>
            ` : ''}
          </table>
        </div>
      </div>
    ` : '';
	const wholesaleCopy = wholesale.copyText ? `
	  <div class="wlp-wholesale-copy">
	    <button class="button" type="button" data-wlp-copy-wholesale>Copy customer quote</button>
	    <textarea hidden readonly data-wlp-wholesale-copy-source>${escapeHtml(wholesale.copyText)}</textarea>
	  </div>
	` : '';
    const wholesaleNotes = isWholesale ? `
      <div class="wlp-wholesale-internal-notes" data-wlp-wholesale-notes>
        <label>
          <span>Customer / quote notes</span>
          <textarea rows="3" maxlength="4000" data-wlp-wholesale-notes-field placeholder="Add customer context, commitments, follow-up points or quote decisions.">${escapeHtml(wholesale.internalNotes || '')}</textarea>
        </label>
        <div class="wlp-wholesale-internal-notes__actions">
          <small data-wlp-wholesale-notes-status>${wholesale.internalNotesUpdatedAt ? `Private. Saved ${escapeHtml(wholesale.internalNotesUpdatedAt)}. Never emailed to the customer.` : 'Private. Never emailed to the customer.'}</small>
          <button class="button" type="button" data-wlp-save-wholesale-notes data-order-id="${escapeHtml(payload.orderId)}">Save private notes</button>
        </div>
      </div>
    ` : '';
    const wholesaleUpdateControl = isWholesale ? `
      <details class="wlp-wholesale-communications">
        <summary>Customer communication</summary>
      <div class="wlp-wholesale-note-control">
        <label class="screen-reader-text" for="wlp-wholesale-update-quick-${escapeHtml(payload.orderId)}">Wholesale update</label>
        <select id="wlp-wholesale-update-quick-${escapeHtml(payload.orderId)}" data-wlp-wholesale-update-select>
          <option value="payment_instructions">Send final quote + payment</option>
          <option value="shipping_details_request">Missing shipping details</option>
          <option value="payment_received">Payment received + fulfillment</option>
          <option value="hamilton_hub">Fulfillment in progress</option>
          <option value="inspected_label">Tracking ready</option>
          <option value="shipped_tracking">Shipped + tracking</option>
          <option value="refund_etransfer">Refund by e-Transfer</option>
          <option value="refund_wire">Refund by wire/EFT</option>
          <option value="delivered_complete">Delivered + complete</option>
        </select>
        <div class="wlp-wholesale-message-preview">
          <strong data-wlp-wholesale-preview-subject>Message preview loads after Quick view opens.</strong>
          <textarea data-wlp-wholesale-message-body rows="12" aria-label="Wholesale message body"></textarea>
        </div>
        <button class="button wlp-wholesale-action" type="button" data-wlp-wholesale-followup data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.sendWholesaleUpdate || 'Send wholesale update')}</button>
      </div>
      </details>
    ` : '';
    const sourcingDetails = isWholesale ? `<details class="wlp-tidy-details" data-wlp-sourcing-details><summary>Fulfillment sources · JKL / XP / Hamilton</summary>${sourcingHtml(payload.orderId, wholesale.sourcing || {})}</details>` : '';
    const wholesaleSummary = isWholesale ? `
      <section class="wlp-quick-section wlp-wholesale-summary" aria-label="Wholesale quote summary">
        ${wholesaleWorkflowHtml(payload.orderId, wholesale.workflow || {})}
        ${sourcingDetails}
        <details class="wlp-tidy-details wlp-quote-details"><summary>Quote, pricing &amp; contact details</summary>
        ${wholesale.quoteTotal ? `<div><span>${Number(wholesale.quoteTaxAmount || 0) > 0 ? 'Total including tax' : 'Quote total'}</span><strong>${escapeHtml(wholesale.quoteTotal)}</strong></div>` : ''}
        ${Number(wholesale.vialCount || 0) > 0 ? `<div><span>Total vials</span><strong>${escapeHtml(wholesale.vialCount)} ${Number(wholesale.vialCount) === 1 ? 'vial' : 'vials'}</strong></div>` : ''}
        ${payload.email ? `<div><span>Email</span><strong>${escapeHtml(payload.email)}</strong></div>` : ''}
        ${payload.phone ? `<div><span>Phone</span><strong>${escapeHtml(payload.phone)}</strong></div>` : ''}
        ${wholesale.deliveryProvince ? `<div><span>Delivery province</span><strong>${escapeHtml(wholesale.deliveryProvince)}</strong></div>` : ''}
        ${wholesale.payment ? `<div><span>Payment</span><strong>${escapeHtml(wholesale.payment)}</strong></div>` : ''}
        ${wholesale.release ? `<div><span>Requested shipping</span><strong>${escapeHtml(wholesale.release)}</strong></div>` : ''}
        <div><span>Follow-up</span><strong data-wlp-wholesale-stage-label>${escapeHtml(wholesale.stageLabel || 'Quote needs follow-up')}</strong></div>
        ${wholesale.stageAt ? `<div><span>Updated</span><strong data-wlp-wholesale-stage-at>${escapeHtml(wholesale.stageAt)}</strong></div>` : ''}
        ${wholesaleLineItems}
        ${!wholesaleLines.length && wholesale.products ? `<p><span>Requested</span>${escapeHtml(wholesale.products)}</p>` : ''}
		${wholesaleCopy}
        ${!wholesale.workflow?.stage && wholesale.nextStep ? `<p><span>Next step</span>${escapeHtml(wholesale.nextStep)}</p>` : ''}
        </details>
        <details class="wlp-tidy-details"><summary>Edit private notes</summary>${wholesaleNotes}</details>
      </section>
    ` : '';
	const profitPanel = isWholesale ? renderProfitPanel(payload.orderId, wholesale.profit || {}) : '';

    return `
      <div class="wlp-quick-view ${logistics.deliveryLate ? 'is-delivery-late' : ''}" data-wlp-order-box data-order-id="${escapeHtml(payload.orderId)}" data-logistics-state="${escapeHtml(logistics.state || '')}" data-wholesale-workflow-stage="${escapeHtml(wholesale.workflow && wholesale.workflow.stage ? wholesale.workflow.stage : '')}" data-has-label="${logistics.hasLabel ? 'yes' : 'no'}" data-invoice-printed="${logistics.invoicePrinted ? 'yes' : 'no'}" data-packed="${logistics.packed ? 'yes' : 'no'}" data-alcohol-pads-due="${logistics.alcoholPadsDue ? 'yes' : 'no'}" data-delivery-late="${logistics.deliveryLate ? 'yes' : 'no'}">
        <div class="wlp-quick-nav" aria-label="Quick view order navigation">
          <button class="button" type="button" data-wlp-quick-nav data-order-id="${escapeHtml(navigation.previousOrderId || '')}" ${navigation.previousOrderId ? '' : 'disabled'}>&larr; Previous</button>
          <span>${navigation.total ? `Order ${escapeHtml((navigation.index || 0) + 1)} of ${escapeHtml(navigation.total)}` : 'Order navigation'}</span>
          <button class="button" type="button" data-wlp-quick-nav data-order-id="${escapeHtml(navigation.nextOrderId || '')}" ${navigation.nextOrderId ? '' : 'disabled'}>Next &rarr;</button>
        </div>
        ${payload.customerNote ? `<details class="wlp-tidy-details wlp-customer-note-alert" ${payload.customerNote.length <= 300 ? 'open' : ''}><summary>Customer note · review before packing</summary><p>${escapeHtml(payload.customerNote)}</p></details>` : ''}
        ${logistics.alcoholPadsDue ? `<div class="wlp-fulfillment-reminder">${escapeHtml(text.alcoholPadsReminder || 'Fulfillment reminder: add complimentary alcohol pads.')}</div>` : ''}
        ${logistics.deliveryLate ? `<div class="wlp-late-delivery-banner"><strong>Delivery needs review</strong><span>Expected delivery was ${escapeHtml(logistics.expectedDeliveryLabel || 'the stored carrier date')}. Check Canada Post tracking and follow up if needed.</span></div>` : ''}
        <div class="wlp-quick-view__header">
          <div class="wlp-quick-view__identity">
            <h3>#${escapeHtml(payload.orderNumber)}</h3>
            <p>${escapeHtml(payload.customer || payload.shippingName || '')}</p>
            ${isWholesale ? `
              <section class="wlp-quick-pack-list" aria-label="Full order contents">
                <h4>Full order contents</h4>
                <p>For split orders, check package quantities in Split shipment / packages.</p>
                <ul>${packingCatalog.map(item => `<li><span>${escapeHtml(item.name)}</span><b>&times;${escapeHtml(item.quantity)}</b></li>`).join('') || `<li><strong>Review order contents before packing.</strong> ${escapeHtml(wholesale.products || 'Review saved quote products.')}</li>`}</ul>
                ${!packingCatalog.length && packingItems ? `<h4>Woo order items / extras</h4><ul>${packingItems}</ul>` : ''}
                <p><b>Vial case:</b> ${escapeHtml(payload.vialCase?.label || '-')}</p>
                ${wholesale.internalNotes ? `<p class="wlp-packing-note"><b>Private notes:</b> ${escapeHtml(wholesale.internalNotes)}</p>` : ''}
              </section>
            ` : `
              <section class="wlp-quick-pack-list" aria-label="Vials and items needed">
                <h4>Vials / items needed</h4>
                <ul>${packingItems || '<li><span>No items found.</span></li>'}</ul>
              </section>
            `}
          </div>
          <div class="wlp-quick-view__header-actions">
            <button class="button button-primary" type="button" data-wlp-mark-packed data-order-id="${escapeHtml(payload.orderId)}" ${logistics.packed ? 'disabled' : ''}>${escapeHtml(logistics.packed ? (text.packed || 'Packed') : (text.markPacked || 'Mark packed'))}</button>
            <button class="button" type="button" data-wlp-create-label data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(logistics.hasLabel ? (text.viewLabelOptions || 'View label options') : (text.createLabel || 'Create label'))}</button>
            ${logistics.hasLabel && logistics.printUrl ? `<a class="button" target="_blank" data-wlp-print-label data-order-id="${escapeHtml(payload.orderId)}" href="${escapeHtml(logistics.printUrl)}">${escapeHtml(text.printLabel || 'Print label')}</a>` : ''}
            <a class="button" target="_blank" data-wlp-print-invoice data-order-id="${escapeHtml(payload.orderId)}" href="${escapeHtml(logistics.invoiceUrl || '#')}">${escapeHtml(text.printInvoice || 'Print invoice')}</a>
            <button class="button" type="button" data-wlp-manual-counter data-order-id="${escapeHtml(payload.orderId)}">Manual counter label</button>
            <details class="wlp-tidy-details"><summary>Returns &amp; reshipment</summary>
            <button class="button" type="button" data-wlp-prepare-reship data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.prepareReship || 'Prepare reship')}</button>
            <button class="button" type="button" data-wlp-create-return-label data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.createReturnLabel || 'Create return label')}</button>
            ${logistics.hasReturnLabel ? `<a class="button" target="_blank" data-wlp-print-return-label href="${escapeHtml(logistics.returnPrintUrl || '#')}">${escapeHtml(text.printReturnLabel || 'Print return label')}</a>` : ''}
            ${logistics.hasReturnLabel ? `<button class="button" type="button" data-wlp-send-return-note data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.sendReturnNote || 'Email return label + instructions')}</button>` : ''}
            ${logistics.deliveryLate ? `<button class="button" type="button" data-wlp-send-delay-note data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.sendDelayNote || 'Send delay follow-up')}</button>` : ''}
            <button class="button wlp-button-danger" type="button" data-wlp-cancel-order data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.cancelOrder || 'Cancel order')}</button>
            </details>
            ${logistics.state === 'to_be_shipped' || (isWholesale && logistics.state === 'wholesale_to_ship' && logistics.packed) ? `<button class="button" type="button" data-wlp-mark-shipped data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.markShipped || 'Mark shipped')}</button>` : ''}
            ${(!isWholesale || wholesale.workflow?.stage === 'shipped') && !['delivered', 'wholesale_delivered'].includes(logistics.state) ? `<button class="button" type="button" data-wlp-mark-delivered data-order-id="${escapeHtml(payload.orderId)}">${escapeHtml(text.markDelivered || 'Mark delivered')}</button>` : ''}
            <a class="button" target="_blank" href="${escapeHtml(payload.adminUrl || '#')}">Open full order</a>
            ${wholesaleUpdateControl}
          </div>
        </div>
        ${payload.isWholesale ? `<button class="button button-primary" type="button" data-wlp-split-open data-order-id="${escapeHtml(payload.orderId)}">Shipment / packages</button>` : ''}
        ${payload.splitActive ? '<p class="wlp-split-summary">This order has a saved shipment plan. Manage quantities, labels and handoff in Shipment / packages.</p>' : wholesaleSummary}
        ${payload.splitActive ? sourcingDetails : ''}
        ${profitPanel ? `<details class="wlp-tidy-details"><summary>Profit &amp; supplier costs</summary>${profitPanel}</details>` : ''}
        <dl class="wlp-quick-view__facts">
          <div><dt>Status</dt><dd>${escapeHtml(payload.status || '-')}</dd></div>
          <div><dt>Total</dt><dd>${escapeHtml(payload.total || '-')}</dd></div>
          <div><dt>Label</dt><dd>${statusPill(logistics.hasLabel)}</dd></div>
          <div><dt>Invoice</dt><dd data-wlp-card-invoice>${escapeHtml(logistics.invoicePrinted ? 'Printed' : 'Not printed')}</dd></div>
          <div><dt>Packed</dt><dd data-wlp-card-packed>${escapeHtml(logistics.packed ? 'Yes' : 'No')}</dd></div>
          <div><dt>Vial case</dt><dd>${escapeHtml(payload.vialCase && payload.vialCase.label ? payload.vialCase.label : '-')}</dd></div>
          <div><dt>Expected delivery</dt><dd>${escapeHtml(logistics.expectedDeliveryLabel || '-')}</dd></div>
          <div><dt>Tracking</dt><dd data-wlp-card-tracking>${tracking}</dd></div>
          <div><dt>Return</dt><dd data-wlp-card-return-tracking>${returnTracking}</dd></div>
        </dl>
        ${isWholesale && !wholesaleLines.length && !packingItems ? `<section class="wlp-quick-section">
          <h4>${isWholesale ? 'Quote products' : 'Items to pack'}</h4>
          <table class="wlp-quick-items">
            <thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
            <tbody>${items || `<tr><td colspan="3">${isWholesale ? 'See requested wholesale products above.' : 'No items found.'}</td></tr>`}</tbody>
          </table>
        </section>` : ''}
        <section class="wlp-quick-section">
          <h4>Ship to</h4>
          <div class="wlp-quick-address">
            <strong data-wlp-shipping-name>${escapeHtml(payload.shippingName || payload.customer || '')}</strong>
            <div data-wlp-shipping-lines>${address}</div>
            ${payload.phone ? `<div>${escapeHtml(payload.phone)}</div>` : ''}
            ${payload.email ? `<div>${escapeHtml(payload.email)}</div>` : ''}
          </div>
        </section>
        <details class="wlp-quick-section wlp-tidy-details">
          <summary>Recent order notes</summary>
          <ul class="wlp-quick-notes">${notes}</ul>
        </details>
      </div>
    `;
  };

  const loadQuickView = async (orderId) => {
    const packingWasPending = packingSaves.get(String(orderId)) === 'pending';
    setDrawerTitle(text.loadingOrder || 'Loading order...');
    content.innerHTML = `
      <div class="wlp-loader">
        <div class="wlp-spinner"></div>
        <p>${escapeHtml(text.loadingOrder || 'Loading order...')}</p>
      </div>
    `;

    const result = await post('wlp_quick_view_order', { orderId });
    if (!result.success) {
      setDrawerTitle(text.quickView || 'Quick view');
      content.innerHTML = renderError(result.data && result.data.message ? result.data.message : (text.failedQuickView || 'Failed to load order quick view.'));
      return;
    }

    setDrawerTitle(`#${result.data.orderNumber} quick view`);
    content.innerHTML = renderQuickView(result.data, quickViewNavigation(result.data.orderId));
    if (result.data.logistics?.packed) {
      packingFailures.delete(String(result.data.orderId));
      showPackingFailures();
    }
    if (!packingWasPending && packingSaves.get(String(orderId)) === 'saved') packingSaves.delete(String(orderId));
    syncPackingSave(String(result.data.orderId));
    if (result.data.customerNote) {
      window.setTimeout(() => {
        window.alert(`Customer note for order #${result.data.orderNumber}\n\n${result.data.customerNote}`);
      }, 100);
    }
  };

  const loadRates = async (orderId, signatureRequired = signatureEnabled(), cardForPickup = cardForPickupEnabled(), customPackage = null) => {
    drawerMode = 'label';
    setDrawerTitle('Create Canada Post Label');
    content.innerHTML = `
      ${renderSignatureControl(orderId, signatureRequired)}
      ${renderCardForPickupControl(orderId, cardForPickup)}
      ${renderCustomPackageControl(orderId, customPackage || {})}
      <div class="wlp-loader">
        <div class="wlp-spinner"></div>
        <p>${escapeHtml(text.loadingRates || 'Loading Canada Post rates...')}</p>
      </div>
    `;

    const postData = {
      orderId,
      signatureRequired: signatureRequired ? 'yes' : 'no',
    };
    const customPostData = customPackagePostData(customPackage || {});
    if (customPostData) {
      Object.assign(postData, customPostData);
    }

    const result = await post('wlp_get_rates', postData);
    if (!result.success) {
      content.innerHTML = `
        ${renderSignatureControl(orderId, signatureRequired)}
        ${renderCardForPickupControl(orderId, cardForPickup)}
        ${renderCustomPackageControl(orderId, customPackage || {})}
        ${renderError(result.data && result.data.message ? result.data.message : (text.failedRates || 'Failed to load rates.'))}
      `;
      return;
    }

    if (result.data.hasLabel) {
      result.data.message = result.data.message || 'This order already has a label. Reprint it or buy a replacement.';
    }

    renderRates(orderId, result.data, signatureRequired, cardForPickup, customPackage || {});
  };

  const renderRates = (orderId, payload, signatureRequired = signatureEnabled(), cardForPickup = cardForPickupEnabled(), customPackage = {}) => {
    const suggested = payload.suggestedRate || {};
    const customPackageActive = hasCustomPackageValues(customPackage);
    const visiblePresets = customPackageActive
      ? payload.presets.filter((entry) => String(entry.preset.id) === 'custom-box')
      : payload.presets.filter((entry) => String(entry.preset.id) !== 'custom-box');
    const groups = visiblePresets.map((entry) => {
      const rates = entry.rates.length
        ? entry.rates.map((rate) => {
          const isSuggestedPackage = suggested.presetId && String(entry.preset.id) === String(suggested.presetId);
          const isSuggestedRate = isSuggestedPackage && suggested.serviceCode && String(rate.service_code) === String(suggested.serviceCode);
          const isCustomerPicked = payload.orderShipping && payload.orderShipping.serviceCode && String(rate.service_code) === String(payload.orderShipping.serviceCode);

          const customAttributes = String(entry.preset.id) === 'custom-box'
            ? ` data-custom-package="yes" data-custom-length="${escapeHtml(customPackage.length || entry.preset.length || '')}" data-custom-width="${escapeHtml(customPackage.width || entry.preset.width || '')}" data-custom-height="${escapeHtml(customPackage.height || entry.preset.height || '')}" data-custom-weight="${escapeHtml(customPackage.weight || entry.preset.weight || '')}"`
            : '';

          return `
          <div class="wlp-rate ${isSuggestedRate ? 'is-suggested' : ''}">
            <div class="wlp-rate__info">
              <span class="wlp-rate__name">
                ${escapeHtml(rate.service_name || rate.service_code)}
                ${isCustomerPicked ? '<span class="wlp-rate-badge">Customer picked</span>' : ''}
                ${isSuggestedRate ? '<span class="wlp-rate-badge is-suggested">Recommended service</span>' : ''}
              </span>
              <div class="wlp-rate__price">${rate.due ? `$${escapeHtml(rate.due)} CAD` : ''}</div>
              ${renderTransitEstimate(rate)}
              ${isSuggestedRate && suggested.reason ? `<div class="wlp-rate__reason">${escapeHtml(suggested.reason)}</div>` : ''}
            </div>
            <button class="button ${!payload.hasLabel && isSuggestedRate ? 'button-primary' : ''}" type="button" data-wlp-buy-label data-order-id="${escapeHtml(orderId)}" data-preset-id="${escapeHtml(entry.preset.id)}" data-service-code="${escapeHtml(rate.service_code)}" data-has-label="${payload.hasLabel ? 'yes' : 'no'}"${customAttributes}>${escapeHtml(isSuggestedRate && !payload.hasLabel ? 'Buy recommended label' : (payload.hasLabel ? (text.buyOverride || 'Review existing label') : (text.buyLabel || 'Buy label')))}</button>
          </div>
        `;
        }).join('')
        : renderError(entry.error || 'No rates returned for this package.');
      const isSuggestedPackage = customPackageActive || (payload.packageSuggestion && String(entry.preset.id) === String(payload.packageSuggestion.id));

      return `
        <section class="wlp-rate-group ${customPackageActive ? 'is-active-custom-package' : (isSuggestedPackage ? 'is-suggested-package' : '')}">
          <h3>${escapeHtml(entry.preset.name)}${customPackageActive ? '<span class="wlp-package-badge is-active">Active custom package</span>' : (isSuggestedPackage ? '<span class="wlp-package-badge">Recommended package</span>' : '')}</h3>
          ${rates}
        </section>
      `;
    }).join('');

    const packageSummary = renderLabelSummary(payload, customPackage);
    content.innerHTML = `${renderSignatureControl(orderId, signatureRequired)}${renderCardForPickupControl(orderId, cardForPickup)}${renderCustomPackageControl(orderId, customPackage)}${renderExistingLabel(payload, orderId)}${packageSummary}${groups}`;
  };

  const loadReturnRates = async (orderId, signatureRequired = signatureEnabled(), customPackage = null) => {
    drawerMode = 'return';
    setDrawerTitle(text.returnLabelTitle || 'Create Return Label');
    content.innerHTML = `

      ${renderCustomPackageControl(orderId, customPackage || {})}
      <div class="wlp-loader">
        <div class="wlp-spinner"></div>
        <p>${escapeHtml(text.loadingReturnRates || 'Loading return rates...')}</p>
      </div>
    `;

    const postData = {
      orderId,
      signatureRequired: 'no',
    };
    const customPostData = customPackagePostData(customPackage || {});
    if (customPostData) {
      Object.assign(postData, customPostData);
    }

    const result = await post('wlp_get_return_rates', postData);
    if (!result.success) {
      content.innerHTML = `

        ${renderCustomPackageControl(orderId, customPackage || {})}
        ${renderError(result.data && result.data.message ? result.data.message : (text.failedRates || 'Failed to load rates.'))}
      `;
      return;
    }

    if (result.data.hasLabel) {
      result.data.message = result.data.message || 'This order already has a return label. Reprint it or buy a replacement return label.';
    }

    renderReturnRates(orderId, result.data, signatureRequired, customPackage || {});
  };

  const renderReturnRates = (orderId, payload, signatureRequired = signatureEnabled(), customPackage = {}) => {
    const suggested = payload.suggestedRate || {};
    const customPackageActive = hasCustomPackageValues(customPackage);
    const visiblePresets = customPackageActive
      ? payload.presets.filter((entry) => String(entry.preset.id) === 'custom-box')
      : payload.presets.filter((entry) => String(entry.preset.id) !== 'custom-box');
    const groups = visiblePresets.map((entry) => {
      const rates = entry.rates.length
        ? entry.rates.map((rate) => {
          const isSuggestedPackage = suggested.presetId && String(entry.preset.id) === String(suggested.presetId);
          const isSuggestedRate = isSuggestedPackage && suggested.serviceCode && String(rate.service_code) === String(suggested.serviceCode);
          const customAttributes = String(entry.preset.id) === 'custom-box'
            ? ` data-custom-package="yes" data-custom-length="${escapeHtml(customPackage.length || entry.preset.length || '')}" data-custom-width="${escapeHtml(customPackage.width || entry.preset.width || '')}" data-custom-height="${escapeHtml(customPackage.height || entry.preset.height || '')}" data-custom-weight="${escapeHtml(customPackage.weight || entry.preset.weight || '')}"`
            : '';

          return `
          <div class="wlp-rate ${isSuggestedRate ? 'is-suggested' : ''}">
            <div class="wlp-rate__info">
              <span class="wlp-rate__name">
                ${escapeHtml(rate.service_name || rate.service_code)}
                ${isSuggestedRate ? '<span class="wlp-rate-badge is-suggested">Recommended service</span>' : ''}
              </span>
              <div class="wlp-rate__price">${rate.due ? `Estimated $${escapeHtml(rate.due)} CAD` : ''}</div>
              ${renderTransitEstimate(rate)}
              ${isSuggestedRate && suggested.reason ? `<div class="wlp-rate__reason">${escapeHtml(suggested.reason)}</div>` : ''}
            </div>
            <button class="button ${!payload.hasLabel && isSuggestedRate ? 'button-primary' : ''}" type="button" data-wlp-buy-return-label data-order-id="${escapeHtml(orderId)}" data-preset-id="${escapeHtml(entry.preset.id)}" data-service-code="${escapeHtml(rate.service_code)}" data-has-label="${payload.hasLabel ? 'yes' : 'no'}"${customAttributes}>${escapeHtml(isSuggestedRate && !payload.hasLabel ? 'Buy recommended return label' : (payload.hasLabel ? (text.buyReturnOverride || 'Buy replacement return label') : (text.buyReturnLabel || 'Buy return label')))}</button>
          </div>
        `;
	        }).join('')
	        : renderError(entry.error || 'No return rates returned for this package.');
	      const isSuggestedPackage = customPackageActive || (payload.packageSuggestion && String(entry.preset.id) === String(payload.packageSuggestion.id));

	      return `
	        <section class="wlp-rate-group ${customPackageActive ? 'is-active-custom-package' : (isSuggestedPackage ? 'is-suggested-package' : '')}">
	          <h3>${escapeHtml(entry.preset.name)}${customPackageActive ? '<span class="wlp-package-badge is-active">Active custom package</span>' : (isSuggestedPackage ? '<span class="wlp-package-badge">Recommended package</span>' : '')}</h3>
	          ${rates}
	        </section>
	      `;
    }).join('');

    const packageSummary = renderReturnSummary(payload, customPackage);
    content.innerHTML = `<p>Return rates are estimates. Final charges depend on the parcel returned. Authorized returns are separate from outbound manifests; signature selection is not supported.</p>${renderCustomPackageControl(orderId, customPackage)}${renderExistingLabel(payload, orderId)}${packageSummary}${groups}`;
  };

  document.addEventListener('input', (event) => {
    const profitInput = event.target.closest('[data-wlp-profit-input]');
    if (profitInput) {
      updateProfitPreview(profitInput.closest('[data-wlp-profit-panel]'));
      return;
    }

    const customInput = event.target.closest('[data-wlp-custom-length], [data-wlp-custom-width], [data-wlp-custom-height], [data-wlp-custom-weight]');
    if (!customInput) {
      return;
    }

    const control = customInput.closest('[data-wlp-custom-package-control]');
    if (!control || control.getAttribute('data-custom-active') !== 'yes') {
      return;
    }

    control.classList.add('is-stale');
    const status = control.querySelector('[data-wlp-custom-status]');
    const note = control.querySelector('[data-wlp-custom-note]');
    if (status) {
      status.textContent = 'Measurements changed';
    }
    if (note) {
      note.textContent = 'Refresh custom rates before buying a label with these measurements.';
    }
    content.querySelectorAll('[data-wlp-buy-label], [data-wlp-buy-return-label]').forEach((button) => {
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
    });
  });

  document.addEventListener('change', (event) => {
    const supplierOverride = event.target.closest('[data-wlp-profit-override]');
    if (!supplierOverride) return;
    const panel = supplierOverride.closest('[data-wlp-profit-panel]');
    const supplierInput = panel ? panel.querySelector('[data-wlp-profit-input="supplierCost"]') : null;
    if (!supplierInput) return;
    supplierInput.readOnly = !supplierOverride.checked;
    if (!supplierOverride.checked) supplierInput.value = panel.getAttribute('data-auto-supplier-cost') || '0.00';
    updateProfitPreview(panel);
  });

  const combinedSourceProducts = rows => {
    const products = new Map();
    rows.forEach(row => {
      const key = String(row.name).trim().replace(/\s+/g, ' ').toLowerCase();
      if (!products.has(key)) products.set(key, {key, name:row.name, unit:row.unit || 'vials', quantity:0, assigned:0, remaining:0, allocations:{jkl:0,xp:0,hamilton:0}, orders:new Set(), rows:[]});
      const product = products.get(key);
      const assigned = Object.values(row.allocations).reduce((sum,value) => sum + Number(value || 0), 0);
      product.quantity += row.quantity; product.assigned += assigned;
      ['jkl','xp','hamilton'].forEach(source => { product.allocations[source] += Number(row.allocations[source] || 0); });
      product.remaining += Math.max(0, row.quantity - assigned); product.orders.add(row.orderId); product.rows.push(row);
    });
    return [...products.values()];
  };
  const sourceRowsInBatch = () => [...content.querySelectorAll('[data-wlp-source-batch-editor] [data-wlp-source-line]')].map(element => ({
    element, name:element.dataset.productName, unit:element.dataset.productUnit || 'vials', orderId:element.closest('[data-wlp-sourcing]').dataset.orderId,
    quantity:Number(element.dataset.quantity), allocations:Object.fromEntries([...element.querySelectorAll('[data-wlp-source-qty]')].map(input => [input.dataset.wlpSourceQty, Number(input.value)]))
  }));
  const refreshCombinedSources = () => {
    const target = content.querySelector('[data-wlp-combined-products]');
    if (!target) return;
    const products = combinedSourceProducts(sourceRowsInBatch());
    target.innerHTML = `<h3>Assign by product</h3><p>Choose a source once for each product. All remaining quantities are allocated across the selected orders. Existing allocations stay unchanged.</p>${products.map((product,index) => `<section class="wlp-sourcing-line" data-combined-product="${index}"><strong>${escapeHtml(product.name)} — ${product.quantity} ${escapeHtml(product.unit)} needed</strong><p>${product.assigned} assigned · ${product.remaining} unassigned · ${product.orders.size} order(s)</p><p>${Object.entries(product.allocations).filter(([,quantity]) => quantity > 0).map(([source,quantity]) => `${source === 'hamilton' ? 'Hamilton' : source.toUpperCase()}: ${quantity}`).join(' · ') || 'No source assigned yet'}</p><div class="wlp-sourcing-actions">${['xp','jkl','hamilton'].map(source => `<button class="button" type="button" data-wlp-assign-product="${index}" data-source="${source}" ${product.remaining ? '' : 'disabled'}>Assign remaining to ${source === 'hamilton' ? 'Hamilton' : source.toUpperCase()}</button>`).join('')}</div></section>`).join('')}`;
  };
  const sourcePickQuantity = (limit, quantities, source, checked) => checked
    ? Math.max(0, limit - Object.entries(quantities).filter(([key]) => key !== source).reduce((sum, [, value]) => sum + Math.max(0, Number(value) || 0), 0)) : 0;
  const updateSourceRow = row => {
    let total = 0;
    row.querySelectorAll('[data-wlp-source-qty]').forEach(input => {
      total += Number(input.value) || 0;
      row.querySelector(`[data-wlp-source-pick="${input.dataset.wlpSourceQty}"]`).checked = Number(input.value) > 0;
    });
    const remaining = Number(row.dataset.quantity) - total;
    row.querySelector('[data-wlp-source-remaining]').textContent = remaining >= 0 ? `${remaining} unassigned` : 'Assigned quantities exceed this order line.';
  };
  document.addEventListener('change', event => {
    const pick = event.target.closest('[data-wlp-source-pick]');
    if (!pick) return;
    const row = pick.closest('[data-wlp-source-line]');
    const quantities = Object.fromEntries([...row.querySelectorAll('[data-wlp-source-qty]')].map(input => [input.dataset.wlpSourceQty, Number(input.value)]));
    const input = row.querySelector(`[data-wlp-source-qty="${pick.dataset.wlpSourcePick}"]`);
    input.value = sourcePickQuantity(Number(row.dataset.quantity), quantities, pick.dataset.wlpSourcePick, pick.checked);
    input.dispatchEvent(new Event('input', {bubbles:true}));
    if (pick.checked && !Number(input.value)) pick.checked = false;
  });

  document.addEventListener('input', (event) => {
    if (!event.target.matches('[data-wlp-source-qty]')) return;
    const sourcing = event.target.closest('[data-wlp-sourcing]');
    if (!sourcing) return;
    const status = sourcing.querySelector('[data-wlp-sourcing-status]');
    sourcing.dataset.dirty = 'yes';
    const row = event.target.closest('[data-wlp-source-line]');
    if (row) updateSourceRow(row);
    if (status) status.textContent = 'Unsaved source changes. Save the plan before using a supplier message.';
    sourcing.querySelectorAll('[data-wlp-sourcing-message] button').forEach((button) => { button.disabled = true; });
    if (sourcing.closest('[data-wlp-source-batch-editor]')) {
      content.querySelectorAll('[data-wlp-batch-message] button').forEach(button => { button.disabled = true; });
      refreshCombinedSources();
    }
  });

  const sourcePlanValues = panel => Object.fromEntries([...panel.querySelectorAll('[data-wlp-source-line]')].map(line => {
    const values = Object.fromEntries([...line.querySelectorAll('[data-wlp-source-qty]')].map(input => [input.dataset.wlpSourceQty, Number(input.value)]));
    if (Object.values(values).some(value => !Number.isInteger(value) || value < 0) || Object.values(values).reduce((a,b) => a+b,0) > Number(line.dataset.quantity)) throw new Error('Use whole vial quantities that do not exceed each order line.');
    return [line.dataset.key, values];
  }));
  document.addEventListener('click', async event => {
    const assignProduct = event.target.closest('[data-wlp-assign-product]');
    if (assignProduct) {
      const product = combinedSourceProducts(sourceRowsInBatch())[Number(assignProduct.dataset.wlpAssignProduct)];
      const source = assignProduct.dataset.source;
      if (!product || !['jkl','xp','hamilton'].includes(source)) return;
      product.rows.forEach(row => {
        const input = row.element.querySelector(`[data-wlp-source-qty="${source}"]`);
        const quantity = sourcePickQuantity(row.quantity, row.allocations, source, true);
        if (quantity > Number(input.value)) { input.value = quantity; input.dispatchEvent(new Event('input', {bubbles:true})); }
      });
      content.querySelector('[data-wlp-source-batch-status]').textContent = `${product.name}: ${product.remaining} ${product.unit} assigned to ${source.toUpperCase()} across ${product.orders.size} order(s). Review and save all to keep this plan.`;
      return;
    }
    const fill = event.target.closest('[data-wlp-bulk-source]');
    if (fill) {
      const source = fill.dataset.wlpBulkSource;
      content.querySelectorAll('[data-wlp-source-line]').forEach(row => {
        const input = row.querySelector(`[data-wlp-source-qty="${source}"]`);
        const quantities = Object.fromEntries([...row.querySelectorAll('[data-wlp-source-qty]')].map(input => [input.dataset.wlpSourceQty, Number(input.value)]));
        const quantity = sourcePickQuantity(Number(row.dataset.quantity), quantities, source, true);
        if (quantity > Number(input.value)) { input.value = quantity; input.dispatchEvent(new Event('input', {bubbles:true})); }
      });
      return;
    }
    const save = event.target.closest('[data-wlp-save-source-batch]');
    if (!save) return;
    const panels = [...content.querySelectorAll('[data-wlp-source-batch-editor] [data-wlp-sourcing]')];
    const status = content.querySelector('[data-wlp-source-batch-status]');
    let plans;
    try { plans = panels.map(panel => ({panel, orderId:panel.dataset.orderId, allocations:sourcePlanValues(panel)})); }
    catch (error) { status.textContent = error.message; return; }
    const inputs = [...content.querySelectorAll('[data-wlp-source-batch-editor] input, [data-wlp-source-batch-editor] button')];
    inputs.forEach(input => { input.disabled = true; });
    const saved = [];
    try {
      for (const plan of plans) {
        const result = await post('wlp_wholesale_save_sources', {orderId:plan.orderId, allocations:JSON.stringify(plan.allocations), expectedAllocations:plan.panel.dataset.expectedSources});
        if (!result.success) throw new Error(`Order #${plan.orderId}: ${result.data?.message || 'Save failed.'}`);
        saved.push(plan.orderId); plan.panel.dataset.expectedSources = JSON.stringify(plan.allocations); delete plan.panel.dataset.dirty;
        status.textContent = `Saved ${saved.length} of ${plans.length} orders…`;
      }
      await loadBatchSourcing('send');
    } catch (error) { status.textContent = `${error.message} ${saved.length ? 'Confirmed saved orders: #' + saved.join(', #') + '. ' : ''}Reopen the batch to check remaining orders before trying again.`; }
    finally { inputs.forEach(input => { input.disabled = false; }); }
  });

  document.addEventListener('click', async (event) => {
    const phaseButton = event.target.closest('[data-wlp-batch-phase-button]');
    if (phaseButton) { if (content.querySelector('[data-wlp-sourcing][data-dirty="yes"]') && phaseButton.dataset.wlpBatchPhaseButton !== 'assign') { window.alert('Save your source changes before continuing.'); return; } showBatchPhase(phaseButton.dataset.wlpBatchPhaseButton); return; }
    const batchCopy = event.target.closest('[data-wlp-batch-message] [data-wlp-copy-sourcing]');
    if (batchCopy) {
      const message = batchCopy.closest('[data-wlp-batch-message]');
      const status = message.querySelector('[data-wlp-batch-status]');
      try { await copyTextToClipboard(message.querySelector('textarea').value); status.textContent = 'Copied. Nothing was sent or marked received.'; }
      catch (error) { status.textContent = error.message; }
      return;
    }
    const batchStep = event.target.closest('[data-wlp-batch-step]');
    if (batchStep) {
      const message = batchStep.closest('[data-wlp-batch-message]');
      if (!message) return;
      batchStep.disabled = true;
      try {
        const result = await post('wlp_wholesale_batch_supplier_status', { orderIds: JSON.stringify((message.getAttribute('data-order-ids') || '').split(',').map(Number).filter(Boolean)), source: message.getAttribute('data-source'), mode: message.getAttribute('data-mode'), step: batchStep.getAttribute('data-wlp-batch-step'), draftVersion: '5', expectedTokens: message.dataset.expectedTokens || '{}', batchKey: message.dataset.batchKey || '' });
        if (!result.success) throw new Error(result.data?.message || 'Could not save batch supplier status.');
        (result.data.orders || []).forEach(order => {
          const card = [...document.querySelectorAll('[data-wlp-order-card]')].find(item => Number(item.dataset.orderId) === Number(order.orderId));
          if (!card) return;
          card.dataset.wholesaleWorkflowStage = order.workflow.stage || card.dataset.wholesaleWorkflowStage;
          const label = card.querySelector('[data-wlp-card-supplier-status]'), next = card.querySelector('[data-wlp-card-supplier-next]');
          if (label) label.textContent = order.workflow.label;
          if (next) next.textContent = `Next: ${order.workflow.task}`;
        });
        await loadBatchSourcing(batchStep.dataset.wlpBatchStep === 'received' ? '' : message.dataset.mode === 'order' ? 'receive' : 'send');
      } catch (error) { const status = message.querySelector('[data-wlp-batch-status]'); if (status) status.textContent = error.message; batchStep.disabled = false; }
      return;
    }
	const sourcing = event.target.closest('[data-wlp-sourcing]');
	if (sourcing) {
	  const orderId = sourcing.getAttribute('data-order-id') || '';
	  const status = sourcing.querySelector('[data-wlp-sourcing-status]');
	  const save = event.target.closest('[data-wlp-save-sourcing]');
	  const copy = event.target.closest('[data-wlp-copy-sourcing]');
	  const stepButton = event.target.closest('[data-wlp-sourcing-step]');
	  if (save) {
		const allocations = {};
		let valid = true;
		sourcing.querySelectorAll('[data-wlp-source-line]').forEach((line) => {
		  const key = line.getAttribute('data-key');
		  const limit = Number(line.getAttribute('data-quantity'));
		  const row = {};
		  line.querySelectorAll('[data-wlp-source-qty]').forEach((input) => {
			const value = Number(input.value);
			if (!Number.isInteger(value) || value < 0) valid = false;
			row[input.getAttribute('data-wlp-source-qty')] = value;
		  });
		  if (Object.values(row).reduce((sum, value) => sum + value, 0) > limit) valid = false;
		  allocations[key] = row;
		});
		if (!valid) { if (status) status.textContent = 'Use whole vial quantities that do not exceed each order line.'; return; }
		save.disabled = true;
		try {
		  const result = await post('wlp_wholesale_save_sources', { orderId, allocations: JSON.stringify(allocations), expectedAllocations:sourcing.dataset.expectedSources });
		  if (!result.success) throw new Error(result.data?.message || 'Could not save source plan.');
          if (save.hasAttribute('data-add-to-batch')) {
            const card = [...document.querySelectorAll('[data-wlp-order-card]')].find(card => card.getAttribute('data-order-id') === orderId);
            if (card) setCardSelected(card, true);
            updateSelectionCount();
          }
		  sourcing.outerHTML = sourcingHtml(orderId, result.data.sourcing);
          if (save.hasAttribute('data-add-to-batch')) content.querySelector('[data-wlp-sourcing-status]').textContent = `Order #${orderId} added. Open another card to add more, or view the combined supplier order.`;
		} catch (error) { if (status) status.textContent = error.message; save.disabled = false; }
		return;
	  }
	  if (copy || stepButton) {
		const message = event.target.closest('[data-wlp-sourcing-message]');
		if (!message) return;
		if (copy) {
		  try { await copyTextToClipboard(message.querySelector('textarea')?.value || ''); if (status) status.textContent = 'Copied. Send it in the supplier chat, then record that it was sent.'; }
		  catch (error) { if (status) status.textContent = error.message; }
		  return;
		}
		stepButton.disabled = true;
		try {
		  const result = await post('wlp_wholesale_supplier_status', { orderId, source: message.getAttribute('data-source'), mode: message.getAttribute('data-mode'), hash: message.getAttribute('data-hash'), step: stepButton.getAttribute('data-wlp-sourcing-step') });
		  if (!result.success) throw new Error(result.data?.message || 'Could not save supplier status.');
		  sourcing.outerHTML = sourcingHtml(orderId, result.data.sourcing);
		} catch (error) { if (status) status.textContent = error.message; stepButton.disabled = false; }
		return;
	  }
	}
	const saveWholesaleNotesButton = event.target.closest('[data-wlp-save-wholesale-notes]');
	if (saveWholesaleNotesButton) {
	  const panel = saveWholesaleNotesButton.closest('[data-wlp-wholesale-notes]');
	  const field = panel ? panel.querySelector('[data-wlp-wholesale-notes-field]') : null;
	  const status = panel ? panel.querySelector('[data-wlp-wholesale-notes-status]') : null;
	  const orderId = saveWholesaleNotesButton.getAttribute('data-order-id') || '';
	  const originalText = saveWholesaleNotesButton.textContent;

	  saveWholesaleNotesButton.disabled = true;
	  saveWholesaleNotesButton.textContent = 'Saving...';
	  if (status) status.textContent = 'Saving private notes...';

	  let result;
	  try {
		result = await post('wlp_wholesale_save_notes', {
		  orderId,
		  notes: field ? field.value : '',
		});
	  } catch (error) {
		const message = 'Failed to save private quote notes.';
		saveWholesaleNotesButton.disabled = false;
		saveWholesaleNotesButton.textContent = originalText;
		if (status) status.textContent = message;
		window.alert(message);
		return;
	  }

	  saveWholesaleNotesButton.disabled = false;
	  saveWholesaleNotesButton.textContent = originalText;

	  if (!result.success) {
		const message = result.data && result.data.message ? result.data.message : 'Failed to save private quote notes.';
		if (status) status.textContent = message;
		window.alert(message);
		return;
	  }

	  const notes = result.data && typeof result.data.notes === 'string' ? result.data.notes : '';
	  const updatedAt = result.data && result.data.updatedAt ? result.data.updatedAt : '';
	  document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId)}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId)}"]`).forEach((surface) => {
		const notesPanel = surface.querySelector('[data-wlp-wholesale-notes]');
		const notesField = notesPanel ? notesPanel.querySelector('[data-wlp-wholesale-notes-field]') : null;
		const notesStatus = notesPanel ? notesPanel.querySelector('[data-wlp-wholesale-notes-status]') : null;
		if (notesField) notesField.value = notes;
		if (notesStatus) notesStatus.textContent = updatedAt ? `Private. Saved ${updatedAt}. Never emailed to the customer.` : 'Private. Never emailed to the customer.';
	  });
	  bulkStatus(result.data && result.data.message ? result.data.message : 'Private quote notes saved.');
	  return;
	}

    const wholesaleFilterButton = event.target.closest('[data-wlp-wholesale-filter]');
    if (wholesaleFilterButton) {
      const filter = wholesaleFilterButton.getAttribute('data-wlp-wholesale-filter') || 'all';
      const stages = {
        action: ['stock_check', 'final_quote', 'supplier_order', 'ready_to_ship'],
        waiting: ['awaiting_customer', 'awaiting_payment', 'inbound_hamilton'],
        ready: ['ready_to_ship'],
      };
      document.querySelectorAll('[data-wlp-wholesale-filter]').forEach((button) => button.classList.toggle('is-active', button === wholesaleFilterButton));
      document.querySelectorAll('[data-wlp-order-card][data-wholesale-workflow-stage]').forEach((card) => {
        const stage = card.getAttribute('data-wholesale-workflow-stage') || '';
        card.hidden = filter !== 'all' && !(stages[filter] || []).includes(stage);
      });
      return;
    }

    const copyWholesaleButton = event.target.closest('[data-wlp-copy-wholesale]');
    if (copyWholesaleButton) {
      const surface = copyWholesaleButton.closest('.wlp-wholesale-summary');
      const source = surface ? surface.querySelector('[data-wlp-wholesale-copy-source]') : null;
      const originalText = copyWholesaleButton.textContent;

      try {
        await copyTextToClipboard(source ? source.value : '');
        copyWholesaleButton.textContent = 'Copied for WhatsApp';
      } catch (error) {
        copyWholesaleButton.textContent = 'Copy failed';
      }

      window.setTimeout(() => {
        copyWholesaleButton.textContent = originalText;
      }, 1800);
      return;
    }

    const pricingModal = document.querySelector('[data-wlp-pricing-modal]');
    const openPricingButton = event.target.closest('[data-wlp-open-pricing]');
    if (openPricingButton && pricingModal) {
      pricingModal.hidden = false;
      document.body.classList.add('wlp-has-modal');
      updatePricingModal(pricingModal);
      updatePricingSaveState(pricingModal);
      const search = pricingModal.querySelector('[data-wlp-pricing-search]');
      if (search) search.focus();
      return;
    }

    const closePricingButton = event.target.closest('[data-wlp-close-pricing]');
    if ((closePricingButton || event.target === pricingModal) && pricingModal) {
      pricingModal.hidden = true;
      document.body.classList.remove('wlp-has-modal');
      return;
    }

    const addPricingButton = event.target.closest('[data-wlp-pricing-add]');
    if (addPricingButton && pricingModal) {
      const nameInput = pricingModal.querySelector('[data-wlp-pricing-new-name]');
      const costInput = pricingModal.querySelector('[data-wlp-pricing-new-cost]');
      const name = nameInput ? nameInput.value.trim() : '';
      const cost = costInput ? Number(costInput.value || 0) : 0;
      const key = normalizeSupplierKey(name);
      const existingRow = key ? pricingModal.querySelector(`[data-wlp-pricing-row][data-supplier-key="${cssEscape(key)}"]`) : null;
      const status = pricingModal.querySelector('[data-wlp-pricing-status]');

      if (!name || !key || cost <= 0) {
        if (status) status.textContent = 'Enter an exact product name, strength, and supplier cost greater than zero.';
        return;
      }

      if (existingRow) {
        const existingCost = existingRow.querySelector('[data-wlp-catalog-supplier-cost]');
        if (existingCost) {
          existingCost.value = cost.toFixed(2);
          existingCost.focus();
        }
        existingRow.hidden = false;
      } else {
        const row = document.createElement('tr');
        row.setAttribute('data-wlp-pricing-row', '');
        row.setAttribute('data-supplier-key', key);
        row.setAttribute('data-wholesale-price', '');
        row.setAttribute('data-search', name.toLowerCase());
        row.innerHTML = `
          <th>${escapeHtml(name)}</th>
          <td><label class="wlp-pricing-cost-input"><span aria-hidden="true">C$</span><input type="number" min="0.01" max="100000" step="0.01" inputmode="decimal" data-wlp-catalog-supplier-cost data-original-cost="0.00" value="${escapeHtml(cost.toFixed(2))}" aria-label="Supplier cost per 10-vial kit for ${escapeHtml(name)}"></label></td>
          <td>Not listed</td><td data-wlp-pricing-current-margin>—</td><td data-wlp-pricing-floor>—</td><td><span class="wlp-pricing-signal is-missing" data-wlp-pricing-signal>Cost only</span></td><td>Not listed</td>
        `;
        const body = pricingModal.querySelector('.wlp-pricing-modal__table tbody');
        if (body) body.prepend(row);
      }

      if (nameInput) nameInput.value = '';
      if (costInput) costInput.value = '';
      updatePricingModal(pricingModal);
      updatePricingSaveState(pricingModal);
      return;
    }

    const savePricingButton = event.target.closest('[data-wlp-pricing-save]');
    if (savePricingButton && pricingModal) {
      const status = pricingModal.querySelector('[data-wlp-pricing-status]');
      const changedInputs = [...pricingModal.querySelectorAll('[data-wlp-catalog-supplier-cost]')].filter((input) => {
        const original = Number(input.getAttribute('data-original-cost') || 0);
        return Number(input.value || 0) > 0 && Math.abs(Number(input.value || 0) - original) >= 0.005;
      });
      const entries = changedInputs.map((input) => {
        const row = input.closest('[data-wlp-pricing-row]');
        return {
          key: row ? row.getAttribute('data-supplier-key') || '' : '',
          name: row && row.querySelector('th') ? row.querySelector('th').textContent.trim() : '',
          cost: input.value.trim(),
        };
      });

      if (!entries.length) {
        if (status) status.textContent = 'No supplier-cost changes to save.';
        updatePricingSaveState(pricingModal);
        return;
      }

      const originalText = savePricingButton.textContent;
      savePricingButton.disabled = true;
      savePricingButton.textContent = 'Saving...';
      if (status) status.textContent = 'Saving private supplier costs...';

      let result;
      try {
        result = await post('wlp_save_supplier_cost_catalog', { entries: JSON.stringify(entries) });
      } catch (error) {
        result = { success: false, data: { message: 'Failed to save supplier costs.' } };
      }

      savePricingButton.textContent = originalText;
      if (!result.success) {
        if (status) status.textContent = result.data && result.data.message ? result.data.message : 'Failed to save supplier costs.';
        updatePricingSaveState(pricingModal);
        return;
      }

      changedInputs.forEach((input) => input.setAttribute('data-original-cost', Number(input.value || 0).toFixed(2)));
      if (status) status.textContent = result.data && result.data.message ? result.data.message : 'Supplier costs saved.';
      updatePricingModal(pricingModal);
      updatePricingSaveState(pricingModal);
      return;
    }

    const saveSupplierCostsButton = event.target.closest('[data-wlp-save-supplier-costs]');
    if (saveSupplierCostsButton) {
      const panel = saveSupplierCostsButton.closest('[data-wlp-profit-panel]');
      const status = panel ? panel.querySelector('[data-wlp-supplier-cost-status]') : null;
      const entries = panel ? [...panel.querySelectorAll('[data-wlp-supplier-cost]')]
        .map((input) => ({
          key: input.getAttribute('data-supplier-cost-key') || '',
          cost: input.value.trim(),
        }))
        .filter((entry) => entry.key && Number(entry.cost) > 0) : [];

      if (!entries.length) {
        const message = 'Enter at least one supplier cost greater than zero.';
        if (status) status.textContent = message;
        window.alert(message);
        return;
      }

      const originalText = saveSupplierCostsButton.textContent;
      const values = profitPanelValues(panel);
      const notes = panel ? panel.querySelector('[data-wlp-profit-notes]') : null;
      const supplierOverride = panel ? panel.querySelector('[data-wlp-profit-override]') : null;
      saveSupplierCostsButton.disabled = true;
      saveSupplierCostsButton.textContent = 'Saving...';
      if (status) status.textContent = 'Saving permanent supplier costs...';

      let result;
      try {
        result = await post('wlp_save_supplier_costs', {
          orderId: saveSupplierCostsButton.getAttribute('data-order-id'),
          costs: JSON.stringify(entries),
        });
      } catch (error) {
        const message = 'Failed to save permanent supplier costs.';
        saveSupplierCostsButton.disabled = false;
        saveSupplierCostsButton.textContent = originalText;
        if (status) status.textContent = message;
        window.alert(message);
        return;
      }

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Failed to save permanent supplier costs.';
        saveSupplierCostsButton.disabled = false;
        saveSupplierCostsButton.textContent = originalText;
        if (status) status.textContent = message;
        window.alert(message);
        return;
      }

      const refreshedProfit = {
        ...(result.data && result.data.profit ? result.data.profit : {}),
        actualRevenue: values.revenue.toFixed(2),
        shippingCost: values.shippingCost.toFixed(2),
        paymentFees: values.paymentFees.toFixed(2),
        taxCost: values.taxCost.toFixed(2),
        otherCosts: values.otherCosts.toFixed(2),
        notes: notes ? notes.value : '',
      };
      if (supplierOverride && supplierOverride.checked) {
        refreshedProfit.supplierCostMode = 'manual';
        refreshedProfit.supplierCost = values.supplierCost.toFixed(2);
      }

      const template = document.createElement('template');
      template.innerHTML = renderProfitPanel(saveSupplierCostsButton.getAttribute('data-order-id'), refreshedProfit).trim();
      const refreshedPanel = template.content.firstElementChild;
      if (panel && refreshedPanel) {
        panel.replaceWith(refreshedPanel);
        updateProfitPreview(refreshedPanel);
        const refreshedStatus = refreshedPanel.querySelector('[data-wlp-supplier-cost-status]');
        if (refreshedStatus) refreshedStatus.textContent = result.data.message || 'Permanent supplier costs saved.';
      }
      bulkStatus(result.data && result.data.message ? result.data.message : 'Permanent supplier costs saved.');
      return;
    }

    const saveProfitButton = event.target.closest('[data-wlp-save-profit]');
    if (saveProfitButton) {
      const panel = saveProfitButton.closest('[data-wlp-profit-panel]');
      const values = profitPanelValues(panel);
      const status = panel ? panel.querySelector('[data-wlp-profit-status]') : null;
      const notes = panel ? panel.querySelector('[data-wlp-profit-notes]') : null;
      const supplierOverride = panel ? panel.querySelector('[data-wlp-profit-override]') : null;
      const originalText = saveProfitButton.textContent;

      saveProfitButton.disabled = true;
      saveProfitButton.textContent = 'Saving...';
      if (status) status.textContent = 'Saving internal calculation...';

      const result = await post('wlp_save_profit', {
        orderId: saveProfitButton.getAttribute('data-order-id'),
        actualRevenue: values.revenue.toFixed(2),
        supplierCost: values.supplierCost.toFixed(2),
        supplierCostMode: supplierOverride && supplierOverride.checked ? 'manual' : 'auto',
        shippingCost: values.shippingCost.toFixed(2),
        paymentFees: values.paymentFees.toFixed(2),
        taxCost: values.taxCost.toFixed(2),
        otherCosts: values.otherCosts.toFixed(2),
        notes: notes ? notes.value : '',
      });

      saveProfitButton.disabled = false;
      saveProfitButton.textContent = originalText;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Failed to save the profit calculation.';
        if (status) status.textContent = message;
        window.alert(message);
        return;
      }

      if (status) status.textContent = result.data && result.data.profit && result.data.profit.updatedAt
        ? `Saved ${result.data.profit.updatedAt}`
        : 'Saved';
      updateProfitPreview(panel);
      bulkStatus(result.data && result.data.message ? result.data.message : 'Internal profit calculation saved.');
      return;
    }

    const prelabelDoneButton = event.target.closest('[data-wlp-prelabel-done]');
    if (prelabelDoneButton) {
      markPrelabelRowDone(prelabelDoneButton.closest('[data-wlp-prelabel-row]'));
      return;
    }

    const prelabelResetButton = event.target.closest('[data-wlp-prelabel-reset]');
    if (prelabelResetButton) {
      resetPrelabelRows();
      return;
    }

    const startUnpackedBatchButton = event.target.closest('[data-wlp-start-unpacked-batch]');
    if (startUnpackedBatchButton) {
      orderCards().forEach((card) => setCardSelected(card, card.getAttribute('data-packed') !== 'yes'));
      updateSelectionCount();

      const selected = selectedCards();
      if (!selected.length) {
        bulkStatus('There are no unpacked orders in this tab.');
        return;
      }

      bulkStatus(`${selected.length} unpacked orders selected. Review the label options to continue.`);
      const quickBuyButton = document.querySelector('[data-wlp-quick-buy-selected]');
      if (quickBuyButton) {
        quickBuyButton.click();
      }
      return;
    }

    const selectAllButton = event.target.closest('[data-wlp-select-all]');
    if (selectAllButton) {
      orderCards().forEach((card) => setCardSelected(card, true));
      updateSelectionCount();
      bulkStatus(`${selectedCards().length} orders selected.`);
      return;
    }

    const selectLabeledButton = event.target.closest('[data-wlp-select-labeled]');
    if (selectLabeledButton) {
      orderCards().forEach((card) => setCardSelected(card, card.getAttribute('data-has-label') === 'yes'));
      updateSelectionCount();
      bulkStatus(`${selectedCards().length} labeled orders selected.`);
      return;
    }

    const selectPackedButton = event.target.closest('[data-wlp-select-packed]');
    if (selectPackedButton) {
      orderCards().forEach((card) => setCardSelected(card, card.getAttribute('data-packed') === 'yes'));
      updateSelectionCount();
      bulkStatus(`${selectedCards().length} packed orders selected.`);
      return;
    }

    const selectUnpackedButton = event.target.closest('[data-wlp-select-unpacked]');
    if (selectUnpackedButton) {
      orderCards().forEach((card) => setCardSelected(card, card.getAttribute('data-packed') !== 'yes'));
      updateSelectionCount();
      bulkStatus(`${selectedCards().length} unpacked orders selected.`);
      return;
    }

    const selectUnpackedLabeledButton = event.target.closest('[data-wlp-select-unpacked-labeled]');
    if (selectUnpackedLabeledButton) {
      orderCards().forEach((card) => {
        setCardSelected(card, card.getAttribute('data-packed') !== 'yes' && card.getAttribute('data-has-label') === 'yes');
      });
      updateSelectionCount();
      bulkStatus(`${selectedCards().length} unpacked labeled orders selected.`);
      return;
    }

    const selectPackedLabeledButton = event.target.closest('[data-wlp-select-packed-labeled]');
    if (selectPackedLabeledButton) {
      orderCards().forEach((card) => {
        setCardSelected(card, card.getAttribute('data-packed') === 'yes' && card.getAttribute('data-has-label') === 'yes');
      });
      updateSelectionCount();
      bulkStatus(`${selectedCards().length} packed labeled orders selected.`);
      return;
    }

    const clearSelectionButton = event.target.closest('[data-wlp-clear-selection]');
    if (clearSelectionButton) {
      orderCheckboxes().forEach((checkbox) => {
        checkbox.checked = false;
      });
      updateSelectionCount();
      bulkStatus('Selection cleared.');
      return;
    }

    const closeButton = event.target.closest('[data-wlp-close]');
    if (closeButton) {
      if (!(await confirmLeaveUnpackedQuickView())) {
        return;
      }

      closeDrawer();
      return;
    }

    const batchSourcingButton = event.target.closest('button[data-wlp-batch-sourcing], [data-wlp-open-source-batch]');
    if (batchSourcingButton) {
      if (content.querySelector('[data-wlp-sourcing][data-dirty="yes"]')) { window.alert('Save the source plan before viewing the combined supplier order.'); return; }
      await loadBatchSourcing();
      return;
    }

    const sourcingButton = event.target.closest('[data-wlp-open-sourcing]');
    if (sourcingButton) {
      const orderId = sourcingButton.getAttribute('data-order-id');
      openDrawer();
      await loadQuickView(orderId);
      const details = content.querySelector('[data-wlp-sourcing-details]');
      if (details) {
        details.open = true;
        details.scrollIntoView({ block: 'start' });
      }
      return;
    }

    const quickViewButton = event.target.closest('[data-wlp-quick-view]');
    if (quickViewButton) {
      const orderId = quickViewButton.getAttribute('data-order-id');
      openDrawer();
      await loadQuickView(orderId);
      return;
    }

    const quickNavButton = event.target.closest('[data-wlp-quick-nav]');
    if (quickNavButton) {
      const orderId = quickNavButton.getAttribute('data-order-id');
      if (!orderId || quickNavButton.disabled) {
        return;
      }

      if (!(await confirmLeaveUnpackedQuickView())) {
        return;
      }

      await loadQuickView(orderId);
      return;
    }

    const printInvoiceLink = event.target.closest('[data-wlp-print-invoice]');
    if (printInvoiceLink) {
      const surface = orderSurface(printInvoiceLink);
      if (!confirmPackedAction(surface, text.confirmPackedInvoice || 'This order is already marked packed. Are you sure you want to print the invoice/guide?')) {
        event.preventDefault();
        return;
      }

      updateCardAfterInvoicePrinted(surface);
      return;
    }

    const printLabelLink = event.target.closest('[data-wlp-print-label]');
    if (printLabelLink) {
      const surface = orderSurface(printLabelLink) || surfaceForOrderId(printLabelLink.getAttribute('data-order-id'));
      if (!confirmPackedAction(surface, text.confirmPackedLabel || 'This order is already marked packed. Are you sure you want to print or create a label?')) {
        event.preventDefault();
      }
      return;
    }

    const createButton = event.target.closest('[data-wlp-create-label]');
    if (createButton) {
      const surface = orderSurface(createButton);
      if (!confirmPackedAction(surface, text.confirmPackedLabel || 'This order is already marked packed. Are you sure you want to print or create a label?')) {
        return;
      }

      const orderId = createButton.getAttribute('data-order-id');
      const defaults = deliveryProtectionDefaults(surface);
      openDrawer();
      await loadRates(orderId, defaults.signature, defaults.cardForPickup);
      return;
    }

    const createReturnButton = event.target.closest('[data-wlp-create-return-label]');
    if (createReturnButton) {
      const orderId = createReturnButton.getAttribute('data-order-id');
      openDrawer();
      await loadReturnRates(orderId, wlpAdmin.signatureRequired === 'yes');
      return;
    }

    const customRatesButton = event.target.closest('[data-wlp-custom-rates]');
    if (customRatesButton) {
      const orderId = customRatesButton.getAttribute('data-order-id') || '';
      const customPackage = customPackageFromForm();
      if (!hasCustomPackageValues(customPackage)) {
        window.alert('Enter length, width, height, and weight before getting custom rates.');
        return;
      }

      if (drawerMode === 'return') {
        await loadReturnRates(orderId, signatureEnabled(), customPackage);
      } else {
        await loadRates(orderId, signatureEnabled(), cardForPickupEnabled(), customPackage);
      }
      return;
    }

    const savedRatesButton = event.target.closest('[data-wlp-use-saved-rates]');
    if (savedRatesButton) {
      const orderId = savedRatesButton.getAttribute('data-order-id') || '';
      if (drawerMode === 'return') {
        await loadReturnRates(orderId, signatureEnabled(), null);
      } else {
        await loadRates(orderId, signatureEnabled(), cardForPickupEnabled(), null);
      }
      return;
    }

    const buyButton = event.target.closest('[data-wlp-buy-label]');
    if (buyButton) {
      if (buyButton.disabled) {
        return;
      }
      const hasLabel = buyButton.getAttribute('data-has-label') === 'yes';
      const buyOrderId = buyButton.getAttribute('data-order-id') || '';
      if (!confirmPackedAction(surfaceForOrderId(buyOrderId), text.confirmPackedLabel || 'This order is already marked packed. Are you sure you want to print or create a label?')) {
        return;
      }

      if (hasLabel) {
        window.location.assign(wlpAdmin.manifestsUrl);
        return;
      }

      if (buyButton.getAttribute('data-custom-package') === 'yes') {
        const dimensions = `${buyButton.getAttribute('data-custom-length')} × ${buyButton.getAttribute('data-custom-width')} × ${buyButton.getAttribute('data-custom-height')} cm`;
        const weight = `${buyButton.getAttribute('data-custom-weight')} kg`;
        if (!window.confirm(`Use this custom package for the label?\n\n${dimensions}\n${weight}\n\nConfirm the packed box matches these measurements.`)) {
          return;
        }
      }

      buyButton.disabled = true;
      buyButton.textContent = text.buying || 'Buying...';

      const labelPostData = {
        orderId: buyOrderId,
        presetId: buyButton.getAttribute('data-preset-id'),
        serviceCode: buyButton.getAttribute('data-service-code'),
        override: hasLabel ? 'yes' : 'no',
        signatureRequired: signatureEnabled() ? 'yes' : 'no',
        cardForPickup: cardForPickupEnabled() ? 'yes' : 'no',
      };

      if (buyButton.getAttribute('data-custom-package') === 'yes') {
        Object.assign(labelPostData, {
          customPackage: 'yes',
          customLength: buyButton.getAttribute('data-custom-length') || '',
          customWidth: buyButton.getAttribute('data-custom-width') || '',
          customHeight: buyButton.getAttribute('data-custom-height') || '',
          customWeight: buyButton.getAttribute('data-custom-weight') || '',
        });
      }

      const result = await post('wlp_create_label', labelPostData);

      if (!result.success) {
        buyButton.disabled = false;
        buyButton.textContent = hasLabel ? (text.buyOverride || 'Review existing label') : (text.buyLabel || 'Buy label');

        if (result.data && result.data.code === 'label_exists' && result.data.printUrl) {
          content.innerHTML = `
            <p>${escapeHtml(result.data.message)}</p>
            <a class="button button-primary" target="_blank" href="${escapeHtml(result.data.printUrl)}">${escapeHtml(text.reprint || 'Reprint existing label')}</a>
          `;
          return;
        }

        window.alert(result.data && result.data.message ? result.data.message : (text.failedLabel || 'Failed to create label.'));
        return;
      }

      content.innerHTML = `
        <p><strong>${escapeHtml(text.tracking || 'Tracking')}:</strong> ${trackingLinkHtml(result.data.shipment.tracking_number, result.data.shipment.tracking_url)}</p>
        ${renderTransitEstimate(result.data.rate || {})}
        <p><a class="button button-primary" target="_blank" data-wlp-print-label data-order-id="${escapeHtml(buyOrderId)}" href="${escapeHtml(result.data.printUrl)}">${escapeHtml(text.printLabel || 'Print label')}</a></p>
      `;
      const card = document.querySelector(`[data-wlp-order-card][data-order-id="${cssEscape(buyOrderId)}"]`);
      if (card) {
        updateCardAfterLabel(card, result.data.printUrl, result.data.shipment, result.data.package, result.data.estimate, result.data.rate);
      }
      return;
    }

    const buyReturnButton = event.target.closest('[data-wlp-buy-return-label]');
    if (buyReturnButton) {
      if (buyReturnButton.disabled) {
        return;
      }
      const hasLabel = buyReturnButton.getAttribute('data-has-label') === 'yes';
      const buyOrderId = buyReturnButton.getAttribute('data-order-id') || '';
      const confirmMessage = hasLabel
        ? (text.confirmReturnOverride || 'This order already has a return label. Buy a replacement return label anyway?')
        : (text.confirmReturnLabel || 'Buy a Canada Post return label that sends this customer package back to your configured return address?');

      if (!window.confirm(confirmMessage)) {
        return;
      }

      if (buyReturnButton.getAttribute('data-custom-package') === 'yes') {
        const dimensions = `${buyReturnButton.getAttribute('data-custom-length')} × ${buyReturnButton.getAttribute('data-custom-width')} × ${buyReturnButton.getAttribute('data-custom-height')} cm`;
        const weight = `${buyReturnButton.getAttribute('data-custom-weight')} kg`;
        if (!window.confirm(`Use this custom package for the return label?\n\n${dimensions}\n${weight}\n\nConfirm the return box matches these measurements.`)) {
          return;
        }
      }

      buyReturnButton.disabled = true;
      buyReturnButton.textContent = text.buying || 'Buying...';

      const labelPostData = {
        orderId: buyOrderId,
        presetId: buyReturnButton.getAttribute('data-preset-id'),
        serviceCode: buyReturnButton.getAttribute('data-service-code'),
        override: hasLabel ? 'yes' : 'no',
        signatureRequired: 'no',
      };

      if (buyReturnButton.getAttribute('data-custom-package') === 'yes') {
        Object.assign(labelPostData, {
          customPackage: 'yes',
          customLength: buyReturnButton.getAttribute('data-custom-length') || '',
          customWidth: buyReturnButton.getAttribute('data-custom-width') || '',
          customHeight: buyReturnButton.getAttribute('data-custom-height') || '',
          customWeight: buyReturnButton.getAttribute('data-custom-weight') || '',
        });
      }

      const result = await post('wlp_create_return_label', labelPostData);

      if (!result.success) {
        buyReturnButton.disabled = false;
        buyReturnButton.textContent = hasLabel ? (text.buyReturnOverride || 'Buy replacement return label') : (text.buyReturnLabel || 'Buy return label');

        if (result.data && result.data.code === 'return_label_exists' && result.data.printUrl) {
          content.innerHTML = `
            <p>${escapeHtml(result.data.message)}</p>
            <a class="button button-primary" target="_blank" href="${escapeHtml(result.data.printUrl)}">${escapeHtml(text.printReturnLabel || 'Print return label')}</a>
          `;
          return;
        }

        window.alert(result.data && result.data.message ? result.data.message : (text.failedLabel || 'Failed to create label.'));
        return;
      }

      content.innerHTML = `
        <p><strong>Return tracking:</strong> ${trackingLinkHtml(result.data.shipment.tracking_number, result.data.shipment.tracking_url)}</p>
        ${renderTransitEstimate(result.data.rate || {})}
        <p><a class="button button-primary" target="_blank" data-wlp-print-return-label href="${escapeHtml(result.data.printUrl)}">${escapeHtml(text.printReturnLabel || 'Print return label')}</a></p>
      `;

      document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(buyOrderId)}"], [data-wlp-order-box][data-order-id="${cssEscape(buyOrderId)}"]`).forEach((surface) => {
        updateCardAfterReturnLabel(surface, result.data.printUrl, result.data.shipment);
      });
      return;
    }

    const sendNoteButton = event.target.closest('[data-wlp-send-customer-note]');
    if (sendNoteButton) {
      const originalText = sendNoteButton.textContent;
      sendNoteButton.disabled = true;
      sendNoteButton.textContent = text.sendingNote || 'Sending note...';

      const result = await post('wlp_send_customer_note', {
        orderId: sendNoteButton.getAttribute('data-order-id'),
      });

      sendNoteButton.disabled = false;
      sendNoteButton.textContent = originalText || (text.sendNote || 'Send customer note');

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : (text.failedNote || 'Failed to send customer note.');
        bulkStatus(message, [{
          orderId: sendNoteButton.getAttribute('data-order-id'),
          orderNumber: sendNoteButton.getAttribute('data-order-id'),
          message,
        }]);
        window.alert(message);
        return;
      }

      bulkStatus(result.data && result.data.message ? result.data.message : (text.sentNote || 'Customer note sent.'));
      return;
    }

    const sendDelayNoteButton = event.target.closest('[data-wlp-send-delay-note]');
    if (sendDelayNoteButton) {
      const orderId = sendDelayNoteButton.getAttribute('data-order-id');
      if (!window.confirm(text.confirmDelayNote || 'Send this customer a delivery delay follow-up email?')) {
        return;
      }

      const originalText = sendDelayNoteButton.textContent;
      sendDelayNoteButton.disabled = true;
      sendDelayNoteButton.textContent = text.sendingNote || 'Sending note...';

      const result = await post('wlp_send_delivery_delay_note', { orderId });

      sendDelayNoteButton.disabled = false;
      sendDelayNoteButton.textContent = originalText || (text.sendDelayNote || 'Send delay follow-up');

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : (text.failedDelayNote || 'Failed to send delivery delay follow-up.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      bulkStatus(result.data && result.data.message ? result.data.message : (text.delayNoteSent || 'Delivery delay follow-up sent.'));
      return;
    }

    const sendReturnNoteButton = event.target.closest('[data-wlp-send-return-note]');
    if (sendReturnNoteButton) {
      const originalText = sendReturnNoteButton.textContent;
      const orderId = sendReturnNoteButton.getAttribute('data-order-id');
      sendReturnNoteButton.disabled = true;
      sendReturnNoteButton.textContent = text.sendingNote || 'Sending note...';

      const result = await post('wlp_send_return_note', { orderId });

      sendReturnNoteButton.disabled = false;
      sendReturnNoteButton.textContent = originalText || (text.sendReturnNote || 'Email return label + instructions');

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : (text.failedReturnNote || 'Failed to send return link.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      bulkStatus(result.data && result.data.message ? result.data.message : (text.returnNoteSent || 'Return label email sent.'));
      return;
    }

    const partialPaymentButton = event.target.closest('[data-wlp-record-partial-payment]');
    if (partialPaymentButton) {
      const orderId = partialPaymentButton.getAttribute('data-order-id');
      const panel = partialPaymentButton.closest('[data-wlp-wholesale-partial-payment]');
      const input = panel ? panel.querySelector('[data-wlp-partial-payment-amount]') : null;
      const amount = input ? Number(input.value) : 0;
      const balance = input ? Number(input.max) : 0;

      if (!Number.isFinite(amount) || amount <= 0) {
        window.alert('Enter the reconciled partial payment amount first.');
        return;
      }
      if (Number.isFinite(balance) && balance > 0 && amount > balance) {
        window.alert(`The amount cannot exceed the remaining balance of C$${balance.toFixed(2)}.`);
        return;
      }
      if (!window.confirm(`Record a partial payment of C$${amount.toFixed(2)}?\n\nThis keeps the order Pending. It does not email the customer, start fulfillment, or report a purchase.`)) {
        return;
      }

      const entryToken = window.crypto && typeof window.crypto.randomUUID === 'function'
        ? window.crypto.randomUUID()
        : `wlp-${Date.now()}-${Math.random().toString(16).slice(2)}`;
      partialPaymentButton.disabled = true;
      partialPaymentButton.textContent = 'Recording...';
      const result = await post('wlp_wholesale_record_partial_payment', { orderId, amount: amount.toFixed(2), entryToken });

      if (!result.success) {
        partialPaymentButton.disabled = false;
        partialPaymentButton.textContent = 'Record partial payment';
        window.alert(result.data && result.data.message ? result.data.message : 'Could not record the partial payment.');
        return;
      }

      reloadAfterWholesaleUpdate(result.data && result.data.message);
      return;
    }

    const workflowButton = event.target.closest('[data-wlp-wholesale-advance]');
    if (workflowButton) {
      const orderId = workflowButton.getAttribute('data-order-id');
      const workflowAction = workflowButton.getAttribute('data-wlp-wholesale-advance');
      const workflow = workflowButton.closest('[data-wlp-wholesale-workflow]');
      const notify = workflow ? workflow.querySelector('[data-wlp-workflow-notify]') : null;
      const notificationStage = notify && notify.checked ? notify.getAttribute('data-wlp-workflow-notify-stage') : '';
	  const paymentNotify = workflow ? workflow.querySelector('[data-wlp-payment-notify-email]') : null;
	  const suppressCustomerEmail = workflowAction === 'payment_verified' && paymentNotify && !paymentNotify.checked;
      const confirmations = {
		payment_verified: suppressCustomerEmail
		  ? 'Confirm that the exact payment has been reconciled. This updates WooCommerce to Processing and starts fulfillment without emailing the customer.'
		  : 'Confirm that the exact payment has been reconciled. This updates WooCommerce to Processing, starts fulfillment, and automatically emails the customer that payment was received.',
        received_inspected: 'Confirm that the stock is physically on hand in Hamilton and has been inspected? This moves the order to Ready to ship. No customer email will be sent.',
        confirm_shipped: 'Confirm that the package has been handed to Canada Post? This marks the order shipped and automatically emails tracking and the account link.',
      };

      const confirmation = confirmations[workflowAction]
        ? `${confirmations[workflowAction]}${notificationStage ? ' The selected customer email will open as a preview after this step.' : ''}`
        : '';
      if (confirmation && !window.confirm(confirmation)) {
        return;
      }

      workflowButton.disabled = true;
	  const result = await post('wlp_wholesale_advance', { orderId, workflowAction, suppressCustomerEmail: suppressCustomerEmail ? '1' : '0' });
      workflowButton.disabled = false;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Could not advance the wholesale workflow.';
        window.alert(message);
        return;
      }

      reloadAfterWholesaleUpdate(result.data && result.data.message, {
        orderId,
        notificationStage,
        state: result.data && result.data.state ? result.data.state : '',
      });
      return;
    }

    const workflowEmailButton = event.target.closest('[data-wlp-open-wholesale-email]');
    if (workflowEmailButton) {
      const stage = workflowEmailButton.getAttribute('data-wlp-open-wholesale-email') || '';
      const surface = workflowEmailButton.closest('[data-wlp-order-card], .wlp-quick-view[data-wlp-order-box]') || document;
      await openWholesaleEmailPreview(surface, stage);
      return;
    }

    const workflowSetButton = event.target.closest('[data-wlp-workflow-set-step]');
    if (workflowSetButton) {
      const orderId = workflowSetButton.getAttribute('data-order-id');
      const workflow = workflowSetButton.closest('[data-wlp-wholesale-workflow]');
      const select = workflow ? workflow.querySelector('[data-wlp-workflow-stage-select]') : null;
      const target = select ? select.value : '';
      const targetLabel = select && select.selectedOptions.length ? select.selectedOptions[0].textContent.trim() : target;
      const current = workflow ? workflow.getAttribute('data-workflow-stage') : '';

      if (!target) {
        window.alert('Choose an internal wholesale workflow step first.');
        return;
      }
      if (target === current) {
        window.alert('This order is already on that workflow step.');
        return;
      }

      const inspectionNote = target === 'ready_to_ship'
        ? '\n\nThis records that the order was inspected in Hamilton.'
        : '';
      if (!window.confirm(`Set this order to “${targetLabel}”?\n\nThis only updates the internal workflow and sends no customer email.${inspectionNote}`)) {
        return;
      }

      workflowSetButton.disabled = true;
      const result = await post('wlp_wholesale_set_workflow', { orderId, workflowStage: target });
      workflowSetButton.disabled = false;

      if (!result.success) {
        window.alert(result.data && result.data.message ? result.data.message : 'Could not update the wholesale workflow step.');
        return;
      }

      reloadAfterWholesaleUpdate(result.data && result.data.message, {
        state: result.data && result.data.state ? result.data.state : '',
      });
      return;
    }

    const inboundButton = event.target.closest('[data-wlp-save-inbound-tracking]');
    if (inboundButton) {
      const orderId = inboundButton.getAttribute('data-order-id');
      const form = inboundButton.closest('[data-wlp-wholesale-inbound-form]');
      const carrier = form && form.querySelector('[data-wlp-inbound-carrier]') ? form.querySelector('[data-wlp-inbound-carrier]').value.trim() : '';
      const trackingNumber = form && form.querySelector('[data-wlp-inbound-number]') ? form.querySelector('[data-wlp-inbound-number]').value.trim() : '';
      const trackingUrl = form && form.querySelector('[data-wlp-inbound-url]') ? form.querySelector('[data-wlp-inbound-url]').value.trim() : '';

      updateInboundTrackingUrl(form);
      const resolvedTrackingUrl = form && form.querySelector('[data-wlp-inbound-url]') ? form.querySelector('[data-wlp-inbound-url]').value.trim() : trackingUrl;

      inboundButton.disabled = true;
      const result = await post('wlp_wholesale_save_inbound_tracking', { orderId, carrier, trackingNumber, trackingUrl: resolvedTrackingUrl });
      inboundButton.disabled = false;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Could not save inbound tracking.';
        window.alert(message);
        return;
      }

      reloadAfterWholesaleUpdate(result.data && result.data.message);
      return;
    }

    const outboundSaveButton = event.target.closest('[data-wlp-save-outbound-tracking]');
    if (outboundSaveButton) {
      const orderId = outboundSaveButton.getAttribute('data-order-id');
      const form = outboundSaveButton.closest('[data-wlp-wholesale-outbound-form]');
      const payload = outboundTrackingPayload(form);

      outboundSaveButton.disabled = true;
      const result = await post('wlp_wholesale_save_tracking', { orderId, mode: 'save', ...payload });
      outboundSaveButton.disabled = false;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Could not save customer tracking.';
        window.alert(message);
        return;
      }

      reloadAfterWholesaleUpdate(result.data && result.data.message);
      return;
    }

    const outboundPreviewButton = event.target.closest('[data-wlp-preview-outbound-tracking]');
    if (outboundPreviewButton) {
      const orderId = outboundPreviewButton.getAttribute('data-order-id');
      const form = outboundPreviewButton.closest('[data-wlp-wholesale-outbound-form]');
      const payload = outboundTrackingPayload(form);
      const preview = form ? form.querySelector('[data-wlp-tracking-preview]') : null;

      outboundPreviewButton.disabled = true;
      const result = await post('wlp_wholesale_tracking_preview', { orderId, ...payload });
      outboundPreviewButton.disabled = false;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Could not preview the tracking email.';
        window.alert(message);
        return;
      }

      if (preview) {
        preview.hidden = false;
        preview.dataset.previewTracking = JSON.stringify(payload);
        const subject = preview.querySelector('[data-wlp-tracking-preview-subject]');
        const recipient = preview.querySelector('[data-wlp-tracking-preview-recipient]');
        const body = preview.querySelector('[data-wlp-tracking-preview-body]');
        if (subject) subject.textContent = `Subject: ${result.data.subject || ''}`;
        if (recipient) recipient.textContent = `Recipient: ${result.data.recipient || ''}`;
        if (body) body.value = result.data.body || '';
      }
      return;
    }

    const outboundShipButton = event.target.closest('[data-wlp-confirm-outbound-ship]');
    if (outboundShipButton) {
      const orderId = outboundShipButton.getAttribute('data-order-id');
      const form = outboundShipButton.closest('[data-wlp-wholesale-outbound-form]');
      const preview = form ? form.querySelector('[data-wlp-tracking-preview]') : null;
      const body = preview ? preview.querySelector('[data-wlp-tracking-preview-body]') : null;
      const payload = outboundTrackingPayload(form);

      if (!preview || preview.hidden || preview.dataset.previewTracking !== JSON.stringify(payload)) {
        window.alert('Preview the exact tracking email again before sending.');
        return;
      }

      if (!window.confirm('Mark this wholesale order shipped and send the exact previewed tracking email?')) {
        return;
      }

      outboundShipButton.disabled = true;
      const result = await post('wlp_wholesale_save_tracking', { orderId, mode: 'ship', body: body ? body.value : '', ...payload });
      outboundShipButton.disabled = false;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : 'Could not mark the wholesale order shipped.';
        window.alert(message);
        return;
      }

      reloadAfterWholesaleUpdate(result.data && result.data.message);
      return;
    }

    const wholesaleButton = event.target.closest('[data-wlp-wholesale-followup]');
    if (wholesaleButton) {
      const originalText = wholesaleButton.textContent;
      const orderId = wholesaleButton.getAttribute('data-order-id');
      const control = wholesaleButton.closest('.wlp-wholesale-note-control');
      const select = control ? control.querySelector('[data-wlp-wholesale-update-select]') : null;
      const bodyField = control ? control.querySelector('[data-wlp-wholesale-message-body]') : null;
      const stage = wholesaleButton.getAttribute('data-wlp-wholesale-followup') || (select ? select.value : '');

      if (!window.confirm(wholesaleConfirmText(stage))) {
        return;
      }

      wholesaleButton.disabled = true;
      wholesaleButton.textContent = text.wholesaleSending || 'Sending wholesale update...';

      const result = await post('wlp_wholesale_followup', { orderId, stage, body: bodyField ? bodyField.value : '' });

      wholesaleButton.disabled = false;
      wholesaleButton.textContent = originalText;

      if (!result.success) {
        const message = result.data && result.data.message ? result.data.message : (text.wholesaleFailed || 'Failed to send wholesale update.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId || '')}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId || '')}"]`).forEach((surface) => {
        updateWholesaleStage(surface, result.data || {});
      });

      if (stage === 'payment_received') {
        document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId || '')}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId || '')}"]`).forEach((surface) => {
          const statusLabel = Array.from(surface.querySelectorAll('dt')).find((node) => node.textContent.trim().toLowerCase() === 'status');
          if (statusLabel && statusLabel.nextElementSibling && result.data && result.data.status) {
            statusLabel.nextElementSibling.textContent = result.data.status;
          }
        });
      }

      if (stage === 'delivered_complete') {
        removeOrderSurfaces(orderId);
      }

      bulkStatus(result.data && result.data.message ? result.data.message : (text.wholesaleSent || 'Wholesale update sent.'));
      return;
    }

    const prepareReshipButton = event.target.closest('[data-wlp-prepare-reship]');
    if (prepareReshipButton) {
      const originalText = prepareReshipButton.textContent;
      const orderId = prepareReshipButton.getAttribute('data-order-id');

      prepareReshipButton.disabled = true;
      prepareReshipButton.textContent = 'Loading address...';

      const orderResult = await post('wlp_quick_view_order', { orderId });
      if (!orderResult.success) {
        prepareReshipButton.disabled = false;
        prepareReshipButton.textContent = originalText || (text.prepareReship || 'Prepare reship');
        const message = orderResult.data && orderResult.data.message ? orderResult.data.message : 'Failed to load the shipping address.';
        window.alert(message);
        return;
      }

      const shippingAddress = await reshipAddressDialog(orderResult.data.shippingAddress || {});
      if (!shippingAddress) {
        prepareReshipButton.disabled = false;
        prepareReshipButton.textContent = originalText || (text.prepareReship || 'Prepare reship');
        return;
      }

      prepareReshipButton.textContent = text.preparingReship || 'Preparing reship...';

      const result = await post('wlp_prepare_reship', { orderId, ...shippingAddress });

      if (!result.success) {
        prepareReshipButton.disabled = false;
        prepareReshipButton.textContent = originalText || (text.prepareReship || 'Prepare reship');
        const message = result.data && result.data.message ? result.data.message : (text.failedReship || 'Failed to prepare reship.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId || '')}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId || '')}"]`).forEach((surface) => {
        updateCardAfterReshipPrepared(surface);
        const shippingName = surface.querySelector('[data-wlp-shipping-name]');
        if (shippingName && result.data && result.data.shippingName) {
          shippingName.textContent = result.data.shippingName;
        }
        const shippingLines = surface.querySelector('[data-wlp-shipping-lines]');
        if (shippingLines && result.data && Array.isArray(result.data.shippingLines)) {
          shippingLines.innerHTML = result.data.shippingLines.map((line) => `<div>${escapeHtml(line)}</div>`).join('');
        }
      });
      bulkStatus(result.data && result.data.message ? result.data.message : (text.preparedReship || 'Order prepared for reship.'));
      return;
    }

    const markPackedButton = event.target.closest('[data-wlp-mark-packed]');
    if (markPackedButton) {
      const orderId = markPackedButton.getAttribute('data-order-id');
      if (!orderId || packingSaves.get(orderId) === 'pending' || markPackedButton.disabled) return;
      const surface = orderSurface(markPackedButton);
      if (!(await confirmMarkPacked(surface))) return;
      if (packingSaves.get(orderId) === 'pending') return;

      packingSaves.set(orderId, 'pending');
      packingFailures.delete(orderId);
      showPackingFailures();
      syncPackingSave(orderId);
      bulkStatus(`Saving packed status for order #${orderId}. You can move to the next order.`);

      try {
        const result = await post('wlp_mark_packed', { orderId });
        if (!result.success) throw new Error(result.data?.message || text.failedPacked || 'Failed to mark packed.');
        packingSaves.set(orderId, 'saved');
        syncPackingSave(orderId);
        bulkStatus(`Order #${orderId} saved as packed.`);
      } catch (error) {
        packingSaves.delete(orderId);
        packingFailures.set(orderId, error.message || 'Save could not be confirmed.');
        syncPackingSave(orderId);
        showPackingFailures();
        bulkStatus(`Packing save for order #${orderId} could not be confirmed. Check the order before retrying.`, [{ orderId, message: error.message }]);
      }
      return;
    }

    const markShippedButton = event.target.closest('[data-wlp-mark-shipped]');
    if (markShippedButton) {
      const originalText = markShippedButton.textContent;
      const orderId = markShippedButton.getAttribute('data-order-id');

      const shippedConfirmation = markShippedButton.hasAttribute('data-wlp-direct-fulfillment')
        ? `Confirm the entire order #${orderId} has been handed to the carrier? This is not for a partial shipment. Wholesale customers automatically receive tracking and the account link.`
        : (text.confirmShipped || 'Mark this order shipped and move it to In transit?');
      if (!window.confirm(shippedConfirmation)) {
        return;
      }

      markShippedButton.disabled = true;
      markShippedButton.textContent = text.markingShipped || 'Marking shipped...';

      const result = await post('wlp_mark_shipped', { orderId });

      if (!result.success) {
        markShippedButton.disabled = false;
        markShippedButton.textContent = originalText || (text.markShipped || 'Mark shipped');
        const message = result.data && result.data.message ? result.data.message : (text.failedShipped || 'Failed to mark shipped.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      document.querySelectorAll(`[data-wlp-order-card][data-order-id="${cssEscape(orderId || '')}"], [data-wlp-order-box][data-order-id="${cssEscape(orderId || '')}"]`).forEach((surface) => {
        updateCardAfterShipped(surface);
      });
      bulkStatus(result.data && result.data.message ? result.data.message : (text.shipped || 'Shipped'));
      window.setTimeout(() => {
        window.location.reload();
      }, 500);
      return;
    }

    const markDeliveredButton = event.target.closest('[data-wlp-mark-delivered]');
    if (markDeliveredButton) {
      const originalText = markDeliveredButton.textContent;
      const orderId = markDeliveredButton.getAttribute('data-order-id');

      const deliveredConfirmation = markDeliveredButton.hasAttribute('data-wlp-direct-fulfillment')
        ? `Confirm the customer received the entire order #${orderId}? This will mark it delivered and completed in WooCommerce. No customer email will be sent.`
        : (text.confirmDelivered || 'Mark this order delivered and complete it in WooCommerce?');
      if (!window.confirm(deliveredConfirmation)) {
        return;
      }

      markDeliveredButton.disabled = true;
      markDeliveredButton.textContent = text.markingDelivered || 'Marking delivered...';

      const result = await post('wlp_mark_delivered', { orderId });

      if (!result.success) {
        markDeliveredButton.disabled = false;
        markDeliveredButton.textContent = originalText || (text.markDelivered || 'Mark delivered');
        const message = result.data && result.data.message ? result.data.message : (text.failedDelivered || 'Failed to mark delivered.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      removeOrderSurfaces(orderId);
      bulkStatus(result.data && result.data.message ? result.data.message : (text.delivered || 'Delivered'));
      return;
    }

    const cancelOrderButton = event.target.closest('[data-wlp-cancel-order]');
    if (cancelOrderButton) {
      const originalText = cancelOrderButton.textContent;
      const orderId = cancelOrderButton.getAttribute('data-order-id');

      if (!window.confirm(text.confirmCancel || 'Cancel this WooCommerce order? This removes it from the active logistics board.')) {
        return;
      }

      cancelOrderButton.disabled = true;
      cancelOrderButton.textContent = text.cancellingOrder || 'Cancelling...';

      const result = await post('wlp_cancel_order', { orderId });

      if (!result.success) {
        cancelOrderButton.disabled = false;
        cancelOrderButton.textContent = originalText || '×';
        const message = result.data && result.data.message ? result.data.message : (text.failedCancel || 'Failed to cancel order.');
        bulkStatus(message, [{ orderId, orderNumber: orderId, message }]);
        window.alert(message);
        return;
      }

      removeOrderSurfaces(orderId);
      bulkStatus(result.data && result.data.message ? result.data.message : (text.cancelledOrder || 'Order cancelled.'));
      return;
    }

    const printBatchButton = event.target.closest('[data-wlp-print-batch]');
    if (printBatchButton) {
      const selected = selectedCards();
      const invoiceOrderIds = selected
        .map((card) => card.getAttribute('data-order-id'))
        .filter(Boolean);
      const labelOrderIds = selected
        .filter((card) => card.getAttribute('data-print-url'))
        .map((card) => card.getAttribute('data-order-id'))
        .filter(Boolean);

      if (!invoiceOrderIds.length) {
        bulkStatus('Select at least one order to print.');
        return;
      }

      if (!labelOrderIds.length) {
        bulkStatus('The selected orders do not have labels yet. Prepare the unpacked batch first.');
        return;
      }

      if (selected.some((card) => card.getAttribute('data-packed') === 'yes') && !window.confirm(text.confirmPackedLabel || 'One or more selected orders are already marked packed. Are you sure you want to print this batch?')) {
        return;
      }

      const labelUrl = `${wlpAdmin.bulkPrintUrl}&order_ids=${encodeURIComponent(labelOrderIds.join(','))}&_wpnonce=${encodeURIComponent(wlpAdmin.bulkPrintNonce || '')}`;
      const invoiceUrl = `${wlpAdmin.bulkInvoiceUrl}&order_ids=${encodeURIComponent(invoiceOrderIds.join(','))}&_wpnonce=${encodeURIComponent(wlpAdmin.bulkInvoiceNonce || '')}`;
      const labelsWindow = window.open(labelUrl, '_blank');
      const invoicesWindow = window.open(invoiceUrl, '_blank');

      if (!labelsWindow || !invoicesWindow) {
        bulkStatus('Allow popups for this site, then choose Labels + invoices again.');
        return;
      }

      labelsWindow.opener = null;
      invoicesWindow.opener = null;
      selected.forEach(updateCardAfterInvoicePrinted);
      bulkStatus(`Opened ${labelOrderIds.length} labels and ${invoiceOrderIds.length} invoices in two print tabs.`);
      return;
    }

    const manualCounterButton = event.target.closest('[data-wlp-manual-counter]');
    if (manualCounterButton) {
      const singleId = manualCounterButton.getAttribute('data-order-id');
      const ids = singleId ? [singleId] : selectedCards().map(card => card.getAttribute('data-order-id')).filter(Boolean);
      if (!ids.length) { bulkStatus('Select at least one order for manual counter labels.'); return; }
      const url = new URL(wlpAdmin.manualCounterUrl.replace(/&amp;/g, '&'));
      url.searchParams.set('order_ids', ids.join(','));
      const opened = window.open(url.toString(), '_blank');
      if (!opened) { bulkStatus('Allow popups to open manual counter labels.'); return; }
      opened.opener = null;
      opened.focus();
      return;
    }

    const bulkPrintButton = event.target.closest('[data-wlp-bulk-print-selected]');
    if (bulkPrintButton) {
      const orderIds = selectedCards()
        .filter((card) => card.getAttribute('data-print-url'))
        .map((card) => card.getAttribute('data-order-id'))
        .filter(Boolean);

      if (!orderIds.length) {
        bulkStatus('Select at least one labeled order to print.');
        return;
      }

      if (selectedCards().some((card) => card.getAttribute('data-print-url') && card.getAttribute('data-packed') === 'yes') && !window.confirm(text.confirmPackedLabel || 'One or more selected orders are already marked packed. Are you sure you want to print labels?')) {
        return;
      }

      const url = `${wlpAdmin.bulkPrintUrl}&order_ids=${encodeURIComponent(orderIds.join(','))}&_wpnonce=${encodeURIComponent(wlpAdmin.bulkPrintNonce || '')}`;
      const opened = window.open(url, '_blank');
      if (!opened) {
        bulkStatus(text.popupNotice || 'Allow popups for this site to bulk print labels.');
        return;
      }

      opened.opener = null;
      opened.focus();
      return;
    }

    const bulkInvoiceButton = event.target.closest('[data-wlp-bulk-invoice-selected]');
    if (bulkInvoiceButton) {
      const orderIds = selectedCards()
        .map((card) => card.getAttribute('data-order-id'))
        .filter(Boolean);

      if (!orderIds.length) {
        bulkStatus('Select at least one order to print invoices.');
        return;
      }

      if (selectedCards().some((card) => card.getAttribute('data-packed') === 'yes') && !window.confirm(text.confirmPackedInvoice || 'One or more selected orders are already marked packed. Are you sure you want to print invoices/guides?')) {
        return;
      }

      const url = `${wlpAdmin.bulkInvoiceUrl}&order_ids=${encodeURIComponent(orderIds.join(','))}&_wpnonce=${encodeURIComponent(wlpAdmin.bulkInvoiceNonce || '')}`;
      const opened = window.open(url, '_blank');
      if (!opened) {
        bulkStatus(text.popupNotice || 'Allow popups for this site to bulk print invoices.');
        return;
      }

      opened.opener = null;
      opened.focus();
      selectedCards().forEach(updateCardAfterInvoicePrinted);
      return;
    }

    const bulkInvoiceViewButton = event.target.closest('[data-wlp-bulk-invoice-view]');
    if (bulkInvoiceViewButton) {
      const orderIds = (bulkInvoiceViewButton.getAttribute('data-order-ids') || '')
        .split(',')
        .map((orderId) => orderId.trim())
        .filter(Boolean);

      if (!orderIds.length) {
        bulkStatus('No orders in this tab to print invoices.');
        return;
      }

      if (orderIds.some((orderId) => {
        const card = document.querySelector(`[data-wlp-order-card][data-order-id="${cssEscape(orderId)}"]`);
        return card && card.getAttribute('data-packed') === 'yes';
      }) && !window.confirm(text.confirmPackedInvoice || 'One or more orders in this tab are already marked packed. Are you sure you want to print invoices/guides?')) {
        return;
      }

      const url = `${wlpAdmin.bulkInvoiceUrl}&order_ids=${encodeURIComponent(orderIds.join(','))}&_wpnonce=${encodeURIComponent(wlpAdmin.bulkInvoiceNonce || '')}`;
      const opened = window.open(url, '_blank');
      if (!opened) {
        bulkStatus(text.popupNotice || 'Allow popups for this site to bulk print invoices.');
        return;
      }

      opened.opener = null;
      opened.focus();
      orderIds.forEach((orderId) => {
        updateCardAfterInvoicePrinted(document.querySelector(`[data-wlp-order-card][data-order-id="${cssEscape(orderId)}"]`));
      });
      return;
    }

    const quickBuyButton = event.target.closest('[data-wlp-quick-buy-selected]');
    if (quickBuyButton) {
      const selected = selectedCards();
      const cards = selected.filter((card) => card.getAttribute('data-has-label') !== 'yes');

      if (!selected.length) {
        bulkStatus(text.selectOrders || 'Select at least one order.');
        return;
      }

      if (!cards.length) {
        bulkStatus('Selected orders already have labels. Use Bulk print selected instead.');
        return;
      }

      const quickOptions = await quickBuyOptionsDialog(cards.length, cards.map((card) => card.getAttribute('data-order-id')).filter(Boolean));
      if (!quickOptions) {
        bulkStatus('Quick buy cancelled.');
        return;
      }

      quickBuyButton.disabled = true;
      bulkStatus(text.quickBuying || 'Buying quick labels...');

      let success = 0;
      let failed = 0;
      const failures = [];
      const printUrls = [];
      const manifestProgress = () => document.dispatchEvent(new CustomEvent('wlp:label-batch-progress', {detail: {remaining: cards.length - success - failed}}));
      manifestProgress();

      for (const card of cards) {
        const orderId = card.getAttribute('data-order-id');
        const orderNumber = (card.querySelector('h2') && card.querySelector('h2').textContent.replace(/^#/, '').trim()) || orderId;
        const result = await post('wlp_quick_buy_label', {
          orderId,
          presetId: quickOptions.presetId || '',
          serviceCode: quickOptions.serviceCode || '',
          xpresspostUpgradeLimit: quickOptions.xpresspostUpgradeLimit || '1.00',
        });

        if (result.success && result.data && result.data.printUrl) {
          success += 1;
          printUrls.push(result.data.printUrl);
          updateCardAfterLabel(card, result.data.printUrl, result.data.shipment, result.data.package, result.data.estimate, result.data.rate);
        } else {
          failed += 1;
          failures.push({
            orderId,
            orderNumber,
            message: result.data && result.data.message ? result.data.message : (text.failedLabel || 'Failed to create label.'),
          });
        }

        manifestProgress();
        bulkStatus(`${text.quickBuying || 'Buying quick labels...'} ${success + failed}/${cards.length}`);
      }

      quickBuyButton.disabled = false;
      bulkStatus(`${text.quickDone || 'Quick buy complete.'} ${success} bought, ${failed} failed.`, failures);

    }

    const dummyOrderButton = event.target.closest('[data-wlp-create-dummy-order]');
    if (dummyOrderButton) {
      dummyOrderButton.disabled = true;
      bulkStatus(text.creatingDummy || 'Creating test order...');

      const result = await post('wlp_create_dummy_order', {});

      if (!result.success) {
        dummyOrderButton.disabled = false;
        bulkStatus(
          result.data && result.data.message ? result.data.message : (text.dummyFailed || 'Failed to create test order.'),
          [{ orderId: 'test', orderNumber: 'Test order', message: result.data && result.data.message ? result.data.message : '' }],
        );
        return;
      }

      bulkStatus(text.dummyCreated || 'Test order created. Reloading...');
      window.setTimeout(() => {
        window.location.reload();
      }, 600);
    }
  });

  document.addEventListener('change', async (event) => {
    if (event.target.closest('[data-wlp-order-select]')) {
      updateSelectionCount();
    }

    const wholesaleSelect = event.target.closest('[data-wlp-wholesale-update-select]');
    if (wholesaleSelect) {
      await loadWholesalePreview(wholesaleSelect.closest('.wlp-wholesale-note-control'));
    }

    const signatureToggle = event.target.closest('[data-wlp-signature-required]');
    if (signatureToggle) {
      const orderId = signatureToggle.getAttribute('data-order-id');
      const customPackage = customPackageFromForm();
      const activeCustomPackage = hasCustomPackageValues(customPackage) ? customPackage : null;
      if (drawerMode === 'return') {
        await loadReturnRates(orderId, signatureToggle.checked, activeCustomPackage);
      } else {
        await loadRates(orderId, signatureToggle.checked, cardForPickupEnabled(), activeCustomPackage);
      }
    }
  });

  updateSelectionCount();

  window.setTimeout(() => {
    let pending = null;
    try {
      pending = JSON.parse(window.sessionStorage.getItem(pendingWholesaleNotificationKey) || 'null');
    } catch (error) {
      window.sessionStorage.removeItem(pendingWholesaleNotificationKey);
      return;
    }

    if (!pending || !pending.orderId || !pending.notificationStage) {
      return;
    }

    window.sessionStorage.removeItem(pendingWholesaleNotificationKey);
    const surface = surfaceForOrderId(String(pending.orderId));
    if (surface) {
      void openWholesaleEmailPreview(surface, String(pending.notificationStage));
    }
  }, 0);

  document.addEventListener('toggle', (event) => {
    const details = event.target.closest ? event.target.closest('.wlp-wholesale-communications') : null;
    if (!details || !details.open) {
      return;
    }
    loadWholesalePreview(details.querySelector('.wlp-wholesale-note-control'));
  }, true);

  document.addEventListener('input', (event) => {
    const inboundField = event.target.closest('[data-wlp-inbound-carrier], [data-wlp-inbound-number]');
    if (inboundField) {
      updateInboundTrackingUrl(inboundField.closest('[data-wlp-wholesale-inbound-form]'));
    }

    const outboundField = event.target.closest('[data-wlp-outbound-carrier], [data-wlp-outbound-number], [data-wlp-outbound-url], [data-wlp-outbound-date]');
    if (outboundField) {
      const form = outboundField.closest('[data-wlp-wholesale-outbound-form]');
      const preview = form ? form.querySelector('[data-wlp-tracking-preview]') : null;
      if (preview) {
        preview.hidden = true;
        delete preview.dataset.previewTracking;
      }
    }

    const search = event.target.closest('[data-wlp-pricing-search]');
    if (search) {
      const modal = search.closest('[data-wlp-pricing-modal]');
      const query = search.value.trim().toLowerCase();
      modal.querySelectorAll('[data-wlp-pricing-row]').forEach((row) => {
        row.hidden = query !== '' && !(row.getAttribute('data-search') || '').includes(query);
      });
    }

    const pricingCost = event.target.closest('[data-wlp-catalog-supplier-cost]');
    if (pricingCost) {
      const modal = pricingCost.closest('[data-wlp-pricing-modal]');
      updatePricingRow(pricingCost.closest('[data-wlp-pricing-row]'), pricingTargetMargin(modal));
      updatePricingSaveState(modal);
    }

    const targetMargin = event.target.closest('[data-wlp-pricing-target-margin]');
    if (targetMargin) updatePricingModal(targetMargin.closest('[data-wlp-pricing-modal]'));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const modal = document.querySelector('[data-wlp-pricing-modal]:not([hidden])');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('wlp-has-modal');
  });
  applyPrelabelDoneState();
})();
