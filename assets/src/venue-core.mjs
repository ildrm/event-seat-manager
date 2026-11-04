/** Shared state and bounded transport. No reservation rules live in a renderer. */
export const LIMITS = Object.freeze({tile: 2000, cache: 5000, requests: 4, page: 50, maxPage: 100});

export function restURL(path, base) {
  if (/^(?:[a-z]+:)?\/\//i.test(path)) throw new Error('Invalid API path');
  const root = new URL(base, globalThis.location?.href || 'http://localhost/');
  const [pathname, query = ''] = String(path).split('?');
  if (root.searchParams.has('rest_route')) {
    root.searchParams.set('rest_route', `${root.searchParams.get('rest_route').replace(/\/$/, '')}/${pathname.replace(/^\//, '')}`);
  } else root.pathname = `${root.pathname.replace(/\/$/, '')}/${pathname.replace(/^\//, '')}`;
  for (const [key, value] of new URLSearchParams(query)) root.searchParams.set(key, value);
  return root;
}

export function requestKey() {
  if (!globalThis.crypto?.getRandomValues) throw new Error('Secure random identifiers are unavailable.');
  return [...crypto.getRandomValues(new Uint8Array(24))].map(v => v.toString(16).padStart(2, '0')).join('');
}

/** Change only the designer's canonical identifiers; retain unrelated parameters and fragments. */
export function venueDesignerURL(href, hallId, layoutId, sessionId) {
  const url = new URL(href);
  for (const [key, value] of [['hall_id', hallId], ['layout_id', layoutId]]) {
    if (Number.isSafeInteger(Number(value)) && Number(value) > 0) url.searchParams.set(key, String(Number(value)));
    else url.searchParams.delete(key);
  }
  if (sessionId !== undefined) {
    if (Number.isSafeInteger(Number(sessionId)) && Number(sessionId) > 0) url.searchParams.set('session_id', String(Number(sessionId)));
    else url.searchParams.delete('session_id');
  }
  return url;
}

/** Exact recipes may adapt omitted structural defaults; administrator edits stay explicit. */
export function generationParameters(workflow, capacity, values, touched = new Set()) {
  const parameters = {workflow, ...(workflow === 'exact' ? {capacity: Number(capacity)} : {})};
  for (const [name, value] of Object.entries(values)) {
    if (workflow === 'exact' && ['sections', 'levels', 'blocks'].includes(name) && !touched.has(name)) continue;
    parameters[name] = value;
  }
  return parameters;
}

export class RequestQueue {
  constructor(limit = LIMITS.requests) { this.limit = limit; this.active = 0; this.pending = []; }
  run(task) {
    return new Promise((resolve, reject) => { this.pending.push({task, resolve, reject}); this.drain(); });
  }
  drain() {
    while (this.active < this.limit && this.pending.length) {
      const item = this.pending.shift(); this.active++;
      Promise.resolve().then(item.task).then(item.resolve, item.reject).finally(() => { this.active--; this.drain(); });
    }
  }
}

export class GeometryCache {
  constructor(max = LIMITS.cache) { this.max = max; this.tiles = new Map(); this.count = 0; }
  get(key) {
    const tile = this.tiles.get(key);
    if (tile) { this.tiles.delete(key); this.tiles.set(key, tile); }
    return tile;
  }
  set(key, tile) {
    const count = (tile.seats || []).length;
    if (count > LIMITS.tile || count > this.max) throw new Error('Geometry tile exceeds its size limit.');
    const previous = this.tiles.get(key);
    if (previous) { this.count -= previous.seats?.length || 0; this.tiles.delete(key); }
    this.tiles.set(key, tile); this.count += count;
    while (this.count > this.max && this.tiles.size) {
      const [oldKey, old] = this.tiles.entries().next().value;
      this.tiles.delete(oldKey); this.count -= old.seats?.length || 0;
    }
    return tile;
  }
  clear() { this.tiles.clear(); this.count = 0; }
}

export function unitKey(unit) { return `${unit.type === 'seat' ? 'seat' : 'group'}:${unit.id ?? unit.unit_id ?? unit.code}`; }
export function normalizeSeat(seat) {
  return {...seat, id: Number(seat.seat_id ?? seat.id), type: 'seat', code: String(seat.code ?? seat.seat_code ?? seat.id),
    x: Number(seat.x ?? seat.pos_x ?? 0), y: Number(seat.y ?? seat.pos_y ?? 0),
    rotation: Number(seat.rotation || 0), elevation: Number(seat.elevation || 0), capacity: 1};
}
export function normalizeGroup(group) {
  return {...group, id: String(group.group_code ?? group.code ?? group.id),
    type: group.type === 'box' || group.group_type === 'box' || group.unit_type === 'box' ? 'box' : 'table',
    code: String(group.code ?? group.group_code), x: Number(group.x || 0), y: Number(group.y || 0),
    width: Math.max(400, Number(group.width || 1800)), height: Math.max(400, Number(group.height || 1800))};
}

/** Keep values by their immutable booking identities, including deliberately cleared names. */
export function bookingFormState(lines, current = {}, defaults = {}) {
  const result = {ticket_modes: {}, attendees: {}};
  const own = (record, key) => record && Object.prototype.hasOwnProperty.call(record, key);
  const value = (record, fallback, key) => String(own(record, key) ? record[key] ?? '' : fallback?.[key] ?? '');
  for (const line of lines || []) {
    if (line.group_code) {
      const code = String(line.group_code);
      result.ticket_modes[code] = value(current.ticket_modes, defaults.ticket_modes, code) || 'individual';
    }
    for (const id of line.seat_ids || []) {
      const key = String(id), entered = current.attendees?.[key], saved = defaults.attendees?.[key];
      result.attendees[key] = {first_name: value(entered, saved, 'first_name'), last_name: value(entered, saved, 'last_name')};
    }
  }
  return result;
}

/** Public group lists may omit location metadata; resolve before opening a detail tile. */
export async function resolveMapLocation(unit, lookup) {
  let location = unit;
  if (!unit.section_code || !unit.tile_code) {
    const response = await lookup(String(unit.code ?? unit.group_code ?? unit.unit_id ?? unit.id));
    location = {...unit, ...(response.item || response)};
  }
  return {section_code: String(location.section_code || ''), tile_code: String(location.tile_code || '')};
}

/** Camera identity excludes selection, availability and array order. */
export function previewScopeKey(data) {
  const seats = (data.seats || []).slice(0, LIMITS.tile).map(seat => String(seat.seat_id ?? seat.id)).sort();
  const sections = seats.length ? [] : (data.sections || []).map(section => `${section.code || section.section_code}:${section.level || 0}`).sort();
  return JSON.stringify([data.scope || '', seats, sections]);
}
export function boundsOf(items, padding = 500) {
  if (!items.length) return {x: 0, y: 0, width: 10000, height: 7000};
  let x = Infinity, y = Infinity, right = -Infinity, bottom = -Infinity;
  for (const item of items) {
    const bx = Number(item.x || 0), by = Number(item.y || 0);
    x = Math.min(x, bx); y = Math.min(y, by);
    right = Math.max(right, bx + Number(item.width || 450)); bottom = Math.max(bottom, by + Number(item.height || 450));
  }
  return {x: x - padding, y: y - padding, width: Math.max(1000, right - x + padding * 2), height: Math.max(1000, bottom - y + padding * 2)};
}

export class SelectionController {
  constructor(max = 20,maxAdmissions=max===20?200:Infinity) { this.max = max;this.maxAdmissions=maxAdmissions; this.selected = new Map(); this.listeners = new Set(); this.busy = false; this.quote = null; }
  subscribe(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn); }
  notify() { for (const fn of this.listeners) fn(this); }
  setBusy(value) { this.busy = Boolean(value); this.notify(); }
  has(unit) {
    return this.selected.has(unitKey(unit)) || (unit.group_code && this.selected.has(`group:${unit.group_code}`));
  }
  toggle(unit) {
    if (this.busy) return false;
    const key = unitKey(unit);
    const next=new Map(this.selected);
    if (next.has(key)) next.delete(key);
    else {
      if (unit.type !== 'seat') {
        for (const [id, member] of next) if (String(member.group_code) === String(unit.id)) next.delete(id);
      } else if (unit.group_code && next.has(`group:${unit.group_code}`)) return false;
      next.set(key,{...unit});
      if(next.size>this.max||[...next.values()].reduce((total,item)=>total+Number(item.capacity||1),0)>this.maxAdmissions){const error=new Error('The selection exceeds the purchase limit.');error.code='selection_limit';throw error;}
    }
    this.selected=next;
    this.quote = null; this.notify(); return true;
  }
  clear() { if (!this.busy) { this.selected.clear(); this.quote = null; this.notify(); } }
  payload() { return [...this.selected.values()].map(unit => ({type: unit.type === 'seat' ? 'seat' : 'group', id: unit.id})); }
  signature() { return this.payload().map(unitKey).sort().join('|'); }
}

/** Geometry-local nearest directional candidate. Physical directions do not reverse in RTL. */
export function nearest(items, current, key, enabled = () => true) {
  const direction = {ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1]}[key];
  if (!direction) return null;
  let best = null, bestScore = Infinity;
  for (const item of items) {
    const dx = item.x - current.x, dy = item.y - current.y;
    const forward = dx * direction[0] + dy * direction[1];
    const score = forward + 4 * Math.abs(dx * direction[1] - dy * direction[0]);
    if (forward > 0 && score < bestScore && enabled(item)) { best = item; bestScore = score; }
  }
  return best;
}

export function money(minor, decimals = 0, currency = '', locale = 'en') {
  return `${new Intl.NumberFormat(locale, {minimumFractionDigits: 0, maximumFractionDigits: decimals}).format(Number(minor || 0) / 10 ** decimals)} ${currency}`.trim();
}

export function utcMillis(value) {
  if (!value) return NaN;
  const text = String(value).replace(' ', 'T');
  return Date.parse(/[zZ]|[+-]\d\d:\d\d$/.test(text) ? text : `${text}Z`);
}
export function ticketNumberForLookup(value){
  const text=String(value||'').trim();const qr=/^A\.([PG])\.([0-9a-f]{32})\.[0-9a-f]{64}$/i.exec(text);
  if(qr)return `A-${qr[1].toUpperCase()}-${qr[2].toUpperCase()}`;
  return /^(?:A-[PG]-[0-9a-f]{32}|P-[A-Z0-9-]+)$/i.test(text)?text.toUpperCase():null;
}

const errorMessages={
  seat_unavailable:['A selected place is no longer available. Refresh and choose another.','یکی از جایگاه‌ها دیگر آزاد نیست. موجودی را تازه و جایگاه دیگری انتخاب کنید.'],
  hold_expired:['This hold has expired. Check availability and reserve again.','مهلت رزرو تمام شده است. موجودی را بررسی و دوباره رزرو کنید.'],
  permission_denied:['You do not have permission to perform this action.','اجازه انجام این عملیات را ندارید.'],
  csrf_denied:['Your session needs to be refreshed. Reload this page and try again.','نشست شما نیاز به تازه‌سازی دارد. صفحه را دوباره بارگذاری کنید.'],
  rest_cookie_invalid_nonce:['Your session needs to be refreshed. Reload this page and try again.','نشست شما نیاز به تازه‌سازی دارد. صفحه را دوباره بارگذاری کنید.'],
  invalid_selection:['Choose valid seats, tables or boxes and try again.','صندلی، میز یا باکس معتبر انتخاب و دوباره تلاش کنید.'],
  selection_limit:['The selection exceeds the reservation limit of 20 units or 200 guests.','انتخاب از محدودیت رزرو، یعنی ۲۰ واحد یا ۲۰۰ نفر، بیشتر است.'],
  duplicate_resource:['A place cannot be selected twice, individually and inside a group.','یک جایگاه نمی‌تواند هم تکی و هم داخل گروه انتخاب شود.'],
  whole_group_required:['This group must be reserved as one whole unit.','این گروه باید به‌صورت کامل رزرو شود.'],
  invalid_group:['This table or box cannot be reserved in the selected mode.','این میز یا باکس با روش انتخاب‌شده قابل رزرو نیست.'],
  inventory_not_ready:['The venue is still being prepared. Please try again shortly.','آماده‌سازی موجودی سالن ادامه دارد. کمی بعد دوباره تلاش کنید.'],
  configuration_locked:['These settings cannot be changed in the current session state.','در وضعیت فعلی جلسه، تغییر این تنظیمات ممکن نیست.'],
  layout_not_published:['Publish the completed venue layout before creating a session.','پیش از ایجاد جلسه، چیدمان کامل سالن را منتشر کنید.'],
  event_closed:['Sales for this session are not open.','فروش این جلسه باز نیست.'],
  invalid_session:['This session is unavailable.','این جلسه در دسترس نیست.'],
  invalid_seat:['This place is not part of the selected venue.','این جایگاه متعلق به سالن انتخاب‌شده نیست.'],
  invalid_attendee:['Complete the guest name fields or leave both names blank.','نام و نام خانوادگی مهمان را کامل کنید یا هر دو را خالی بگذارید.'],
  invalid_email:['Enter a valid email address.','ایمیل معتبر وارد کنید.'],
  invalid_ticket_mode:['Choose an allowed ticket mode for each purchased group.','برای هر گروه خریداری‌شده یک روش مجاز صدور بلیت انتخاب کنید.'],
  phone_limit:['This phone number has reached the event reservation limit.','این شماره همراه به محدودیت رزرو رویداد رسیده است.'],
  price_mismatch:['The payment amount does not match this booking. The order needs review.','مبلغ پرداخت با رزرو مطابقت ندارد. سفارش نیاز به بررسی دارد.'],
  payment_unverified:['Payment has not yet been confirmed. Please check again later.','پرداخت هنوز تأیید نشده است. کمی بعد دوباره بررسی کنید.'],
  checkout_reconciliation_required:['The order is awaiting review. Do not create another payment for this booking.','سفارش در انتظار بررسی است. برای این رزرو پرداخت دیگری ایجاد نکنید.'],
  paid_tax_unsupported:['Paid booking is unavailable with the current tax configuration. Contact the organizer.','رزرو پولی با تنظیمات فعلی مالیات در دسترس نیست. با برگزارکننده تماس بگیرید.'],
  idempotency_conflict:['This request changed while being retried. Reload the latest state before continuing.','درخواست هنگام تلاش دوباره تغییر کرده است. پیش از ادامه، وضعیت جدید را بارگذاری کنید.'],
  hold_changed_retry:['The reservation changed in another request. Refresh before continuing.','رزرو در درخواست دیگری تغییر کرده است. پیش از ادامه تازه‌سازی کنید.'],
  hold_conflict:['This hold can no longer be confirmed. Refresh the available places.','این رزرو موقت دیگر قابل تأیید نیست. موجودی را تازه کنید.'],
  invalid_token:['The private access key is invalid or has expired.','کلید دسترسی خصوصی معتبر نیست یا منقضی شده است.'],
  invalid_credential:['This ticket credential is invalid.','اعتبارنامه این بلیت معتبر نیست.'],
  invalid_qr:['This QR code is invalid. Use the current ticket.','این کد QR معتبر نیست. بلیت فعلی را ارائه کنید.'],
  ticket_inactive:['This ticket is no longer active.','این بلیت دیگر فعال نیست.'],
  ticket_not_found:['No matching ticket was found.','بلیتی با این مشخصات پیدا نشد.'],
  invalid_ticket_number:['Enter the complete ticket number.','شماره کامل بلیت را وارد کنید.'],
  already_checked_in:['This ticket or group has already entered.','این بلیت یا گروه قبلاً وارد شده است.'],
  admission_quantity:['The arrival count exceeds the remaining admissions. Confirm the quantity.','تعداد ورودی از ظرفیت باقی‌مانده بیشتر است. تعداد را بررسی کنید.'],
  invalid_entry_time:['Entry is not permitted at this time.','ورود در این زمان مجاز نیست.'],
  group_already_used:['This booking already has admissions. It cannot be cancelled or moved.','بخشی از این رزرو استفاده شده است و قابل لغو یا جابه‌جایی نیست.'],
  price_difference_unsupported:['Choose an unused destination with the same capacity and total price.','مقصد آزاد با ظرفیت و مبلغ یکسان انتخاب کنید.'],
  invalid_refund:['The WooCommerce refund could not be verified for this booking.','استرداد ووکامرس برای این رزرو قابل تأیید نیست.'],
  refund_amount_mismatch:['The refund must match the complete booking amount.','مبلغ استرداد باید برابر مبلغ کامل رزرو باشد.'],
  refund_line_mismatch:['The refund must refer to this exact order line.','استرداد باید به همین قلم سفارش مربوط باشد.'],
  cart_conflict:['The cart reservation changed. Refresh the cart before continuing.','رزرو سبد تغییر کرده است. پیش از ادامه سبد را تازه کنید.'],
  invalid_cart_item:['This cart reservation is no longer available.','این رزرو سبد دیگر در دسترس نیست.'],
  cart_unavailable:['The cart is unavailable. You can continue with direct booking.','سبد در دسترس نیست. می‌توانید ثبت سفارش مستقیم را ادامه دهید.'],
  woocommerce_required:['Order processing is temporarily unavailable.','ثبت سفارش موقتاً در دسترس نیست.'],
  db_busy:['The venue is busy. Retry the same request in a moment.','سامانه پرترافیک است. چند لحظه دیگر همان درخواست را دوباره ارسال کنید.'],
  rate_limited:['Too many requests. Wait a moment and retry.','تعداد درخواست‌ها زیاد است. کمی صبر و دوباره تلاش کنید.'],
};
export function friendlyError(code,locale,fallback=''){
  const key=String(code||'').replace(/^pesm_/,'');const fa=String(locale||'').startsWith('fa');
  if(errorMessages[key])return errorMessages[key][fa?1:0];
  if(fa)return /[\u0600-\u06ff]/.test(fallback)?fallback:'عملیات انجام نشد. صفحه را تازه و دوباره تلاش کنید.';
  return fallback&&!/^[a-z0-9_]+$/.test(fallback)?fallback:'The request could not be completed. Refresh and try again.';
}

export class ApiClient {
  constructor(config) { this.config = config; this.queue = new RequestQueue(); }
  async request(path, {method = 'GET', body, token, guestToken, signal} = {}) {
    const headers = {'Accept': 'application/json'};
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (this.config.nonce) headers['X-WP-Nonce'] = this.config.nonce;
    if (this.config.cartNonce) headers['X-PESM-Cart-Nonce'] = this.config.cartNonce;
    if (token) headers['X-PESM-Hold-Token'] = token;
    if (guestToken) headers['X-PESM-Guest-Token'] = guestToken;
    return this.queue.run(async () => {
      const response = await fetch(restURL(path, this.config.rest), {method, headers, credentials: 'same-origin', cache: 'no-store',
        ...(body === undefined ? {} : {body: JSON.stringify(body)}), signal});
      const data = await response.json().catch(() => { throw new Error('Invalid server response.'); });
      if (!response.ok) { const error = new Error(friendlyError(data.code||data.error,this.config.locale,data.message||'')); error.status = response.status; error.data = data; throw error; }
      return data;
    });
  }
}
