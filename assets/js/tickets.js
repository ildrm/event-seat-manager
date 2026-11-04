/* MIT QR generator is bundled locally; no CDN and no QR payload in URLs. */
(() => {
  'use strict';
  const apiRoot = window.PESM_TICKETS_ROOT || window.PESM_CONFIG?.apiRoot;
  function qrSvg(text) {
    if (!window.PESM_QRCode) return document.createTextNode(window.PESM_I18N.text("نمایش QR در دسترس نیست."));
    const qr = new window.PESM_QRCode(-1, 0);
    qr.addData(text); qr.make();
    const count = qr.getModuleCount(), xmlns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(xmlns, 'svg');
    svg.setAttribute('viewBox', `-4 -4 ${count + 8} ${count + 8}`);
    svg.setAttribute('width', '200'); svg.setAttribute('height', '200');
    svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', window.PESM_I18N.text("کد QR ورود بلیت"));
    svg.style.background = '#fff'; svg.style.shapeRendering = 'crispEdges';
    const path = document.createElementNS(xmlns, 'path');
    let shape = '';
    for (let y = 0; y < count; y++) for (let x = 0; x < count; x++) {
      if (qr.isDark(y, x)) shape += `M${x} ${y}h1v1h-1z`;
    }
    path.setAttribute('d', shape); path.setAttribute('fill', '#000'); svg.appendChild(path);
    return svg;
  }
  function showTickets(container, tickets) {
    container.replaceChildren();
    if (!tickets.length) {
      const empty = document.createElement('div'); empty.className = 'pesm-empty-state';
      empty.textContent = window.PESM_I18N.text("بلیت صادرشده‌ای برای این سفارش پیدا نشد.");
      container.append(empty); return;
    }
    for (const ticket of tickets) {
      const box = document.createElement('article'); box.className = 'pesm-ticket-card';
      const head = document.createElement('header'); head.className = 'pesm-ticket-head';
      const title = document.createElement('h4'); title.textContent = window.PESM_I18N.text("بلیت ") + String(ticket.ticket_number);
      const badge = document.createElement('span'); badge.className = 'pesm-badge'; badge.textContent = window.PESM_I18N.status(ticket.status);
      head.append(title, badge); box.append(head);
      const info = document.createElement('dl'); info.className = 'pesm-ticket-info';
      for (const [label, value] of [[window.PESM_I18N.text("نام شرکت‌کننده"), ticket.attendee_name],
        [window.PESM_I18N.text("جایگاه"), ticket.seat_code], [window.PESM_I18N.text("شناسه بلیت"), ticket.id]]) {
        const row = document.createElement('div'), term = document.createElement('dt'), definition = document.createElement('dd');
        term.textContent = label; definition.textContent = value ?? '—'; row.append(term, definition); info.append(row);
      }
      box.append(info);
      if (ticket.qr_payload) {
        const qr = document.createElement('div'); qr.className = 'pesm-ticket-qr'; qr.append(qrSvg(ticket.qr_payload)); box.append(qr);
      }
      container.append(box);
    }
    const print = document.createElement('button'); print.type = 'button'; print.className = 'pesm-ticket-print pesm-button-primary';
    print.textContent = window.PESM_I18N.text("چاپ بلیت‌ها");
    print.addEventListener('click', () => window.print()); container.append(print);
  }
  function showInvoice(container, orderId, invoice) {
    container.replaceChildren();
    if (!invoice) return;
    const heading = document.createElement('h4'); heading.textContent = (window.PESM_I18N.text("صورتحساب سفارش ") + String(orderId));
    container.append(heading);
    const created = invoice.date_created ? new Date(invoice.date_created) : null;
    const createdLabel = created && !Number.isNaN(created.getTime())
      ? created.toLocaleString(window.PESM_I18N.locale, {timeZone: 'Asia/Tehran'})
      : window.PESM_I18N.text('نامشخص');
    const rows = [
      [window.PESM_I18N.text("وضعیت سفارش"), window.PESM_I18N.status(invoice.order_status)],
      [window.PESM_I18N.text("مبلغ کل"), `${invoice.total} ${invoice.currency}`],
      [window.PESM_I18N.text("تخفیف"), `${invoice.discount_total} ${invoice.currency}`],
      [window.PESM_I18N.text("مالیات"), `${invoice.tax_total} ${invoice.currency}`],
      [window.PESM_I18N.text("مبلغ استرداد ثبت‌شده"), `${invoice.refunded_total} ${invoice.currency}`],
      [window.PESM_I18N.text("روش پرداخت"), invoice.payment_method || window.PESM_I18N.text("بدون درگاه")],
      [window.PESM_I18N.text("شناسه تراکنش"), invoice.transaction_id || window.PESM_I18N.text("ثبت نشده")],
      [window.PESM_I18N.text("تاریخ ثبت"), createdLabel],
    ];
    const table = document.createElement('table'); table.className = 'pesm-invoice-table';
    for (const [label, value] of rows) {
      const tr = document.createElement('tr');
      const th = document.createElement('th'); th.scope = 'row'; th.textContent = label;
      const td = document.createElement('td'); td.textContent = value;
      tr.append(th, td); table.append(tr);
    }
    const scroll = document.createElement('div'); scroll.className = 'pesm-table-scroll'; scroll.append(table); container.append(scroll);
  }
  async function retrieve(orderId, token) {
    const url = window.PESM_restURL(`tickets/me?order_id=${encodeURIComponent(orderId)}`, apiRoot);
    const response = await fetch(url, {credentials:'same-origin',cache:'no-store',headers:{'X-PESM-Guest-Token':token}});
    const data = await response.json();
    if (!response.ok) throw Error(data.message || window.PESM_I18N.text("دسترسی به بلیت ممکن نیست."));
    return data;
  }
  const pending = (form, value) => {
    form.dataset.pending = value ? 'true' : 'false';
    form.setAttribute('aria-busy', String(value));
    form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = value; });
  };
  document.querySelectorAll('.pesm-tickets-panel').forEach(panel => {
    const form = panel.querySelector('form'), message = panel.querySelector('.pesm-ticket-message');
    const results = panel.querySelector('.pesm-ticket-results');
    const invoice = panel.querySelector('.pesm-invoice-results');
    form.addEventListener('submit', async e => {
      e.preventDefault(); if (form.dataset.pending === 'true') return;
      pending(form, true); message.textContent = window.PESM_I18N.text("در حال دریافت...");
      try {
        const data = await retrieve(Number(form.elements.order_id.value), form.elements.guest_token.value);
        message.textContent = data.fulfillment_status === 'issued' ? window.PESM_I18N.text("بلیت‌های شما آماده هستند.") : window.PESM_I18N.text("سفارش هنوز تأیید نهایی نشده است.");
        showInvoice(invoice, data.order_id, data.invoice);
        showTickets(results, data.tickets);
      } catch (err) { message.textContent = err.message; results.replaceChildren(); invoice?.replaceChildren(); }
      finally { pending(form, false); }
    });
    const recoveryRequest = panel.querySelector('.pesm-recovery-request');
    const recoveryVerify = panel.querySelector('.pesm-recovery-verify');
    const recoveryMessage = panel.querySelector('.pesm-recovery-message');
    async function recoveryPost(path, body) {
      const response = await fetch(window.PESM_restURL(path, apiRoot), {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type':'application/json'}, body: JSON.stringify(body),
      });
      const data = await response.json();
      if (!response.ok) throw Error(data.message || window.PESM_I18N.text("بازیابی انجام نشد."));
      return data;
    }
    recoveryRequest?.addEventListener('submit', async e => {
      e.preventDefault(); if (recoveryRequest.dataset.pending === 'true') return;
      pending(recoveryRequest, true); recoveryMessage.textContent = window.PESM_I18N.text("در حال بررسی درخواست...");
      const orderId = Number(recoveryRequest.elements.order_id.value);
      try {
        const data = await recoveryPost('guest-access/request', {
          order_id: orderId, email: recoveryRequest.elements.email.value,
        });
        recoveryVerify.elements.order_id.value = orderId;
        recoveryVerify.hidden = false;
        recoveryVerify.elements.code.focus();
        recoveryMessage.textContent = data.message;
      } catch (err) { recoveryMessage.textContent = err.message; }
      finally { pending(recoveryRequest, false); }
    });
    recoveryVerify?.addEventListener('submit', async e => {
      e.preventDefault(); if (recoveryVerify.dataset.pending === 'true') return;
      pending(recoveryVerify, true); recoveryMessage.textContent = window.PESM_I18N.text("در حال تأیید کد...");
      const orderId = Number(recoveryVerify.elements.order_id.value);
      try {
        const data = await recoveryPost('guest-access/verify', {
          order_id: orderId, code: recoveryVerify.elements.code.value,
        });
        recoveryVerify.elements.code.value = '';
        form.elements.order_id.value = orderId;
        form.elements.guest_token.value = data.guest_access_token;
        // The recovered bearer is intentionally not added to the URL or persistent storage.
        recoveryMessage.textContent = window.PESM_I18N.text("تأیید شد. دسترسی موقت حداکثر ۲۴ ساعت اعتبار دارد.");
        form.requestSubmit();
      } catch (err) { recoveryMessage.textContent = err.message; }
      finally { pending(recoveryVerify, false); }
    });
    try {
      const cached = JSON.parse(sessionStorage.getItem('pesm-last-order') || 'null');
      if (cached?.order_id && cached?.guest_access_token) {
        form.elements.order_id.value = cached.order_id;
        form.elements.guest_token.value = cached.guest_access_token;
      }
    } catch (_) { /* Storage unavailable; manual token entry stays available. */ }
  });
  window.PESM_renderTickets = showTickets;
  window.PESM_retrieveTickets = retrieve;
})();
