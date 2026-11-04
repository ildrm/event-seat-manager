/* WooCommerce Cart seat editor (engineering preview). Server holds own all inventory. */
(() => {
  'use strict';
  const cfg = window.PESM_CART_CONFIG;
  if (!cfg?.apiRoot || !cfg?.cartNonce) return;
  const endpoint = (p) => window.PESM_restURL(p, cfg.apiRoot);
  const request = async (path, opts = {}) => {
    const response = await fetch(endpoint(path), {
      credentials: 'same-origin', cache: 'no-store', ...opts,
      headers: {'X-PESM-Cart-Nonce': cfg.cartNonce, ...opts.headers}
    });
    const body = await response.json().catch(() => { throw new Error(window.PESM_I18N.text('پاسخ سامانه معتبر نیست. دوباره تلاش کنید.')); });
    if (!response.ok) throw new Error(body.message || window.PESM_I18N.text("درخواست سبد خرید انجام نشد."));
    return body;
  };
  const secureKey = () => {
    if (!window.crypto?.getRandomValues) throw Error(window.PESM_I18N.text("این مرورگر تولید شناسه امن ندارد."));
    return Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
  };
  const labelFor = (seat) => String(seat.seat_code || seat.id);
  document.querySelectorAll('.pesm-cart-entry').forEach((root) => {
    const key = root.dataset.cartKey;
    const sessionId = Number(root.dataset.session);
    const panel = root.querySelector('.pesm-cart-board');
    const save = root.querySelector('.pesm-cart-save');
    const load = root.querySelector('.pesm-cart-load');
    const summary = root.querySelector('.pesm-cart-summary');
    const status = root.querySelector('.pesm-cart-message');
    const form = root.querySelector('.pesm-cart-complete');
    let busy = false;
    let map = [], own = [], selected = new Set(), availability = new Map();
    let currentRequest = null;
    let zoom = 1;
    const message = (s) => { status.textContent = s; };
    const sorted = (values) => [...values].sort((a, b) => a - b);
    const changed = () => sorted(selected).join(',') !== own.join(',');
    const update = () => {
      const picked = map.filter(s => selected.has(Number(s.id))).map(labelFor);
      summary.textContent = (String(selected.size) + window.PESM_I18N.text(" صندلی: ") + String(picked.join(window.PESM_I18N.text("، ")) || window.PESM_I18N.text("هیچ")));
      save.disabled = busy || !selected.size || selected.size > 20 || !changed();
      load.disabled = busy;
      form.querySelector('button[type="submit"]').disabled = busy || changed();
    };
    const paint = () => {
      panel.replaceChildren();
      const toolbar = document.createElement('div'); toolbar.className = 'pesm-cart-toolbar';
      const viewport = document.createElement('div'); viewport.className = 'pesm-cart-viewport';
      viewport.tabIndex = 0;
      viewport.setAttribute('aria-label', window.PESM_I18N.text("نقشه سالن برای تغییر جایگاه در سبد خرید"));
      const stage = document.createElement('div'); stage.className = 'pesm-cart-stage';
      const width = Math.max(450, ...map.map(s => Number(s.pos_x) + 50));
      const height = Math.max(200, ...map.map(s => Number(s.pos_y) + 50));
      const scaleStage = () => {
        stage.style.width = `${Math.ceil(width * zoom)}px`;
        stage.style.height = `${Math.ceil(height * zoom)}px`;
      };
      const canvas = document.createElement('div'); canvas.className = 'pesm-cart-canvas';
      canvas.style.width = `${width}px`; canvas.style.height = `${height}px`;
      canvas.style.transformOrigin = 'top left';
      const setZoom = (v) => {
        zoom = Math.max(.35, Math.min(2.5, v));
        canvas.style.transform = `scale(${zoom})`; scaleStage();
      };
      const button = (title, onClick) => {
        const b = document.createElement('button'); b.type = 'button'; b.textContent = title;
        b.addEventListener('click', onClick); toolbar.append(b); return b;
      };
      button(window.PESM_I18N.text("بزرگ‌نمایی +"), () => setZoom(zoom * 1.25));
      button(window.PESM_I18N.text("کوچک‌نمایی −"), () => setZoom(zoom / 1.25));
      button(window.PESM_I18N.text("نمای کامل"), () => setZoom(Math.min(1.2, Math.max(.35, (viewport.clientWidth - 24) / width))));
      const search = document.createElement('input'); search.type = 'search'; search.placeholder = window.PESM_I18N.text("کد صندلی");
      search.setAttribute('aria-label', window.PESM_I18N.text("جست‌وجوی صندلی")); toolbar.append(search);
      button(window.PESM_I18N.text("یافتن"), () => {
        const match = map.find(s => labelFor(s).toUpperCase() === search.value.trim().toUpperCase());
        if (!match) { message(window.PESM_I18N.text("صندلی پیدا نشد.")); return; }
        setZoom(Math.max(zoom, .8));
        viewport.scrollLeft = Math.max(0, Number(match.pos_x) * zoom - viewport.clientWidth / 2);
        viewport.scrollTop = Math.max(0, Number(match.pos_y) * zoom - viewport.clientHeight / 2);
        [...canvas.children].find(b => Number(b.dataset.seatId) === Number(match.id))?.focus({preventScroll: true});
      });
      for (const seat of map) {
        const id = Number(seat.id);
        const mine = own.includes(id);
        const state = availability.get(id) || 'blocked';
        const accessible = mine || state === 'available';
        const b = document.createElement('button'); b.type = 'button';
        b.className = `pesm-cart-seat ${selected.has(id) ? 'pesm-cart-selected' : ''}`;
        b.dataset.seatId = String(id);
        b.style.left = `${Number(seat.pos_x)}px`; b.style.top = `${Number(seat.pos_y)}px`;
        b.textContent = labelFor(seat); b.title = (window.PESM_I18N.text("صندلی ") + String(labelFor(seat)) + window.PESM_I18N.text("؛ ") + String(mine ? window.PESM_I18N.text("رزرو شما") : window.PESM_I18N.status(state)));
        b.setAttribute('aria-pressed', String(selected.has(id)));
        b.disabled = !accessible;
        b.addEventListener('click', () => {
          if (busy) return;
          if (selected.has(id)) selected.delete(id); else if (selected.size < 20) selected.add(id);
          currentRequest = null;
          for (const c of canvas.children) {
            const checked = selected.has(Number(c.dataset.seatId));
            c.classList.toggle('pesm-cart-selected', checked);
            c.setAttribute('aria-pressed', String(checked));
          }
          update();
        });
        canvas.append(b);
      }
      stage.append(canvas); viewport.append(stage); panel.append(toolbar, viewport);
      setZoom(Math.min(1.2, Math.max(.35, (viewport.clientWidth - 24) / width)));
      update();
    };
    const reloadMap = async () => {
      const [mine, layout, states] = await Promise.all([
        request(`cart/preview?cart_key=${encodeURIComponent(key)}`),
        request(`sessions/${sessionId}/layout`), request(`sessions/${sessionId}/availability`)
      ]);
      if (Number(mine.session_id) !== sessionId) throw Error(window.PESM_I18N.text("جلسه سبد با رزرو برابر نیست."));
      own = sorted(mine.seat_ids.map(Number)); selected = new Set(own);
      map = Array.isArray(layout.seats) ? layout.seats : [];
      availability = new Map((states.availability || []).map(s => [Number(s.seat_id), String(s.status)]));
      panel.hidden = false; paint();
      message(window.PESM_I18N.text("صندلی‌های آبی رزرو فعلی شما هستند؛ تغییرات پس از ثبت سمت سرور اعمال می‌شوند."));
    };
    load.addEventListener('click', async () => {
      if (busy) return;
      busy = true; update(); message(window.PESM_I18N.text("در حال دریافت نقشه..."));
      try { await reloadMap(); } catch (e) { message(e.message); }
      finally { busy = false; update(); }
    });
    save.addEventListener('click', async () => {
      if (busy || !selected.size || !changed()) return;
      const signature = sorted(selected).join(',');
      if (!currentRequest || currentRequest.signature !== signature) {
        currentRequest = {signature, key: secureKey()};
      }
      busy = true; update();
      try {
        await request('cart/replace', {method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({cart_key: key, seat_ids: sorted(selected), request_key: currentRequest.key})});
        message(window.PESM_I18N.text("تغییر صندلی‌ها در سرور ثبت شد. جمع سبد به‌روزرسانی می‌شود."));
        location.reload();
      } catch (e) { message((String(e.message) + window.PESM_I18N.text(" در صورت خطای شبکه، درخواست را بدون تغییر انتخاب دوباره ارسال کنید."))); }
      finally { busy = false; update(); }
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy || (map.length && changed())) return;
      busy = true; update();
      try {
        const buyer = Object.fromEntries(new FormData(form).entries());
        const result = await request('cart/complete', {method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({cart_key: key, ...buyer})});
        form.hidden = true; panel.hidden = true; save.hidden = true;
        const resultBox = document.createElement('div'); resultBox.className = 'pesm-cart-order-result';
        const line = document.createElement('p'); line.textContent = (window.PESM_I18N.text("سفارش #") + String(result.order_id) + ": " + String(result.message));
        resultBox.append(line);
        if (result.guest_access_token) {
          const note = document.createElement('p'); note.dir = 'ltr';
          note.textContent = window.PESM_I18N.text('کلید دسترسی: ') + result.guest_access_token; resultBox.append(note);
        }
        if (result.payment_required && result.payment_url) {
          const url = new URL(result.payment_url, location.href);
          if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) throw Error(window.PESM_I18N.text("نشانی پرداخت معتبر نیست."));
          const a = document.createElement('a'); a.href = url.href; a.textContent = window.PESM_I18N.text("ادامه به پرداخت ووکامرس"); resultBox.append(a);
        }
        root.append(resultBox); message(window.PESM_I18N.text("ثبت سفارش انجام شد؛ کلید بازیابی را در محل امن نگهداری کنید."));
      } catch (e) { message(e.message); }
      finally { busy = false; update(); }
    });
    update();
  });
})();
