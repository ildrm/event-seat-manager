/* General-admission hold -> WooCommerce order -> free ticket or paid checkout. */
(() => {
  'use strict';
  const root = window.PESM_CAPACITY_ROOT;
  if (!root) return;
  const request = async (path, options = {}) => {
    const response = await fetch(window.PESM_restURL(path, root), {
      credentials: 'same-origin', cache: 'no-store', ...options
    });
    const payload = await response.json().catch(() => { throw new Error(window.PESM_I18N.text('پاسخ سامانه معتبر نیست. دوباره تلاش کنید.')); });
    if (!response.ok) throw new Error(payload.message || window.PESM_I18N.text("ارتباط با سامانه برقرار نشد."));
    return payload;
  };
  const requestKey = () => {
    if (!window.crypto?.getRandomValues) throw new Error(window.PESM_I18N.text("برای ثبت درخواست امن، مرورگر HTTPS لازم است."));
    const bytes = new Uint8Array(24);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, v => v.toString(16).padStart(2, '0')).join('');
  };
  document.querySelectorAll('.pesm-capacity').forEach(el => {
    const sessionId = Number(el.dataset.session);
    const counts = el.querySelector('.pesm-capacity-counts');
    const result = el.querySelector('.pesm-capacity-result');
    const quantity = el.querySelector('.pesm-capacity-quantity');
    const holdButton = el.querySelector('.pesm-capacity-hold');
    const releaseButton = el.querySelector('.pesm-capacity-release');
    const refreshButton = el.querySelector('.pesm-capacity-refresh');
    const checkout = el.querySelector('.pesm-capacity-checkout');
    const attendeeFields = window.PESM_Attendees.mount(checkout);
    const receipt = el.querySelector('.pesm-capacity-receipt');
    let hold = null, busy = false, paidDisabled = false, inFlight = null, inFlightCount = 0, tickTimer = null;
    const toggle = () => {
      if (!hold && tickTimer) { window.clearInterval(tickTimer); tickTimer = null; }
      holdButton.disabled = busy || Boolean(hold) || paidDisabled;
      releaseButton.disabled = busy || !hold;
      quantity.disabled = busy || Boolean(hold);
      refreshButton.disabled = busy;
      checkout.hidden = !hold;
      checkout.querySelector('button[type=submit]').disabled = busy;
    };
    const refresh = async () => {
      const capacity = await request(`sessions/${sessionId}/capacity`);
      const decimals = Math.max(0, Math.min(6, Number(capacity.currency_decimals) || 0));
      const minor = Math.max(0, Number(capacity.unit_price_minor) || 0);
      const amount = minor === 0 ? window.PESM_I18N.text("رایگان") : `${(minor / 10 ** decimals).toLocaleString(window.PESM_I18N.locale)} ${capacity.currency || ''}`;
      counts.textContent = (window.PESM_I18N.text("کل ظرفیت: ") + String(capacity.total) + window.PESM_I18N.text(" | آزاد: ") + String(capacity.available) + window.PESM_I18N.text(" | نگهداری موقت: ") + String(capacity.held) + window.PESM_I18N.text(" | قیمت هر نفر: ") + String(amount));
      paidDisabled = minor > 0 && !capacity.paid_preview_enabled;
      toggle();
      if (paidDisabled && !hold) result.textContent = window.PESM_I18N.text("پرداخت این جلسه در نسخه آزمایشی غیرفعال است.");
    };
    const tick = () => {
      if (!hold) return;
      const seconds = Math.max(0, Math.ceil((Date.parse(hold.expires_at_utc.replace(' ', 'T') + 'Z') - (Date.now() + (hold.serverOffset || 0))) / 1000));
      if (seconds === 0) {
        result.textContent = window.PESM_I18N.text("مهلت رزرو موقت به پایان رسید. برای بررسی موجودی، تازه‌سازی کنید.");
        hold = null; toggle();
      } else {
        result.textContent = (String(hold.quantity) + window.PESM_I18N.text(" جایگاه موقتاً نگهداری شده؛ حدود ") + String(seconds) + window.PESM_I18N.text(" ثانیه باقی‌مانده است. برای ادامه ثبت سفارش فرم را تکمیل کنید."));
      }
    };
    const setBusy = value => { busy = value; toggle(); };
    holdButton.addEventListener('click', async () => {
      const count = Number(quantity.value);
      if (!Number.isInteger(count) || count < 1 || count > 20) {
        result.textContent = window.PESM_I18N.text("تعداد باید عدد صحیح بین ۱ تا ۲۰ باشد.");
        return;
      }
      setBusy(true);
      let acquired = false;
      try {
        if (!inFlight || inFlightCount !== count) { inFlight = requestKey(); inFlightCount = count; }
        const data = await request('capacity/holds', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({session_id: sessionId, quantity: count, idempotency_key: inFlight})
        });
        hold = data;
        acquired = true;
        attendeeFields.set(Array.from({length: data.quantity}, (_, index) => ({
          admission_index: index + 1, label: (window.PESM_I18N.text("بلیت شماره ") + String(index + 1))
        })));
        hold.serverOffset = Date.parse(data.server_time_utc.replace(' ', 'T') + 'Z') - Date.now();
        inFlight = null;
        tick();
        if (!tickTimer) tickTimer = window.setInterval(tick, 1000);
        await refresh();
      } catch (error) { result.textContent = error.message; }
      finally {
        setBusy(false);
        if (acquired && hold && !checkout.hidden) {
          checkout.querySelector('input[name="first_name"]')?.focus({preventScroll: true});
          (checkout.querySelector('.pesm-page-heading') || checkout).scrollIntoView({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'
          });
        }
      }
    });
    releaseButton.addEventListener('click', async () => {
      if (!hold) return;
      setBusy(true);
      try {
        await request('capacity/holds', {
          method: 'DELETE', headers: {'Content-Type': 'application/json', 'X-PESM-Hold-Token': hold.token},
          body: JSON.stringify({session_id: sessionId})
        });
        hold = null;
        result.textContent = window.PESM_I18N.text("ظرفیت موقت آزاد شد.");
        await refresh();
      } catch (error) { result.textContent = error.message; }
      finally { setBusy(false); }
    });
    checkout.addEventListener('submit', async event => {
      event.preventDefault();
      if (!hold) return;
      const data = Object.fromEntries(new FormData(checkout).entries());
      data.session_id = sessionId;
      try { const attendees = attendeeFields.payload(); if (attendees) data.attendees = attendees; }
      catch (error) { result.textContent = error.message; return; }
      setBusy(true);
      try {
        const order = await request('checkout/prepare', {
          method: 'POST', headers: {'Content-Type': 'application/json', 'X-PESM-Hold-Token': hold.token},
          body: JSON.stringify(data)
        });
        // Never put the guest bearer token in a URL or an HTML attribute.
        try { sessionStorage.setItem('pesm-last-order', JSON.stringify({
          order_id: order.order_id, guest_access_token: order.guest_access_token
        })); } catch (_) { /* Storage may be disabled. */ }
        hold = null;
        checkout.hidden = true;
        result.textContent = order.message || window.PESM_I18N.text("سفارش ثبت شد.");
        receipt.replaceChildren();
        const title = document.createElement('p');
        title.textContent = (window.PESM_I18N.text("شماره سفارش: ") + String(order.order_id) + window.PESM_I18N.text(" — کلید بازیابی را نزد خود نگه دارید."));
        receipt.append(title);
        const token = document.createElement('code');
        token.dir = 'ltr'; token.textContent = order.guest_access_token;
        receipt.append(token);
        if (order.payment_required) {
          if (typeof order.payment_url === 'string' && /^https?:\/\//.test(order.payment_url)) {
            const link = document.createElement('a');
            const target = new URL(order.payment_url, location.href);
            if (target.origin !== location.origin) throw new Error(window.PESM_I18N.text('نشانی پرداخت معتبر نیست.'));
            link.href = target.href;
            link.rel = 'noopener noreferrer';
            link.textContent = window.PESM_I18N.text("ادامه برای پرداخت در درگاه ووکامرس");
            receipt.append(link);
          }
          await refresh();
          return;
        }
        const tickets = document.createElement('div'); receipt.append(tickets);
        if (window.PESM_retrieveTickets && window.PESM_renderTickets) {
          const info = await window.PESM_retrieveTickets(order.order_id, order.guest_access_token);
          window.PESM_renderTickets(tickets, info.tickets || []);
        }
        await refresh();
      } catch (error) { result.textContent = error.message; }
      finally { setBusy(false); }
    });
    refreshButton.addEventListener('click', () => {
      refresh().catch(error => { result.textContent = error.message; });
    });
    window.addEventListener('pagehide', () => { if (tickTimer) window.clearInterval(tickTimer); tickTimer = null; });
    window.addEventListener('pageshow', async event => {
      if (!event.persisted) return;
      setBusy(true);
      try { tick(); await refresh(); if (hold && !tickTimer) tickTimer = window.setInterval(tick, 1000); }
      catch (error) { result.textContent = error.message; }
      finally { setBusy(false); }
    });
    toggle();
    const disclosure = el.closest('details');
    const load = () => refresh().catch(error => { result.textContent = error.message; });
    if (disclosure) disclosure.addEventListener('toggle', () => { if (disclosure.open) load(); });
    if (!disclosure || disclosure.open) load();
  });
})();
