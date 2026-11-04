(() => {
  'use strict';
  const api = window.PESM_OWNERSHIP_ROOT;
  if (!api) return;
  async function send(path, payload, header = {}) {
    const response = await fetch(window.PESM_restURL(path, api), {
      method: 'POST', cache: 'no-store', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', ...header},
      body: JSON.stringify(payload),
    });
    const json = await response.json();
    if (!response.ok) throw Error(json.message || window.PESM_I18N.text("انجام عملیات ممکن نشد."));
    return json;
  }
  function newKey() {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, x => x.toString(16).padStart(2,'0')).join('');
  }
  document.querySelectorAll('.pesm-ownership-transfer').forEach(root => {
    const request = root.querySelector('.pesm-owner-request');
    const accept = root.querySelector('.pesm-owner-accept');
    const view = root.querySelector('.pesm-owner-view');
    const message = root.querySelector('.pesm-owner-message');
    const ticket = root.querySelector('.pesm-owner-ticket');
    const state = {pendingKey: null};
    request.addEventListener('submit', async event => {
      event.preventDefault();
      message.textContent = window.PESM_I18N.text("در حال ثبت درخواست انتقال...");
      try {
        // Reuse the same key when retrying the same form after a timeout.
        const signature = JSON.stringify([
          request.elements.type.value, request.elements.ticket_id.value,
          request.elements.recipient_name.value, request.elements.recipient_email.value,
        ]);
        if (!state.pendingKey || state.pendingKey.signature !== signature) {
          state.pendingKey = {signature, key: newKey()};
        }
        const data = await send('ticket-ownership/request', {
          type: request.elements.type.value, ticket_id: Number(request.elements.ticket_id.value),
          recipient_name: request.elements.recipient_name.value,
          recipient_email: request.elements.recipient_email.value,
          request_key: state.pendingKey.key,
        }, {'X-PESM-Guest-Token': request.elements.owner_token.value});
        accept.elements.transfer_id.value = data.transfer_id;
        message.textContent = window.PESM_I18N.text("درخواست ثبت شد. گیرنده باید کد ۸ رقمی ایمیل خود را وارد کند.");
      } catch (error) { message.textContent = error.message; }
    });
    accept.addEventListener('submit', async event => {
      event.preventDefault();
      message.textContent = window.PESM_I18N.text("در حال تأیید گیرنده...");
      try {
        const data = await send('ticket-ownership/accept', {
          transfer_id: Number(accept.elements.transfer_id.value), code: accept.elements.code.value,
        });
        accept.elements.code.value = '';
        request.elements.owner_token.value = '';
        view.elements.token.value = data.ownership_token;
        message.textContent = window.PESM_I18N.text("انتقال انجام شد. QR قبلی باطل است؛ توکن جدید فقط برای مالک تازه معتبر خواهد بود.");
        // No tokens in browser URL, localStorage, or console.
        view.requestSubmit();
      } catch (error) { message.textContent = error.message; }
    });
    view.addEventListener('submit', async event => {
      event.preventDefault();
      message.textContent = window.PESM_I18N.text("در حال دریافت بلیت...");
      try {
        const data = await send('ticket-ownership/view', {}, {'X-PESM-Ownership-Token': view.elements.token.value});
        ticket.replaceChildren();
        window.PESM_renderTickets(ticket, [data.ticket]);
        message.textContent = window.PESM_I18N.text("بلیت فعال قابل مشاهده است. توکن را محرمانه نگه دارید.");
      } catch (error) {ticket.replaceChildren();message.textContent = error.message; }
    });
  });
})();
