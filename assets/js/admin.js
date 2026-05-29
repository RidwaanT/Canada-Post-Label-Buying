(function () {
  const drawer = document.getElementById('wlp-label-drawer');
  const content = document.getElementById('wlp-drawer-content');

  if (!drawer || !content || typeof wlpAdmin === 'undefined') {
    return;
  }

  const text = wlpAdmin.i18n || {};

  const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

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

    return response.json();
  };

  const openDrawer = () => {
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
  };

  const closeDrawer = () => {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
  };

  const selectedCards = () => Array.from(document.querySelectorAll('[data-wlp-order-select]:checked'))
    .map((input) => input.closest('[data-wlp-order-card]'))
    .filter(Boolean);

  const orderCards = () => Array.from(document.querySelectorAll('[data-wlp-order-card]'));

  const orderCheckboxes = () => Array.from(document.querySelectorAll('[data-wlp-order-select]'));

  const updateSelectionCount = () => {
    const target = document.querySelector('[data-wlp-selection-count]');
    if (!target) {
      return;
    }

    const selected = selectedCards();
    const labeled = selected.filter((card) => card.getAttribute('data-has-label') === 'yes');
    target.textContent = `${selected.length} selected${labeled.length ? `, ${labeled.length} labeled` : ''}`;
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

  const renderError = (message) => `
    <div class="wlp-error" role="alert">
      <strong>Action needed</strong>
      <p>${escapeHtml(formatError(message))}${settingsLink()}</p>
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

    const actions = card.querySelector('.wlp-actions');
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
    const actions = card.querySelector('.wlp-actions');
    if (!actions || actions.querySelector('[data-wlp-send-customer-note]')) {
      return;
    }

    const orderId = card.getAttribute('data-order-id') || '';
    actions.insertAdjacentHTML('beforeend', `<button class="button" type="button" data-wlp-send-customer-note data-order-id="${escapeHtml(orderId)}">${escapeHtml(text.sendNote || 'Send customer note')}</button>`);
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
    const createButton = card.querySelector('[data-wlp-create-label]');
    if (createButton) {
      createButton.textContent = text.viewOptions || 'View options';
    }
    ensurePrintButton(card, printUrl);
    ensureCustomerNoteButton(card);
    updateSelectionCount();
  };

  const renderExistingLabel = (payload) => {
    if (!payload.hasLabel || !payload.printUrl) {
      return '';
    }

    return `
      <div class="wlp-existing-label">
        <p>${escapeHtml(payload.message || '')}</p>
        <a class="button button-primary" target="_blank" href="${escapeHtml(payload.printUrl)}">${escapeHtml(text.reprint || 'Reprint existing label')}</a>
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

  const loadRates = async (orderId, signatureRequired = signatureEnabled(), cardForPickup = cardForPickupEnabled()) => {
    content.innerHTML = `
      ${renderSignatureControl(orderId, signatureRequired)}
      ${renderCardForPickupControl(orderId, cardForPickup)}
      <div class="wlp-loader">
        <div class="wlp-spinner"></div>
        <p>${escapeHtml(text.loadingRates || 'Loading Canada Post rates...')}</p>
      </div>
    `;

    const result = await post('wlp_get_rates', {
      orderId,
      signatureRequired: signatureRequired ? 'yes' : 'no',
    });
    if (!result.success) {
      content.innerHTML = `
        ${renderSignatureControl(orderId, signatureRequired)}
        ${renderCardForPickupControl(orderId, cardForPickup)}
        ${renderError(result.data && result.data.message ? result.data.message : (text.failedRates || 'Failed to load rates.'))}
      `;
      return;
    }

    if (result.data.hasLabel) {
      result.data.message = result.data.message || 'This order already has a label. Reprint it or buy a replacement.';
    }

    renderRates(orderId, result.data, signatureRequired, cardForPickup);
  };

  const renderRates = (orderId, payload, signatureRequired = signatureEnabled(), cardForPickup = cardForPickupEnabled()) => {
    const groups = payload.presets.map((entry) => {
      const rates = entry.rates.length
        ? entry.rates.map((rate) => `
          <div class="wlp-rate">
            <div class="wlp-rate__info">
              <span class="wlp-rate__name">${escapeHtml(rate.service_name || rate.service_code)}</span>
              <div class="wlp-rate__price">${rate.due ? `$${escapeHtml(rate.due)} CAD` : ''}</div>
              ${renderTransitEstimate(rate)}
            </div>
            <button class="button ${payload.hasLabel ? '' : 'button-primary'}" type="button" data-wlp-buy-label data-order-id="${escapeHtml(orderId)}" data-preset-id="${escapeHtml(entry.preset.id)}" data-service-code="${escapeHtml(rate.service_code)}" data-has-label="${payload.hasLabel ? 'yes' : 'no'}">${escapeHtml(payload.hasLabel ? (text.buyOverride || 'Buy replacement label') : (text.buyLabel || 'Buy label'))}</button>
          </div>
        `).join('')
        : renderError(entry.error || 'No rates returned for this package.');

      return `
        <section class="wlp-rate-group">
          <h3>${escapeHtml(entry.preset.name)}</h3>
          ${rates}
        </section>
      `;
    }).join('');

    content.innerHTML = `${renderSignatureControl(orderId, signatureRequired)}${renderCardForPickupControl(orderId, cardForPickup)}${renderExistingLabel(payload)}${groups}`;
  };

  document.addEventListener('click', async (event) => {
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
      closeDrawer();
      return;
    }

    const createButton = event.target.closest('[data-wlp-create-label]');
    if (createButton) {
      const orderId = createButton.getAttribute('data-order-id');
      openDrawer();
      await loadRates(orderId, wlpAdmin.signatureRequired === 'yes');
      return;
    }

    const buyButton = event.target.closest('[data-wlp-buy-label]');
    if (buyButton) {
      const hasLabel = buyButton.getAttribute('data-has-label') === 'yes';
      if (hasLabel && !window.confirm(text.confirm || 'Buy a replacement label?')) {
        return;
      }

      buyButton.disabled = true;
      buyButton.textContent = text.buying || 'Buying...';

      const result = await post('wlp_create_label', {
        orderId: buyButton.getAttribute('data-order-id'),
        presetId: buyButton.getAttribute('data-preset-id'),
        serviceCode: buyButton.getAttribute('data-service-code'),
        override: hasLabel ? 'yes' : 'no',
        signatureRequired: signatureEnabled() ? 'yes' : 'no',
        cardForPickup: cardForPickupEnabled() ? 'yes' : 'no',
      });

      if (!result.success) {
        buyButton.disabled = false;
        buyButton.textContent = hasLabel ? (text.buyOverride || 'Buy replacement label') : (text.buyLabel || 'Buy label');

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
        <p><a class="button button-primary" target="_blank" href="${escapeHtml(result.data.printUrl)}">${escapeHtml(text.printLabel || 'Print label')}</a></p>
      `;
      const card = document.querySelector(`[data-wlp-order-card][data-order-id="${cssEscape(buyButton.getAttribute('data-order-id') || '')}"]`);
      if (card) {
        updateCardAfterLabel(card, result.data.printUrl, result.data.shipment, result.data.package, result.data.estimate, result.data.rate);
      }
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

      quickBuyButton.disabled = true;
      bulkStatus(text.quickBuying || 'Buying quick labels...');

      let success = 0;
      let failed = 0;
      const failures = [];
      const printUrls = [];

      for (const card of cards) {
        const orderId = card.getAttribute('data-order-id');
        const orderNumber = (card.querySelector('h2') && card.querySelector('h2').textContent.replace(/^#/, '').trim()) || orderId;
        const result = await post('wlp_quick_buy_label', { orderId });

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

    const signatureToggle = event.target.closest('[data-wlp-signature-required]');
    if (signatureToggle) {
      await loadRates(signatureToggle.getAttribute('data-order-id'), signatureToggle.checked, cardForPickupEnabled());
    }
  });

  updateSelectionCount();
})();
