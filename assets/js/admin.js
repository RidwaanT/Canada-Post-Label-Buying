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

  const updateCardAfterLabel = (card, printUrl) => {
    card.setAttribute('data-has-label', 'yes');
    card.setAttribute('data-print-url', printUrl || '');
    const pill = card.querySelector('.wlp-pill');
    if (pill) {
      pill.classList.remove('is-pending');
      pill.classList.add('is-ready');
      pill.textContent = 'Labeled';
    }
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

  const renderRates = (orderId, payload) => {
    const groups = payload.presets.map((entry) => {
      const rates = entry.rates.length
        ? entry.rates.map((rate) => `
          <div class="wlp-rate">
            <div>
              <strong>${escapeHtml(rate.service_name || rate.service_code)}</strong>
              <div>${rate.due ? `$${escapeHtml(rate.due)} CAD` : ''}</div>
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

    content.innerHTML = `${renderExistingLabel(payload)}${groups}`;
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
      content.innerHTML = `<p>${escapeHtml(text.loadingRates || 'Loading Canada Post rates...')}</p>`;
      openDrawer();

      const result = await post('wlp_get_rates', { orderId });
      if (!result.success) {
        content.innerHTML = renderError(result.data && result.data.message ? result.data.message : (text.failedRates || 'Failed to load rates.'));
        return;
      }

      if (result.data.hasLabel) {
        result.data.message = result.data.message || 'This order already has a label. Reprint it or buy a replacement.';
      }

      renderRates(orderId, result.data);
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
        <p><strong>${escapeHtml(text.tracking || 'Tracking')}:</strong> ${escapeHtml(result.data.shipment.tracking_number)}</p>
        <p><a class="button button-primary" target="_blank" href="${escapeHtml(result.data.printUrl)}">${escapeHtml(text.printLabel || 'Print label')}</a></p>
      `;
      const card = document.querySelector(`[data-wlp-order-card][data-order-id="${CSS.escape(buyButton.getAttribute('data-order-id'))}"]`);
      if (card) {
        updateCardAfterLabel(card, result.data.printUrl);
      }
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
      const opened = window.open(url, '_blank', 'noopener');
      if (!opened) {
        window.location.href = url;
      }
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
          updateCardAfterLabel(card, result.data.printUrl);
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
  });

  document.addEventListener('change', (event) => {
    if (event.target.closest('[data-wlp-order-select]')) {
      updateSelectionCount();
    }
  });

  updateSelectionCount();
})();
