/* Engineering preview: holds, WooCommerce order handoff and guest ticket retrieval. */
(() => {
  'use strict';
  const root = window.PESM_CONFIG?.apiRoot;
  if (!root) return;
  const statusFa = {
    available: window.PESM_I18N.text("آزاد"), held: window.PESM_I18N.text("رزرو موقت"), sold: window.PESM_I18N.text("فروخته‌شده"),
    payment_pending: window.PESM_I18N.text("در انتظار پرداخت"), blocked: window.PESM_I18N.text("مسدود")
  };
  const fetchJSON = async (path, options = {}) => {
    const response = await fetch(window.PESM_restURL(path, root), {
      credentials: 'same-origin', cache: 'no-store', ...options
    });
    const result = await response.json().catch(() => { throw new Error(window.PESM_I18N.text('پاسخ سامانه معتبر نیست. دوباره تلاش کنید.')); });
    if (!response.ok) throw new Error(result.message || window.PESM_I18N.text("ارتباط با سامانه برقرار نشد."));
    return result;
  };
  document.querySelectorAll('.pesm-map').forEach((el) => {
    const id = Number(el.dataset.session);
    const disclosure = el.closest('details');
    let initialized = false, initializing = false;
    const viewport = el.querySelector('.pesm-viewport');
    const canvas = el.querySelector('.pesm-canvas');
    const stage = el.querySelector('.pesm-stage');
    const feedback = el.querySelector('.pesm-feedback');
    const selectedLabel = el.querySelector('.pesm-selected');
    const ttlLabel = el.querySelector('.pesm-ttl');
    const holdButton = el.querySelector('.pesm-hold');
    const releaseButton = el.querySelector('.pesm-release');
    const replaceButton = el.querySelector('.pesm-replace');
    const renewButton = el.querySelector('.pesm-renew');
    const cartButton = el.querySelector('.pesm-add-cart');
    const checkoutForm = el.querySelector('.pesm-checkout-form');
    const attendeeFields = window.PESM_Attendees.mount(checkoutForm);
    const receipt = el.querySelector('.pesm-receipt');
    const seatStates = new Map();
    const minimumScale = window.matchMedia?.('(pointer: coarse)').matches ? 0.8 : 0.3;
    let focusSeatId = null;
    const selected = new Set();
    let seats = [], objects = [], scale = 1, baseWidth = 0, baseHeight = 0, unitPriceMinor = 0, currencyDecimals = 0, currency = '';
    let hold = null, pendingKey = null, pendingSignature = null, pendingMutation = null, busy = false;
    let serverOffset = 0, pollDelay = 15000, pollTimeout = null, alive = true;
    const setFeedback = (message) => { feedback.textContent = message; };
    const isDirty = () => Boolean(hold) && (selected.size !== hold.seat_ids.length || [...selected].some(s => !hold.seat_ids.includes(s)));
    const updateButtons = () => {
      // Clear immediately on release/expiry/checkout; background timer throttling
      // must not leave a stale reservation countdown visible.
      if (!hold) ttlLabel.textContent = '';
      replaceButton.disabled = busy || !hold || selected.size === 0 || !isDirty();
      renewButton.disabled = busy || !hold || isDirty();
      holdButton.disabled = busy || selected.size === 0 || Boolean(hold);
      releaseButton.disabled = busy || !hold;
      const leaseActions = el.querySelector('.pesm-lease-actions');
      if (leaseActions) leaseActions.hidden = !hold;
      if (cartButton) { cartButton.disabled = busy || !hold || isDirty(); cartButton.hidden = !hold; }
      for (const [sid, node] of seatNodes) node.disabled = busy || (seatStates.get(sid) !== 'available' && !hold?.seat_ids.includes(sid));
      checkoutForm.hidden = !hold;
      const phase = hold ? 2 : (selected.size ? 1 : (receipt.childElementCount ? 3 : 1));
      el.querySelectorAll('[data-booking-step]').forEach(step => {
        const number = Number(step.dataset.bookingStep);
        step.classList.toggle('is-current', number === phase);
        step.classList.toggle('is-complete', number < phase);
        if (number === phase) step.setAttribute('aria-current', 'step');
        else step.removeAttribute('aria-current');
      });
      const submit = checkoutForm.querySelector('button[type=submit]');
      if (submit) submit.disabled = busy || !hold || isDirty();
    };
    const label = () => {
      selectedLabel.textContent = selected.size
        ? (String(selected.size) + window.PESM_I18N.text(" صندلی انتخاب شده: ") + String(seats.filter(s => selected.has(Number(s.id))).map(s => s.seat_code).join(window.PESM_I18N.text("، "))))
        : window.PESM_I18N.text("هیچ صندلی انتخاب نشده است.");
      const totalMinor = unitPriceMinor * selected.size;
      const total = totalMinor / (10 ** currencyDecimals);
      const format = new Intl.NumberFormat(window.PESM_I18N.locale, {maximumFractionDigits: currencyDecimals});
      const price = el.querySelector('.pesm-price');
      const caption = document.createElement('span'); caption.className = 'pesm-price-caption';
      caption.textContent = window.PESM_I18N.text("جمع انتخاب شما");
      const value = document.createElement('strong'); value.className = 'pesm-price-total';
      value.textContent = `${format.format(total)} ${currency}`;
      const unit = document.createElement('small'); unit.className = 'pesm-price-unit';
      unit.textContent = window.PESM_I18N.text("قیمت هر صندلی: ") + format.format(unitPriceMinor / (10 ** currencyDecimals)) + " " + currency;
      price.replaceChildren(caption, value, unit);
      updateButtons();
    };
    const drawScale = () => {
      canvas.style.transform = `scale(${scale})`;
      stage.style.width = `${Math.ceil(baseWidth * scale)}px`;
      stage.style.height = `${Math.ceil(baseHeight * scale)}px`;
      el.querySelector('.pesm-scale-label').textContent = `${Math.round(scale * 100)}%`;
    };
    const setScale = (next) => { scale = Math.max(minimumScale, Math.min(3, next)); drawScale(); };
    const fit = () => setScale(Math.min(1.5, (viewport.clientWidth - 20) / Math.max(baseWidth, 1)));
    el.querySelector('.pesm-zoom-in').addEventListener('click', () => setScale(scale + 0.2));
    el.querySelector('.pesm-zoom-out').addEventListener('click', () => setScale(scale - 0.2));
    el.querySelector('.pesm-zoom-reset').addEventListener('click', fit);
    // Ctrl+wheel is an optional convenience; normal touch scrolling remains enabled.
    viewport.addEventListener('wheel', (e) => {
      if (!e.ctrlKey) return;
      e.preventDefault(); setScale(scale + (e.deltaY < 0 ? 0.1 : -0.1));
    }, {passive: false});
    const seatNodes = new Map();
    let geometryDrawn = false;
    const render = () => {
      const activeSeat = canvas.contains(document.activeElement) ? document.activeElement?.dataset.seatId : null;
      if (activeSeat) focusSeatId = Number(activeSeat);
      const enabledSeatIds = seats.filter(seat => (seatStates.get(Number(seat.id)) || 'blocked') === 'available' || hold?.seat_ids.includes(Number(seat.id))).map(seat => Number(seat.id));
      if (!enabledSeatIds.includes(focusSeatId)) focusSeatId = enabledSeatIds[0] ?? null;
      const fragment = document.createDocumentFragment();
      if (!geometryDrawn) objects.forEach(o => {
        const node = document.createElement('div');
        node.className = 'pesm-layout-object';
        node.setAttribute('role', 'img');
        node.setAttribute('aria-label', o.label || o.type);
        node.style.left = `${Number(o.x)}px`;
        node.style.top = `${Number(o.y)}px`;
        node.style.width = `${Number(o.width)}px`;
        node.style.height = `${Number(o.height)}px`;
        if (o.type === 'round_table') node.style.borderRadius = '50%';
        node.textContent = String(o.label || '');
        fragment.appendChild(node);
      });
      seats.forEach(seat => {
        const seatId = Number(seat.id);
        const state = seatStates.get(seatId) || 'blocked';
        const owned = Boolean(hold && hold.seat_ids.includes(seatId));
        let button = seatNodes.get(seatId);
        const created = !button;
        if (!button) { button = document.createElement('button'); seatNodes.set(seatId, button); }
        button.type = 'button';
        button.dataset.seatId = String(seatId);
        button.tabIndex = seatId === focusSeatId ? 0 : -1;
        if (created) button.addEventListener('focus', () => {
          focusSeatId = seatId;
          for (const node of canvas.querySelectorAll('.pesm-seat')) node.tabIndex = node === button ? 0 : -1;
        });
        button.className = `pesm-seat pesm-state-${statusFa[state] ? state : 'blocked'}`;
        if (selected.has(seatId)) button.classList.add('pesm-is-selected');
        if (owned) button.classList.add('pesm-is-owned');
        button.style.left = `${Number(seat.pos_x)}px`;
        button.style.top = `${Number(seat.pos_y)}px`;
        button.textContent = String(seat.seat_number);
        const status = owned ? window.PESM_I18N.text("نگهداری‌شده توسط شما") : statusFa[state] || window.PESM_I18N.text("نامشخص");
        button.title = `${seat.seat_code}: ${status}`;
        button.setAttribute('aria-label', (String(seat.zone_label || '') + '، ' + String(seat.seat_code) + '، ' + window.PESM_I18N.text("ردیف ") + String(seat.row_label) + window.PESM_I18N.text("، صندلی ") + String(seat.seat_number) + window.PESM_I18N.text("، ") + String(status)));
        button.setAttribute('aria-pressed', String(selected.has(seatId)));
        button.disabled = busy || (state !== 'available' && !owned);
        if (created) button.addEventListener('click', () => {
          if (busy) return;
          if (selected.has(seatId)) selected.delete(seatId);
          else if (selected.size < 20) selected.add(seatId);
          else setFeedback(window.PESM_I18N.text("حداکثر ۲۰ صندلی در هر سفارش قابل نگهداری است."));
          render(); label();
        });
        if (created) fragment.appendChild(button);
      });
      if (!geometryDrawn) canvas.replaceChildren(fragment);
      else if (fragment.childNodes.length) canvas.append(fragment);
      geometryDrawn = true;
      if (activeSeat) canvas.querySelector(`[data-seat-id="${focusSeatId}"]`)?.focus({preventScroll: true});
      label();
    };
    // One tab stop avoids forcing keyboard users through hundreds of seats.
    canvas.addEventListener('keydown', (event) => {
      const directions = {ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1]};
      const vector = directions[event.key];
      const current = seats.find(seat => Number(seat.id) === Number(event.target.dataset.seatId));
      if (!vector || !current) return;
      event.preventDefault();
      const candidates = seats.filter(seat => {
        const sid = Number(seat.id);
        const available = seatStates.get(sid) === 'available' || hold?.seat_ids.includes(sid);
        const forward = (Number(seat.pos_x) - Number(current.pos_x)) * vector[0] +
          (Number(seat.pos_y) - Number(current.pos_y)) * vector[1];
        return available && forward > 0;
      }).map(seat => {
        const dx = Number(seat.pos_x) - Number(current.pos_x), dy = Number(seat.pos_y) - Number(current.pos_y);
        return {seat, score: Math.abs(dx * vector[0] + dy * vector[1]) + 4 * Math.abs(dx * vector[1] - dy * vector[0])};
      }).sort((a, b) => a.score - b.score);
      if (candidates.length) canvas.querySelector(`[data-seat-id="${Number(candidates[0].seat.id)}"]`)?.focus();
    });
    const refresh = async () => {
      const result = await fetchJSON(`sessions/${id}/availability`);
      if (!Array.isArray(result.availability)) throw new Error(window.PESM_I18N.text('پاسخ سامانه معتبر نیست. دوباره تلاش کنید.'));
      seatStates.clear();
      result.availability.forEach(s => seatStates.set(Number(s.seat_id), s.status));
      if (!hold) [...selected].forEach(seatId => {
        if (seatStates.get(seatId) !== 'available') selected.delete(seatId);
      });
      if (hold) {
        const owned = hold.seat_ids.every(seatId => {
          const state = seatStates.get(seatId);
          return state === 'held';
        });
        if (!owned) setFeedback(window.PESM_I18N.text("وضعیت صندلی‌های نگهداری‌شده تغییر کرده است؛ اعتبار نگهداری را بررسی کنید."));
      }
      render();
    };
    const tick = () => {
      if (!hold) { ttlLabel.textContent = ''; return; }
      const remaining = Math.max(0, Math.ceil((Date.parse(hold.expires_at_utc) - (Date.now() + serverOffset)) / 1000));
      ttlLabel.textContent = (window.PESM_I18N.text("زمان تقریبی باقی‌مانده: ") + String(remaining) + window.PESM_I18N.text(" ثانیه. اعتبار نهایی با سرور است."));
      if (remaining === 0) {
        hold = null;
        selected.clear(); label();
        setFeedback(window.PESM_I18N.text("مهلت نگهداری به پایان رسید؛ وضعیت صندلی‌ها را دوباره بررسی کنید."));
        refresh().catch(e => setFeedback(e.message));
      }
    };
    const find = () => {
      const query = el.querySelector('.pesm-search').value.trim().toUpperCase().replace(/\s+/g, '');
      const seat = seats.find(s => String(s.seat_code).toUpperCase() === query);
      if (!seat) { setFeedback(window.PESM_I18N.text("صندلی با کد واردشده پیدا نشد. نمونه: A-12")); return; }
      setScale(Math.max(1, scale));
      const button = [...canvas.children].find(b => Number(b.dataset.seatId) === Number(seat.id));
      viewport.scrollLeft = Math.max(0, Number(seat.pos_x) * scale - viewport.clientWidth / 2);
      viewport.scrollTop = Math.max(0, Number(seat.pos_y) * scale - viewport.clientHeight / 2);
      button?.focus({preventScroll: true});
      setFeedback((window.PESM_I18N.text("صندلی ") + String(seat.seat_code) + window.PESM_I18N.text(" پیدا شد.")));
    };
    el.querySelector('.pesm-find').addEventListener('click', find);
    el.querySelector('.pesm-search').addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); find(); }
    });
    el.querySelector('.pesm-refresh').addEventListener('click', () => {
      if (!initialized) { initialize(); return; }
      refresh().catch(e => setFeedback(e.message));
    });
    el.querySelector('.pesm-suggest').addEventListener('click', async () => {
      if (busy || hold) { setFeedback(window.PESM_I18N.text("ابتدا رزرو فعلی را پایان دهید.")); return; }
      const count = Number(el.querySelector('.pesm-suggest-count').value);
      if (!Number.isInteger(count) || count < 1 || count > 20) { setFeedback(window.PESM_I18N.text("تعداد باید بین ۱ تا ۲۰ باشد.")); return; }
      busy = true; updateButtons();
      try {
        const data = await fetchJSON(`sessions/${id}/suggest?count=${count}`);
        await refresh();
        const choice = data.suggestions.find(group => group.every(sid => seatStates.get(Number(sid)) === 'available'));
        if (!choice) { setFeedback(window.PESM_I18N.text("در این لحظه مجموعه صندلی مجاور مناسب یافت نشد.")); return; }
        selected.clear(); choice.forEach(sid => selected.add(Number(sid)));
        pendingKey = null; pendingSignature = null;
        render();
        setFeedback(window.PESM_I18N.text("صندلی‌ها پیشنهاد شدند؛ موجودی تنها بعد از «نگهداری موقت» رزرو خواهد شد."));
      } catch (error) { setFeedback(error.message); }
      finally { busy = false; render(); updateButtons(); }
    });
    holdButton.addEventListener('click', async () => {
      if (busy || !selected.size || hold) return;
      if (!window.crypto?.getRandomValues) { setFeedback(window.PESM_I18N.text("این مرورگر از تولید شناسه امن پشتیبانی نمی‌کند.")); return; }
      const seatIds = [...selected].sort((a,b) => a-b);
      const signature = seatIds.join(',');
      if (!pendingKey || pendingSignature !== signature) {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        pendingKey = Array.from(bytes, b => b.toString(16).padStart(2,'0')).join('');
        pendingSignature = signature;
      }
      busy = true; updateButtons();
      let acquired = false;
      try {
        const start = Date.now();
        const result = await fetchJSON('holds', {method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({session_id: id, seat_ids: seatIds, idempotency_key: pendingKey})});
        // Approximate server/client clock difference, including a fraction of network transit time.
        serverOffset = Date.parse(result.server_now_utc) - (start + (Date.now() - start) / 2);
        hold = result;
        acquired = true;
        attendeeFields.set(result.seat_ids.map(seatId => ({
          seat_id: Number(seatId),
          label: (window.PESM_I18N.text("صندلی ") + String(seats.find(s => Number(s.id) === Number(seatId))?.seat_code || seatId))
        })));
        pendingKey = null; pendingSignature = null;
        setFeedback(window.PESM_I18N.text("نگهداری ثبت شد؛ اطلاعات خریدار را کامل کنید تا سفارش ساخته شود."));
        await refresh(); tick();
      } catch (error) {
        setFeedback((String(error.message) + window.PESM_I18N.text(" با همین انتخاب دوباره تلاش کنید.")));
      } finally {
        busy = false; render(); updateButtons();
        // Follow the user's successful reservation into the newly revealed step.
        // Polling and inventory rerenders never move keyboard focus or scroll.
        if (acquired && hold && !checkoutForm.hidden) {
          checkoutForm.querySelector('input[name="first_name"]')?.focus({preventScroll: true});
          (checkoutForm.querySelector('.pesm-page-heading') || checkoutForm).scrollIntoView({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'
          });
        }
      }
    });
    const couponPreview = checkoutForm.querySelector('.pesm-coupon-preview');
    if (couponPreview) couponPreview.addEventListener('click', async () => {
      const resultLabel = checkoutForm.querySelector('.pesm-coupon-result');
      const code = String(checkoutForm.elements.namedItem('coupon_code')?.value || '').trim();
      if (!code) { resultLabel.textContent = window.PESM_I18N.text("کد تخفیف وارد نشده است."); return; }
      if (!hold || isDirty()) { resultLabel.textContent = window.PESM_I18N.text("ابتدا صندلی‌ها را نگهداری و انتخاب را ثبت کنید."); return; }
      couponPreview.disabled = true;
      try {
        const estimate = await fetchJSON('checkout/discount-quote', {
          method: 'POST', headers: {'Content-Type': 'application/json', 'X-PESM-Hold-Token': hold.token},
          body: JSON.stringify({session_id: id, coupon_code: code})
        });
        const formatter = new Intl.NumberFormat(window.PESM_I18N.locale, {maximumFractionDigits: currencyDecimals});
        const fmt = (minor) => formatter.format(Number(minor) / (10 ** currencyDecimals));
        resultLabel.textContent = (window.PESM_I18N.text("مبلغ پایه: ") + String(fmt(estimate.base_minor)) + window.PESM_I18N.text("، تخفیف: ") + String(fmt(estimate.discount_minor)) + window.PESM_I18N.text("، جمع تخمینی: ") + String(fmt(estimate.payable_minor)) + " " + String(currency) + window.PESM_I18N.text(". در ثبت سفارش دوباره بررسی می‌شود."));
      } catch (err) { resultLabel.textContent = err.message || window.PESM_I18N.text("بررسی تخفیف ممکن نشد."); }
      finally { couponPreview.disabled = false; }
    });
    checkoutForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!hold || busy) return;
      busy = true; updateButtons();
      try {
        const buyer = Object.fromEntries(new FormData(checkoutForm).entries());
        const attendees = attendeeFields.payload();
        if (attendees) buyer.attendees = attendees;
        const result = await fetchJSON('checkout/prepare', {method: 'POST', headers: {
          'Content-Type': 'application/json', 'X-PESM-Hold-Token': hold.token
        }, body: JSON.stringify({session_id: id, ...buyer})});
        try { sessionStorage.setItem('pesm-last-order', JSON.stringify(result)); } catch (_) {}
        hold = null; selected.clear(); label();
        checkoutForm.hidden = true; receipt.hidden = false; receipt.replaceChildren();
        const headline = document.createElement('h4'); headline.textContent = (window.PESM_I18N.text("سفارش شماره ") + String(result.order_id));
        receipt.append(headline);
        const status = document.createElement('p'); status.textContent = result.message; receipt.append(status);
        const keyLabel = document.createElement('p'); keyLabel.textContent = window.PESM_I18N.text("کلید دسترسی بلیت‌ها");
        const key = document.createElement('code'); key.className = 'pesm-access-key'; key.dir = 'ltr'; key.textContent = result.guest_access_token;
        receipt.append(keyLabel, key);
        const hint = document.createElement('p');
        hint.textContent = window.PESM_I18N.text("کلید دسترسی را در محل امن نگهداری کنید؛ برای بازیابی بلیت‌ها به آن نیاز دارید.");
        receipt.append(hint);
        if (result.payment_required) {
          const link = document.createElement('a');
          link.textContent = window.PESM_I18N.text("ادامه به پرداخت امن ووکامرس");
          const payUrl = new URL(result.payment_url, location.href);
          if (payUrl.origin !== location.origin || !['http:', 'https:'].includes(payUrl.protocol)) {
            throw Error(window.PESM_I18N.text("نشانی پرداخت معتبر نیست."));
          }
          link.className = 'pesm-button pesm-button-primary'; link.href = payUrl.href; receipt.append(link);
        } else {
          const ticketData = await window.PESM_retrieveTickets(result.order_id, result.guest_access_token);
          const ticketBox = document.createElement('div'); receipt.append(ticketBox);
          window.PESM_renderTickets(ticketBox, ticketData.tickets);
        }
        setFeedback(window.PESM_I18N.text("مرحله سفارش ثبت شد. برای سفارش پولی، پرداخت باید تأیید شود."));
        await refresh();
      } catch (err) { setFeedback(err.message); }
      finally { busy = false; render(); updateButtons(); }
    });
    const randomKey = () => {
      if (!window.crypto?.getRandomValues) throw Error(window.PESM_I18N.text("مرورگر فاقد تولید شناسه امن است."));
      return Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2,'0')).join('');
    };
    const changeHold = async (action) => {
      if (!hold || busy || (action === 'replace' && (!isDirty() || !selected.size)) ||
          (action === 'renew' && isDirty())) return;
      const signature = `${action}:${[...selected].sort((a,b)=>a-b).join(',')}`;
      if (!pendingMutation || pendingMutation.signature !== signature) {
        pendingMutation = {signature, key: randomKey()};
      }
      busy = true; updateButtons();
      try {
        const result = await fetchJSON('holds', {method: 'PATCH', headers: {
          'Content-Type': 'application/json', 'X-PESM-Hold-Token': hold.token
        }, body: JSON.stringify({session_id: id, action, idempotency_key: pendingMutation.key,
          ...(action === 'replace' ? {seat_ids: [...selected].sort((a, b) => a - b)} : {})})});
        hold = result;
        if (action === 'replace') attendeeFields.set(result.seat_ids.map(seatId => ({
          seat_id: Number(seatId),
          label: (window.PESM_I18N.text("صندلی ") + String(seats.find(s => Number(s.id) === Number(seatId))?.seat_code || seatId))
        })));
        pendingMutation = null;
        serverOffset = Date.parse(result.server_now_utc) - Date.now();
        setFeedback(action === 'renew' ? window.PESM_I18N.text("مهلت نگهداری تمدید شد.") : window.PESM_I18N.text("تغییر صندلی‌ها ثبت شد."));
        await refresh(); tick();
      } catch (error) { setFeedback(error.message); }
      finally { busy = false; render(); updateButtons(); }
    };
    cartButton?.addEventListener('click', async () => {
      if (busy || !hold || isDirty()) return;
      busy = true; updateButtons();
      try {
        const result = await fetchJSON('cart/attach', {method: 'POST', headers: {
          'Content-Type': 'application/json', 'X-PESM-Hold-Token': hold.token
        }, body: JSON.stringify({session_id: id, attendees: attendeeFields.payload() || undefined})});
        const cartUrl = new URL(result.cart_url, location.href);
        if (cartUrl.origin !== location.origin) throw Error(window.PESM_I18N.text("نشانی سبد خرید معتبر نیست."));
        setFeedback(window.PESM_I18N.text("رزرو به سبد خرید منتقل شد؛ تغییر صندلی‌ها را در سبد انجام دهید."));
        location.assign(cartUrl.href);
      } catch (error) { setFeedback(error.message); }
      finally { busy = false; updateButtons(); }
    });
    replaceButton.addEventListener('click', () => changeHold('replace'));
    renewButton.addEventListener('click', () => changeHold('renew'));
    releaseButton.addEventListener('click', async () => {
      if (!hold || busy) return;
      busy = true; updateButtons();
      try {
        await fetchJSON('holds', {method: 'DELETE', headers: {'Content-Type': 'application/json',
          'X-PESM-Hold-Token': hold.token}, body: JSON.stringify({session_id: id})});
        hold = null; selected.clear(); label();
        setFeedback(window.PESM_I18N.text("صندلی‌ها آزاد شدند."));
        await refresh();
      } catch (error) { setFeedback(error.message); }
      finally { busy = false; render(); updateButtons(); }
    });
    const schedulePoll = () => {
      if (!alive) return;
      pollTimeout = window.setTimeout(async () => {
        if (!document.hidden && (!disclosure || disclosure.open)) {
          try { await refresh(); pollDelay = 15000; }
          catch (error) { pollDelay = Math.min(60000, pollDelay * 2); setFeedback(error.message); }
        }
        schedulePoll();
      }, pollDelay);
    };
    let interval = window.setInterval(tick, 1000);
    window.addEventListener('pagehide', () => {
      alive = false; window.clearInterval(interval); interval = null;
      window.clearTimeout(pollTimeout); pollTimeout = null;
    });
    window.addEventListener('pageshow', async event => {
      if (!event.persisted) return;
      alive = true; busy = true; updateButtons();
      try {
        tick();
        if (initialized) await refresh();
        if (!interval) interval = window.setInterval(tick, 1000);
        if (initialized && !pollTimeout) schedulePoll();
      } catch (error) { setFeedback(error.message); }
      finally { busy = false; render(); updateButtons(); }
    });
    const initialize = async () => {
      if (initialized || initializing) return;
      initializing = true;
      try {
        const layout = await fetchJSON(`sessions/${id}/layout`);
        if (!Array.isArray(layout.seats)) throw new Error(window.PESM_I18N.text('پاسخ سامانه معتبر نیست. دوباره تلاش کنید.'));
        seats = layout.seats;
        objects = Array.isArray(layout.objects) ? layout.objects : [];
        unitPriceMinor = Number(layout.unit_price_minor) || 0;
        currencyDecimals = Math.max(0, Math.min(6, Number(layout.currency_decimals) || 0));
        currency = String(layout.currency || '');
        if (Number(layout.rules?.require_adjacent) || Number(layout.rules?.avoid_single_gap)) {
          const parts = [];
          if (Number(layout.rules?.require_adjacent)) parts.push(window.PESM_I18N.text("صندلی‌ها باید در یک بلوک کنار هم باشند"));
          if (Number(layout.rules?.avoid_single_gap)) parts.push(window.PESM_I18N.text("ایجاد صندلی خالی تک مجاز نیست"));
          setFeedback((window.PESM_I18N.text("قوانین جلسه: ") + String(parts.join(window.PESM_I18N.text("؛ "))) + "."));
        }
        baseWidth = Math.max(0, ...seats.map(s => Number(s.pos_x)), ...objects.map(o => Number(o.x) + Number(o.width))) + 54;
        baseHeight = Math.max(0, ...seats.map(s => Number(s.pos_y)), ...objects.map(o => Number(o.y) + Number(o.height))) + 54;
        canvas.style.width = `${baseWidth}px`;
        canvas.style.height = `${baseHeight}px`;
        fit(); await refresh(); initialized = true; schedulePoll();
      } catch (error) { setFeedback(error.message); }
      finally { initializing = false; }
    };
    if (disclosure) disclosure.addEventListener('toggle', () => {
      if (!disclosure.open) return;
      if (!initialized) initialize();
      else { fit(); refresh().catch(error => setFeedback(error.message)); }
    });
    if (!disclosure || disclosure.open) initialize();
  });
})();
