import {ApiClient, GeometryCache, SelectionController, LIMITS, normalizeSeat, normalizeGroup, unitKey, requestKey, money, restURL, utcMillis,ticketNumberForLookup,bookingFormState,resolveMapLocation,venueDesignerURL,generationParameters} from './venue-core.mjs';
import {VenueCanvas} from './venue-canvas.mjs';
import './venue-app.css';

const el = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key in node && !key.startsWith('aria')) node[key] = value;
    else node.setAttribute(key, value);
  }
  for (const child of children.flat()) if (child !== null && child !== undefined) node.append(child?.nodeType ? child : document.createTextNode(String(child)));
  return node;
};
const button = (text, action, primary = false) => el('button', {type: 'button', class: primary ? 'pesm-v-button is-primary' : 'pesm-v-button', text, onclick: action});
const field = (label, input, help = '') => el('label', {class: 'pesm-v-field'}, el('span', {text: label}), input, help ? el('small', {text: help}) : null);
const option = (value, text) => el('option', {value, text});
const number = (name, value, min = 0, max = 150000) => el('input', {type: 'number', name, value, min, max, step: 1, required: true});
const safeLink = value => { const url = new URL(value, location.href); if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid destination.'); return url.href; };

export class VenueApp {
  constructor(root, config) {
    this.root = root; this.config = config; this.fa = String(config.locale || document.documentElement.lang).startsWith('fa');
    this.t = (en, fa) => this.fa ? fa : en;
    this.api = new ApiClient(config); this.cache = new GeometryCache(); this.admin = config.context === 'admin';
    this.selection = new SelectionController(this.admin ? 2000 : 20); this.availability = new Map(); this.ownSeatIds = new Set();
    this.pendingQuote = 0; this.scopeVersion = 0; this.tile = null; this.overview = null; this.hold = null; this.holdSignature = null;
    this.layoutId = Number(config.layoutId) || 0; this.sessionId = Number(config.sessionId) || 0;
    this.layoutVersions=new Map([[Number(config.hallId)||0,(config.versions||[]).map(version=>({...version}))]]);
    this.tileCode = ''; this.sectionCode = ''; this.active = true; this.undo = []; this.redo = []; this.pollTimer = null;
    this.abort = new AbortController(); this.root.classList.add('pesm-venue'); this.root.dir = this.fa ? 'rtl' : 'ltr';
    this.root.replaceChildren();
    this.heading = el('div', {class: 'pesm-v-heading'}, el('div', {}, el('p', {class: 'pesm-v-eyebrow', text: this.t('EVENT SEAT MANAGER', 'مدیریت جایگاه رویداد')}), el('h2', {text: this.admin ? this.t('Venue designer', 'طراحی سالن و جایگاه') : config.context === 'gate' ? this.t('Admission desk', 'کنترل ورود') : config.context === 'tickets' ? this.t('Your tickets', 'بلیت‌های شما') : this.t('Choose your places', 'جایگاه خود را انتخاب کنید')})));
    this.status = el('p', {class: 'pesm-v-status', role: 'status', 'aria-live': 'polite'});
    this.root.append(this.heading, this.status);
    if (config.context === 'tickets') { this.mountTickets(); return; }
    if (config.context === 'gate') { this.mountGate(); return; }
    if (this.admin) this.mountDesigner();
    this.mountBrowser();
    if (!this.admin) this.mountBooking();
    this.unsubscribe = this.selection.subscribe(() => { this.renderSelection(); this.renderer?.schedule(); this.updateListSelection(); });
    window.addEventListener('pagehide', () => { this.active = false; this.revalidationRequired=true;clearTimeout(this.pollTimer);clearTimeout(this.expiryTimer); clearInterval(this.tickTimer); this.preview?.dispose(); this.preview = null; }, {signal: this.abort.signal});
    window.addEventListener('pageshow', event => {
      if (!event.persisted) return; this.active = true;
      if (!this.admin) { this.selection.setBusy(true); this.resumeHold().then(()=>this.refreshAvailability()).then(()=>{this.revalidationRequired=false;}).catch(error => this.message(error.message, true)).finally(() => { this.tick(); this.selection.setBusy(false); this.startTimers(); }); }
    }, {signal: this.abort.signal});
    if ((this.layoutId && !(this.admin&&this.sessionId)) || (!this.admin&&this.sessionId)) this.loadOverview().catch(error => this.message(error.message, true));
    this.startTimers();
  }
  message(text, error = false) { this.status.textContent = text; this.status.classList.toggle('is-error', error); }
  async perform(task, success) {
    if (this.selection.busy) return;
    this.selection.setBusy(true); this.root.setAttribute('aria-busy', 'true');
    try { const result = await task(); if (success) this.message(success); return result; }
    catch (error) { this.message(error.message, true); if (error.status === 409 && this.admin) this.saveState.textContent = this.t('Conflict — reload before editing.', 'تعارض — پیش از ویرایش دوباره بارگذاری کنید.'); }
    finally { this.selection.setBusy(false); this.root.removeAttribute('aria-busy'); }
  }
  mountBrowser() {
    const t = this.t;
    this.workspace = el('section', {class: 'pesm-v-workspace', 'aria-label': t('Venue map and accessible place list', 'نقشه سالن و فهرست دسترس‌پذیر جایگاه‌ها')});
    this.sectionSelect = el('select', {'aria-label': t('Section', 'بخش'), onchange: () => this.openSection(this.sectionSelect.value)});
    this.sectionSelect.append(option('', t('Venue overview', 'نمای کلی سالن')));
    this.tileSelect = el('select', {'aria-label': t('Detail area', 'ناحیه جزئیات'), hidden: true, onchange: () => this.openTile(this.tileSelect.value)});
    this.viewSelect = el('select', {'aria-label': t('View', 'نوع نمایش'), onchange: () => this.setView(this.viewSelect.value)}, option('map', t('Map', 'نقشه')), option('list', t('List', 'فهرست')));
    this.unitSelect = el('select', {'aria-label': t('Reserve', 'نوع جایگاه'), onchange: () => { this.renderer.setMode(this.unitSelect.value); this.loadList(); }}, option('seat', t('Individual seats', 'صندلی تکی')), option('table', t('Whole tables', 'میز کامل')), option('box', t('Whole boxes', 'باکس کامل')));
    this.levelSelect=el('select',{'aria-label':t('Level','طبقه'),onchange:()=>this.filterLevel()},option('',t('All levels','همه طبقات')));
    this.searchInput = el('input', {type: 'search', placeholder: t('Seat or group code', 'کد صندلی، میز یا باکس'), 'aria-label': t('Search places', 'جست‌وجوی جایگاه')});
    const searchForm = el('form', {class: 'pesm-v-search', onsubmit: event => { event.preventDefault(); this.search(); }}, this.searchInput, el('button', {type: 'submit', class: 'pesm-v-button', text: t('Find', 'یافتن')}));
    const refresh=button(t('Refresh','تازه‌سازی'),()=>this.perform(async()=>{if(this.admin)await this.loadOverview();else{await this.resumeHold();await this.refreshAvailability();this.revalidationRequired=false;if(!this.hold&&this.selection.selected.size)await this.quoteSelection();}}));
    const toolbar = el('div', {class: 'pesm-v-toolbar'}, this.levelSelect,this.sectionSelect, this.tileSelect, this.viewSelect, this.unitSelect, searchForm,refresh);
    this.mapHost = el('div', {class: 'pesm-v-map', 'aria-label': t('Schematic venue map', 'نقشه شماتیک سالن')});
    this.listHost = el('div', {class: 'pesm-v-list', hidden: true});
    this.listToolbar = el('div', {class: 'pesm-v-list-toolbar'});
    this.categorySelect = el('select', {'aria-label': t('Category', 'دسته جایگاه'), onchange: () => this.loadList()}, option('', t('All categories', 'همه دسته‌ها')));
    this.rowInput = el('input', {type: 'search', placeholder: t('Row', 'ردیف'), 'aria-label': t('Filter row', 'فیلتر ردیف'), onchange: () => this.loadList()});
    this.availableOnly = el('input', {type: 'checkbox', checked: false, onchange: () => this.loadList()});
    this.listToolbar.append(this.categorySelect, this.rowInput, el('label', {}, this.availableOnly, t('Available only', 'فقط آزاد')));
    this.listItems = el('div', {class: 'pesm-v-list-items'});
    this.listMore = button(t('Next 50 places', '۵۰ جایگاه بعدی'), () => this.loadList(this.nextCursor));
    this.listBack = button(t('First page', 'صفحه اول'), () => this.loadList());
    this.listHost.append(this.listToolbar, this.listItems, el('div', {class: 'pesm-v-toolbar'}, this.listBack, this.listMore));
    const zoomTools = el('div', {class: 'pesm-v-map-actions'}, button('+', () => this.renderer.zoom(1.35)), button('−', () => this.renderer.zoom(1/1.35)), button(t('Fit view', 'نمای کامل'), () => this.renderer.fit()), button(t('Overview', 'نمای کلی'), () => this.openSection('')));
    zoomTools.children[0].setAttribute('aria-label', t('Zoom in', 'بزرگ‌نمایی')); zoomTools.children[1].setAttribute('aria-label', t('Zoom out', 'کوچک‌نمایی'));
    this.previewButton = button(t('3D preview', 'پیش‌نمایش سه‌بعدی'), () => this.open3D());
    zoomTools.append(this.previewButton);
    this.mapHint = el('p', {class: 'pesm-v-help', text: t('Select a section for details. Use the List view for keyboard and screen-reader booking. Drag empty space to pan; use zoom controls for detail.', 'برای دیدن جزئیات یک بخش را انتخاب کنید. فهرست برای رزرو با صفحه‌کلید و صفحه‌خوان در دسترس است. فضای خالی را برای جابه‌جایی بکشید و از دکمه‌های بزرگ‌نمایی استفاده کنید.')});
    this.previewHost = el('div', {class: 'pesm-v-preview', hidden: true});
    const legend=el('ul',{class:'pesm-v-legend','aria-label':t('Map legend','راهنمای نقشه')},...[[t('Available','آزاد'),'available'],[t('Selected','انتخاب‌شده'),'selected'],[t('Unavailable','غیرقابل رزرو'),'unavailable'],[t('Table or box','میز یا باکس'),'group']].map(([label,state])=>el('li',{},el('span',{class:`pesm-v-legend-${state}`,'aria-hidden':'true'}),label)));
    this.workspace.append(toolbar, this.mapHost, this.listHost, zoomTools, legend, this.mapHint, this.previewHost);
    this.root.append(this.workspace);
    this.renderer = new VenueCanvas(this.mapHost, {controller: this.selection,
      onSelect: item => this.selectUnit(item), onSection: code => this.openSection(code),
      onMove: this.admin ? delta => this.moveSelection(delta) : null,
      label: item => this.unitLabel(item), isAvailable: item => this.isAvailable(item),
      onFocus: item => { if (this.admin) this.inspect(item); }});
    if (this.admin) this.mountInspector();
  }
  overviewPath() { return this.admin ? `admin/layouts/${this.layoutId}` : `sessions/${this.sessionId}/layout`; }
  async loadOverview(binding = {}) {
    const request=this.overviewRequest=(this.overviewRequest||0)+1;
    const layoutId=this.layoutId,sessionId=this.sessionId;
    const current=()=>request===this.overviewRequest&&(this.admin?layoutId===this.layoutId:sessionId===this.sessionId)&&(binding.sessionRequest===undefined||binding.sessionRequest===this.sessionConfigRequest&&binding.sessionId===this.sessionId);
    const result = await this.api.request(this.overviewPath());
    if(!current())return false;
    this.overview = result.layout || result; this.layoutId = Number(this.overview.layout_id || this.layoutId);
    if(!this.admin)this.selection.maxAdmissions=Math.min(200,Number(this.overview.purchase_limit)||200);
    this.settings = result.settings || result.config || this.overview.settings || this.overview.config || {};
    this.revision = Number(this.overview.revision || 0); this.cache.clear(); this.scopeVersion++;this.tile=null;this.tileCode='';this.sectionCode='';this.tileSelect.hidden=true;
    this.sectionSelect.replaceChildren(option('', this.t('Venue overview', 'نمای کلی سالن')),
      ...(this.overview.sections || []).map(s => option(s.code || s.section_code, `${this.sectionTitle(s)} · ${Number(s.capacity || 0).toLocaleString(this.fa?'fa':'en')}`)));
    const levels=[...new Set((this.overview.sections||[]).map(s=>Number(s.level||0)))].sort((a,b)=>a-b);
    this.levelSelect.replaceChildren(option('',this.t('All levels','همه طبقات')),...levels.map(level=>option(level,`${this.t('Level','طبقه')} ${level+1}`)));this.levelSelect.hidden=levels.length<=1;
    this.categorySelect.replaceChildren(option('', this.t('All categories', 'همه دسته‌ها')), ...(this.overview.categories || []).map(c => option(c.code, c.label || c.code)));
    this.renderer.setOverview((this.overview.sections || []).map(section=>({...section,label:this.sectionTitle(section)})), this.overview.objects || []);
    if(this.preview)this.preview.update(this.previewData());
    this.capacityLabel && (this.capacityLabel.textContent = `${Number(this.overview.capacity || this.overview.seat_count || 0).toLocaleString(this.fa?'fa':'en')} ${this.t('places', 'جایگاه')}`);
    if (this.admin) { this.saveState.textContent = this.t('Saved', 'ذخیره شد'); this.loadPricingForm(); this.publishButton.disabled = this.overview.status === 'published'; this.rememberLayoutVersion(); }
    await this.loadList(); if (!this.admin) { await this.resumeHold(); await this.refreshAvailability(); }
    if(!current())return false;
    this.message(this.t('Choose a section or browse the place list.', 'یک بخش را انتخاب کنید یا از فهرست جایگاه‌ها استفاده کنید.'));
    return true;
  }
  async openSection(code,targetTile='') {
    this.sectionCode = code; this.sectionSelect.value = code; this.tileCode = ''; this.tileSelect.replaceChildren();
    if (!code) {
      this.scopeVersion++; this.tile = null; this.tileSelect.hidden = true;
      this.renderer.setOverview(this.visibleSections().map(section=>({...section,label:this.sectionTitle(section)})), this.overview?.objects || []);
      if(this.preview)this.preview.update(this.previewData());
      await this.loadList(); return;
    }
    const section = this.overview.sections.find(s => String(s.code || s.section_code) === code);
    const codes = section?.tiles || (this.overview.tiles || []).filter(tile => tile.section_code === code).map(tile => tile.code || tile.tile_code);
    for (const [index, tile] of codes.entries()) this.tileSelect.append(option(typeof tile === 'string' ? tile : tile.code, `${this.t('Area', 'ناحیه')} ${index+1}`));
    this.tileSelect.hidden = codes.length <= 1;
    if (codes.length) await this.openTile(targetTile||(typeof codes[0] === 'string' ? codes[0] : codes[0].code));
    await this.loadList();
  }
  sectionTitle(section){const label=this.fa&&/^Section \d+$/.test(section.label||'')?`${this.t('Section','بخش')} ${String(section.label).split(' ')[1]}`:section.label||section.code;const min=section.price_min_minor??section.min_price_minor,max=section.price_max_minor??section.max_price_minor;const price=min===undefined?'':` · ${money(min,this.overview?.price_decimals||0,this.overview?.currency||'',this.fa?'fa':'en')}${max!==min?`–${money(max,this.overview?.price_decimals||0,this.overview?.currency||'',this.fa?'fa':'en')}`:''}`;return `${label} · ${this.t('Level','طبقه')} ${Number(section.level||0)+1}${price}`;}
  visibleSections(){return(this.overview?.sections||[]).filter(section=>this.levelSelect.value===''||Number(section.level||0)===Number(this.levelSelect.value));}
  filterLevel(){this.sectionSelect.replaceChildren(option('',this.t('Venue overview','نمای کلی سالن')),...this.visibleSections().map(section=>option(section.code,`${this.sectionTitle(section)} · ${section.capacity}`)));this.openSection('');}
  async openTile(code,preserveView=false) {
    const version = ++this.scopeVersion,layoutId=this.layoutId,sessionId=this.sessionId; this.tileCode = code; this.tileSelect.value = code;
    const current=()=>version===this.scopeVersion&&layoutId===this.layoutId&&sessionId===this.sessionId;
    this.message(this.t('Loading section detail…', 'در حال دریافت جزئیات بخش…'));
    try {
      let tile = this.cache.get(`${layoutId}:${code}`);
      if (!tile) {
        const path = this.admin ? `admin/layouts/${this.layoutId}/tiles/${encodeURIComponent(code)}` : `sessions/${this.sessionId}/tiles/${encodeURIComponent(code)}`;
        tile = await this.api.request(path); tile = tile.tile || tile;
        if(!current())return;
        tile = {...tile,seats:(tile.seats||[]).map(normalizeSeat),groups:(tile.groups||[]).map(normalizeGroup)};
        this.cache.set(`${layoutId}:${code}`, tile);
      }
      if (!current()) return;
      this.tile = tile;
      this.renderer.setDetail(this.tile.seats, this.tile.groups, [...(this.overview.objects || []), ...(this.tile.objects || [])],preserveView);
      for(const[key,unit]of this.selection.selected){const current=unit.type==='seat'?this.tile.seats.find(s=>s.id===unit.id):this.tile.groups.find(g=>g.id===unit.id);if(current)this.selection.selected.set(key,current);}
      if (!this.admin) await this.refreshAvailability();
      else this.loadPricingForm();
      if(!current())return;
      this.message(`${this.tile.seats.length.toLocaleString(this.fa?'fa':'en')} ${this.t('places in this detail area. Select another area to continue.', 'جایگاه در این ناحیه. برای ادامه ناحیه دیگری را انتخاب کنید.')}`);
      if (this.preview) this.preview.update(this.previewData());
    } catch (error) { if(current())this.message(error.message, true); }
  }
  setView(view) { this.mapHost.hidden = view !== 'map'; this.listHost.hidden = view !== 'list'; if (view === 'map') this.renderer.schedule(); else this.loadList(); }
  unitLabel(unit) {
    const name = unit.type === 'seat' ? this.t('Seat', 'صندلی') : unit.type === 'box' ? this.t('Box', 'باکس') : this.t('Table', 'میز');
    const state = this.isAvailable(unit) ? this.t('available', 'آزاد') : this.t('unavailable', 'غیرقابل رزرو');
    return `${name} ${unit.code || unit.id}, ${unit.section_code || ''}, ${unit.row_label || ''}, ${unit.category_code || ''}, ${unit.capacity || 1} ${this.t('places', 'جایگاه')}, ${state}`;
  }
  isAvailable(unit) {
    if (this.admin) return true;
    const group=this.tile?.groups.find(g=>String(g.id)===String(unit.type==='seat'?unit.group_code:unit.id));
    const mode=this.overview?.group_modes?.[group?.id]||this.settings?.group_modes?.[group?.id]||group?.sale_mode;
    if(unit.type==='seat'&&mode==='whole')return false;
    if(unit.type!=='seat'&&mode==='individual')return false;
    if (unit.type === 'seat') return this.ownSeatIds.has(Number(unit.id)) || this.availability.get(`seat:${unit.id}`) === 'available';
    if(this.hold?.quote?.lines.some(line=>String(line.group_code)===String(unit.id)))return true;
    const state = this.availability.get(`group:${unit.id}`);
    if (state) return state === 'available' || state === 'owned';
    if (unit.available !== undefined) return Boolean(unit.available);
    const members = this.tile?.seats.filter(s => String(s.group_code || '') === String(unit.id)) || [];
    return members.length === Number(unit.capacity) && members.every(s => this.ownSeatIds.has(s.id) || this.availability.get(`seat:${s.id}`) === 'available');
  }
  selectUnit(unit) {
    if (this.selection.busy||this.admin&&this.sessionConfigPending) return;
    if (!this.isAvailable(unit) && !this.selection.has(unit)) { this.message(this.t('This place is currently unavailable.', 'این جایگاه در حال حاضر قابل رزرو نیست.'), true); return; }
    try { this.selection.toggle(unit); if (this.admin) this.inspect(unit); else this.quoteSelection(); }
    catch (error) { this.message(error.code==='selection_limit'?`${this.t('Selection limit','محدودیت انتخاب')}: ${this.selection.max} ${this.t('purchase units','واحد خرید')}${Number.isFinite(this.selection.maxAdmissions)?` · ${this.selection.maxAdmissions} ${this.t('guests','نفر')}`:''}`:error.message, true); }
  }
  async loadList(cursor = '') {
    if (!this.layoutId && !this.sessionId) return;
    const request = this.listRequest = (this.listRequest || 0) + 1;
    const filter = new URLSearchParams({limit: String(LIMITS.page), type: this.unitSelect.value, section: this.sectionCode || '', category: this.categorySelect.value || '', row: this.rowInput.value || '', available: this.availableOnly.checked ? '1' : '0'});
    if (cursor) filter.set('cursor', cursor);
    try {
      const prefix = this.admin ? `admin/layouts/${this.layoutId}` : `sessions/${this.sessionId}`;
      const result = await this.api.request(`${prefix}/units?${filter}`);
      if (request !== this.listRequest) return;
      this.nextCursor = result.next_cursor || ''; this.listMore.disabled = !this.nextCursor;
      this.listItems.replaceChildren(); this.listUnits = [];
      for (const raw of (result.items || []).slice(0, LIMITS.maxPage)) {
        const unit = raw.type === 'seat' ? normalizeSeat(raw) : normalizeGroup(raw); this.listUnits.push(unit);
        if (raw.status) this.availability.set(unitKey(unit), raw.status);
        if (raw.available !== undefined) this.availability.set(unitKey(unit), raw.available ? 'available' : 'blocked');
        const detail = el('span', {class: 'pesm-v-unit-detail', text: [unit.section_code, unit.row_label, unit.category_code, unit.type === 'seat' ? '' : `${unit.capacity} ${this.t('places', 'جایگاه')}`].filter(Boolean).join(' · ')});
        const price = raw.total_minor ?? raw.price_minor;
        const choose = button(this.selection.has(unit) ? this.t('Selected', 'انتخاب شد') : this.t('Select', 'انتخاب'), () => this.selectUnit(unit));
        choose.dataset.unitKey = unitKey(unit); choose.disabled = !this.isAvailable(unit); choose.setAttribute('aria-pressed',String(this.selection.has(unit))); choose.setAttribute('aria-label',this.unitLabel(unit));
        const locate = button(this.t('Show on map', 'نمایش روی نقشه'), () => this.showUnitOnMap(unit));
        this.listItems.append(el('article', {class: 'pesm-v-unit'}, el('div', {}, el('strong', {text: unit.code}), detail,
          price === undefined ? null : el('span',{class:'pesm-v-unit-price',text:this.formatMoney(price)})), el('div',{class:'pesm-v-unit-actions'},choose,locate)));
      }
      if (!this.listUnits.length) this.listItems.append(el('p',{text:this.t('No matching places. Change the filters or choose another section.','جایگاهی با این شرایط پیدا نشد. فیلترها را تغییر دهید یا بخش دیگری انتخاب کنید.')}));
    } catch (error) { this.message(error.message,true); }
  }
  updateListSelection() {
    if (!this.listItems) return;
    for (const btn of this.listItems.querySelectorAll('[data-unit-key]')) {
      const unit = this.listUnits?.find(u=>unitKey(u)===btn.dataset.unitKey); if (!unit) continue;
      const selected = this.selection.has(unit); btn.setAttribute('aria-pressed',String(selected)); btn.textContent = selected ? this.t('Selected','انتخاب شد') : this.t('Select','انتخاب'); btn.disabled = this.selection.busy || (!this.isAvailable(unit) && !selected);
    }
  }
  async showUnitOnMap(unit) {
    try {
      const prefix=this.admin?`admin/layouts/${this.layoutId}`:`sessions/${this.sessionId}`;
      const location=await resolveMapLocation(unit,code=>this.api.request(`${prefix}/locate?code=${encodeURIComponent(code)}`));
      const section=this.overview?.sections.find(item=>String(item.code||item.section_code)===location.section_code);
      const tiles=section?.tiles||(this.overview?.tiles||[]).filter(item=>item.section_code===location.section_code).map(item=>item.code||item.tile_code);
      if(!location.tile_code&&tiles.length===1)location.tile_code=typeof tiles[0]==='string'?tiles[0]:tiles[0].code;
      if(!section||!location.tile_code)throw new Error(this.t('The detail location could not be resolved. Refresh and try again.','موقعیت دقیق جایگاه پیدا نشد. تازه‌سازی و دوباره تلاش کنید.'));
      await this.openSection(location.section_code,location.tile_code);
      const found=unit.type==='seat'?this.tile?.seats.find(item=>item.id===unit.id||item.code===unit.code):this.tile?.groups.find(item=>String(item.id)===String(unit.id)||item.code===unit.code);
      if(!found)throw new Error(this.t('This place was not found in its detail area. Refresh and try again.','جایگاه در ناحیه جزئیات یافت نشد. تازه‌سازی و دوباره تلاش کنید.'));
      this.setView('map');this.viewSelect.value='map';this.renderer.focus(found);this.renderer.active.focus();
    }catch(error){this.message(error.message,true);}
  }
  async search() {
    const query = this.searchInput.value.trim(); if (!query) return;
    try {
      const prefix = this.admin ? `admin/layouts/${this.layoutId}` : `sessions/${this.sessionId}`;
      const result = await this.api.request(`${prefix}/locate?code=${encodeURIComponent(query)}`); const item = result.item || result;
      await this.openSection(item.section_code,item.tile_code);
      const found = this.tile?.seats.find(s=>s.code===query || s.id===Number(item.seat_id || item.id)) || this.tile?.groups.find(g=>g.code===query);
      if (found) { this.setView('map'); this.viewSelect.value='map'; this.renderer.focus(found); this.renderer.active.focus(); }
    } catch (error) { this.message(error.message,true); }
  }
  formatMoney(value) { return money(value, Number(this.selection.quote?.price_decimals ?? this.overview?.price_decimals ?? this.settings?.price_decimals ?? 0), this.selection.quote?.currency || this.overview?.currency || this.settings?.currency || '', this.fa?'fa':'en'); }
  previewData() { return {scope:`${this.layoutId}:${this.tileCode||'overview'}:${this.levelSelect?.value||''}`,seats:(this.tile?.seats || []).map(seat=>({...seat,available:this.isAvailable(seat)})),groups:this.tile?.groups || [],objects:[...(this.overview?.objects || []),...(this.tile?.objects || [])],sections:this.tile?[]:this.visibleSections(),selected:[...this.selection.selected.keys()],mode:this.unitSelect?.value||'seat'}; }
  async open3D() {
    if (this.preview) { this.preview.dispose(); this.preview=null; this.previewHost.hidden=true; this.previewButton.textContent=this.t('3D preview','پیش‌نمایش سه‌بعدی'); return; }
    if (!this.config.preview3dUrl) { this.message(this.t('3D preview is unavailable. The map and list remain ready.','پیش‌نمایش سه‌بعدی در دسترس نیست. نقشه و فهرست آماده استفاده‌اند.'),true); return; }
    this.previewButton.disabled=true;
    try {
      const module = await import(/* @vite-ignore */ safeLink(this.config.preview3dUrl)); this.previewHost.hidden=false;
      this.preview=module.createPreview(this.previewHost,this.previewData(),{onSelect:unit=>this.selectUnit(unit),label:this.t('Schematic 3D preview','پیش‌نمایش شماتیک سه‌بعدی'),onError:error=>{this.message(error.message,true);this.preview?.dispose();this.preview=null;this.previewHost.hidden=true;}});
      this.previewButton.textContent=this.t('Close 3D','بستن سه‌بعدی');
    } catch(error) { this.previewHost.hidden=true; this.message(this.t('3D could not start. Continue with the map or list.','نمای سه‌بعدی اجرا نشد. از نقشه یا فهرست استفاده کنید.'),true); }
    finally { this.previewButton.disabled=false; }
  }
  layoutSwitchBlocked(){return Boolean(this.selection.busy||this.pendingCommand||this.sessionConfigPending||(this.currentJob&&this.currentJob!==this.failedJob));}
  syncDesignerURL(navigate=false,hallId=Number(this.config.hallId)||0,layoutId=this.layoutId,sessionId=this.sessionId){
    if(!this.admin)return;
    const url=venueDesignerURL(window.location.href,hallId,layoutId,sessionId);
    if(navigate)window.location.assign(url.href);
    else if(url.href!==window.location.href)window.history.replaceState(window.history.state,'',url.href);
  }
  changeHall(){
    const hallId=Number(this.hallSelect.value),hall=(this.config.halls||[]).find(item=>Number(item.id)===hallId);
    if(!hall||this.layoutSwitchBlocked()){this.hallSelect.value=String(this.config.hallId||'');if(hall)this.message(this.t('Finish the pending save or layout job before switching.','پیش از جابه‌جایی، ذخیره یا پردازش معلق را کامل کنید.'),true);return;}
    const layout=Object.prototype.hasOwnProperty.call(hall,'venue_layout_id')?hall.venue_layout_id:hall.current_layout_id;
    this.syncDesignerURL(true,hallId,Number(layout)||0,0);
  }
  renderLayoutVersions(){
    if(!this.layoutVersionSelect)return;
    const versions=this.layoutVersions?.get(Number(this.config.hallId))||[];
    const statuses={draft:this.t('Draft','پیش‌نویس'),published:this.t('Published','منتشرشده'),archived:this.t('Archived','بایگانی‌شده')};
    this.layoutVersionSelect.replaceChildren(option('',versions.length?this.t('Choose a saved version','نسخه ذخیره‌شده را انتخاب کنید'):this.t('No saved versions yet','هنوز نسخه‌ای ذخیره نشده است')),...versions.map(version=>option(version.id,`${this.t('Version','نسخه')} ${version.version_no||`#${version.id}`} · ${statuses[version.status]||statuses.draft}${version.seat_count===undefined?'':` · ${Number(version.seat_count).toLocaleString(this.fa?'fa':'en')} ${this.t('places','جایگاه')}`}`)));
    this.layoutVersionSelect.value=String(this.layoutId||'');this.layoutVersionSelect.disabled=this.layoutSwitchBlocked()||!versions.length;
  }
  rememberLayoutVersion(){
    if(!this.admin||!this.layoutId)return;
    this.layoutVersions||=new Map();const hallId=Number(this.config.hallId)||0,versions=this.layoutVersions.get(hallId)||[];
    const previous=versions.find(version=>Number(version.id)===this.layoutId)||{};
    const current={...previous,id:this.layoutId,status:this.overview?.status||previous.status||'draft',seat_count:this.overview?.capacity??previous.seat_count};
    this.layoutVersions.set(hallId,[current,...versions.filter(version=>Number(version.id)!==this.layoutId)]);
    this.config.layoutId=this.layoutId;if(current.status==='published'){const hall=(this.config.halls||[]).find(item=>Number(item.id)===hallId);if(hall)hall.venue_layout_id=this.layoutId;}
    this.renderLayoutVersions();this.syncDesignerURL();
  }
  clearSessionSelection(){
    this.sessionConfigRequest=(this.sessionConfigRequest||0)+1;this.sessionId=0;this.sessionConfig=null;this.sessionConfigId=0;this.sessionConfigPending=false;
    if(this.sessionSelect)this.sessionSelect.value='';this.setSessionSettingsPending(false);
  }
  async selectLayoutVersion(){
    const id=Number(this.layoutVersionSelect.value),known=(this.layoutVersions?.get(Number(this.config.hallId))||[]).some(version=>Number(version.id)===id);
    if(!known||this.layoutSwitchBlocked()){this.renderLayoutVersions();return;}
    if(id===this.layoutId){this.syncDesignerURL();return;}
    this.clearSessionSelection();this.selection.clear();this.undo=[];this.redo=[];
    const previous=this.layoutId;this.layoutId=id;
    await this.perform(async()=>{try{await this.loadOverview();}catch(error){this.layoutId=previous;this.renderLayoutVersions();throw error;}});
  }
  mountDesigner() {
    const t = this.t;
    this.designer = el('section', {class:'pesm-v-designer'});
    const steps = [t('Venue','سالن'),t('Configuration','چیدمان'),t('Capacity','ظرفیت'),t('Review & edit','بازبینی و ویرایش'),t('Publish','انتشار')];
    this.designer.append(el('ol',{class:'pesm-v-steps'},steps.map((step,i)=>el('li',{},el('span',{text:String(i+1)}),step))));
    this.hallSelect=el('select',{name:'hall_id',required:true,onchange:()=>this.changeHall()});
    this.hallSelect.append(option('',t('Choose a hall','سالن را انتخاب کنید')),...(this.config.halls||[]).map(h=>option(h.id,h.name)));
    if(this.config.hallId&&!this.hallSelect.querySelector(`option[value="${Number(this.config.hallId)}"]`))this.hallSelect.append(option(this.config.hallId,this.config.hallName||`${t('Hall','سالن')} ${this.config.hallId}`));
    this.hallSelect.value=String(this.config.hallId||'');
    this.layoutVersionSelect=el('select',{name:'layout_id',onchange:()=>this.selectLayoutVersion()});
    this.renderLayoutVersions();
    const refreshVersions=button(t('Reload saved versions','بارگذاری دوباره نسخه‌های ذخیره‌شده'),()=>{if(!this.layoutSwitchBlocked())this.syncDesignerURL(true);});
    this.venueFilter=el('select',{'aria-label':t('Venue type','نوع سالن'),onchange:()=>this.renderPresets()},option('',t('All venue types','همه نوع سالن')));
    this.presetSearch=el('input',{type:'search',placeholder:t('Search configurations','جست‌وجوی چیدمان'),oninput:()=>this.renderPresets(),'aria-label':t('Search configurations','جست‌وجوی چیدمان')});
    this.presetCards=el('div',{class:'pesm-v-presets'});
    this.presetDetails=el('div',{class:'pesm-v-preset-options'});
    this.workflow=el('select',{name:'workflow',onchange:()=>this.renderPresetFields()},option('exact',t('Exact seat count','تعداد دقیق جایگاه')),option('advanced',t('Advanced arrangement','چیدمان پیشرفته')));
    this.capacityInput=number('capacity',900,1,150000);
    this.generateButton=button(t('Generate editable draft','ساخت پیش‌نویس قابل ویرایش'),()=>this.generate(),true);
    this.capacityLabel=el('span',{class:'pesm-v-capacity',text:t('No layout loaded','چیدمانی بارگذاری نشده است')});
    this.saveState=el('span',{class:'pesm-v-save-state',role:'status',text:t('Choose a configuration','یک چیدمان انتخاب کنید')});
    this.publishButton=button(t('Publish this version','انتشار این نسخه'),()=>this.publish(),true);this.publishButton.disabled=true;
    this.retrySaveButton=button(t('Retry pending save','تلاش دوباره ذخیره'),()=>{if(this.pendingCommand)this.saveCommands(this.pendingCommand.operations,this.pendingCommand.inverse,this.pendingCommand.record);});this.retrySaveButton.hidden=true;
    const reloadDraft=button(t('Reload saved draft','بارگذاری پیش‌نویس ذخیره‌شده'),async()=>{this.pendingCommand=null;this.retrySaveButton.hidden=true;this.undo=[];this.redo=[];this.selection.clear();await this.loadOverview();});
    this.retryJobButton=button(t('Retry failed job','اجرای دوباره پردازش'),()=>this.perform(async()=>{const job=await this.api.request(`admin/jobs/${this.failedJob}/retry`,{method:'POST',body:{request_key:requestKey()}});this.failedJob=null;this.retryJobButton.hidden=true;await this.watchJob(job);}));this.retryJobButton.hidden=true;
    this.resumeJobButton=button(t('Resume processing','ادامه پردازش'),async()=>{if(!this.currentJob)return;this.resumeJobButton.disabled=true;try{await this.api.request(`admin/jobs/${this.currentJob}/run`,{method:'POST',body:{request_key:requestKey()}});}catch(error){this.message(error.message,true);}finally{this.resumeJobButton.disabled=false;}});this.resumeJobButton.hidden=true;
    this.importFile=el('input',{type:'file',accept:'.jsonl,.pesmlayout.jsonl,application/x-ndjson','aria-label':t('Import layout file','ورود فایل چیدمان')});
    const fileTools=el('details',{class:'pesm-v-file-tools'},el('summary',{text:t('Import, export & print','ورود، خروجی و چاپ')}),this.importFile,
      button(t('Import as a new draft','ورود به‌عنوان پیش‌نویس جدید'),()=>this.importLayout()),
      button(t('Export layout','دریافت فایل چیدمان'),()=>this.download(`admin/layouts/${this.layoutId}/export`,`layout-${this.layoutId}.pesmlayout.jsonl`)),
      button(t('Print current section','چاپ بخش فعلی'),()=>this.download(`admin/layouts/${this.layoutId}/print${this.sectionCode?`?section=${encodeURIComponent(this.sectionCode)}`:''}`,`layout-${this.layoutId}.html`,true)));
    this.progress=el('progress',{max:100,value:0,hidden:true,'aria-label':t('Layout job progress','پیشرفت پردازش چیدمان')});
    this.jobLabel=el('span',{class:'pesm-v-help'});
    this.designer.append(el('div',{class:'pesm-v-form-grid'},field(t('Venue hall','سالن'),this.hallSelect),field(t('Saved layout version','نسخه ذخیره‌شده چیدمان'),this.layoutVersionSelect)),refreshVersions,el('div',{class:'pesm-v-form-grid'},field(t('Venue type','نوع سالن'),this.venueFilter),field(t('Search','جست‌وجو'),this.presetSearch)),
      this.presetCards,el('div',{class:'pesm-v-form-grid'},field(t('Capacity workflow','روش تعیین ظرفیت'),this.workflow),field(t('Requested places','تعداد جایگاه موردنظر'),this.capacityInput)),this.presetDetails,
      el('div',{class:'pesm-v-toolbar'},this.generateButton,this.capacityLabel,this.saveState,this.publishButton,reloadDraft,this.retrySaveButton),this.progress,this.jobLabel,this.retryJobButton,this.resumeJobButton,fileTools);
    this.root.append(this.designer);
    this.api.request('presets').then(result=>{
      this.presets=Array.isArray(result)?result:result.presets||result.items||[];
      const names={cinema:t('Cinema','سینما'),theater:t('Theater','تئاتر'),theatre:t('Theater','تئاتر'),conference:t('Conference','کنفرانس'),restaurant:t('Restaurant','رستوران'),fashion:t('Fashion show','نمایش مد'),fashion_show:t('Fashion show','نمایش مد'),sports:t('Sports hall','سالن ورزشی'),sports_hall:t('Sports hall','سالن ورزشی'),stadium:t('Stadium','استادیوم')};
      for(const category of new Set(this.presets.map(p=>p.category)))this.venueFilter.append(option(category,names[category]||category));
      this.renderPresets();
    }).catch(error=>this.message(error.message,true));
  }
  mountBooking(){
    const t=this.t;this.basket=el('section',{class:'pesm-v-basket','aria-label':t('Reservation summary','خلاصه رزرو')});
    this.basketItems=el('div',{class:'pesm-v-basket-items'});this.totalLabel=el('strong',{class:'pesm-v-total',role:'status','aria-live':'polite'});this.timerLabel=el('span',{class:'pesm-v-countdown','aria-live':'off'});
    this.holdButton=button(t('Hold selected places','نگهداری موقت جایگاه‌ها'),()=>this.acquire(),true);
    this.replaceButton=button(t('Update reservation','ثبت تغییر رزرو'),()=>this.mutateHold('replace'));
    this.renewButton=button(t('Extend hold','تمدید مهلت'),()=>this.mutateHold('renew'));
    this.releaseButton=button(t('Release hold','آزاد کردن رزرو'),()=>this.releaseHold());
    this.cartButton=button(t('Add reservation to cart','افزودن رزرو به سبد'),()=>this.attachCart());
    this.checkoutForm=el('form',{class:'pesm-v-checkout',hidden:true});
    const inputs=[['first_name',t('First name','نام'),'given-name'],['last_name',t('Last name','نام خانوادگی'),'family-name'],['phone',t('Phone','شماره همراه'),'tel'],['email',t('Email','ایمیل'),'email']];
    const fields=el('div',{class:'pesm-v-form-grid'});for(const[name,label,autocomplete]of inputs)fields.append(field(label,el('input',{type:name==='email'?'email':name==='phone'?'tel':'text',name,required:name!=='email',autocomplete,maxLength:190})));
    this.attendeeFields=el('div',{class:'pesm-v-attendees'});this.ticketModeFields=el('div',{class:'pesm-v-ticket-modes'});
    this.submitOrder=el('button',{type:'submit',class:'pesm-v-button is-primary',text:t('Continue to order','ادامه ثبت سفارش')});
    this.checkoutForm.append(el('h3',{text:t('Buyer details','مشخصات خریدار')}),fields,this.ticketModeFields,el('details',{},el('summary',{text:t('Guest names (optional)','نام مهمان‌ها (اختیاری)')}),this.attendeeFields),this.submitOrder);
    this.checkoutForm.addEventListener('submit',event=>{event.preventDefault();this.checkout();});
    this.receipt=el('div',{class:'pesm-v-receipt',hidden:true});
    this.basket.append(el('h3',{text:t('Your reservation','رزرو شما')}),this.basketItems,this.totalLabel,this.timerLabel,
      el('div',{class:'pesm-v-toolbar'},this.holdButton,this.replaceButton,this.renewButton,this.releaseButton,this.cartButton),
      el('p',{class:'pesm-v-help',text:t('Selection is confirmed only after the server creates a hold. Prices shown on an active hold are fixed for its limited lifetime.','انتخاب تنها پس از ثبت رزرو موقت در سرور قطعی می‌شود. قیمت رزرو فعال در مهلت محدود آن ثابت می‌ماند.')}),this.checkoutForm,this.receipt);
    this.root.append(this.basket);this.renderSelection();
  }
  renderSelection(){
    if(this.preview)this.preview.update(this.previewData());
    if(this.admin){if(this.hallSelect)this.hallSelect.disabled=this.layoutSwitchBlocked();if(this.layoutVersionSelect)this.layoutVersionSelect.disabled=this.layoutSwitchBlocked()||!(this.layoutVersions?.get(Number(this.config.hallId))||[]).length;if(this.undoButton){this.undoButton.disabled=this.selection.busy||!this.undo.length;this.redoButton.disabled=this.selection.busy||!this.redo.length;}return;}
    if(!this.basket)return;
    this.basketItems.replaceChildren();
    for(const unit of this.selection.selected.values()){
      const remove=button(this.t('Remove','حذف'),()=>this.selectUnit(unit));remove.disabled=this.selection.busy;
      this.basketItems.append(el('div',{class:'pesm-v-basket-line'},el('span',{text:`${unit.code||unit.id} · ${unit.type==='seat'?this.t('Seat','صندلی'):`${unit.capacity||''} ${this.t('places','جایگاه')}`}`}),remove));
    }
    const quote=this.hold&&this.holdSignature===this.selection.signature()?this.hold.quote:this.selection.quote;
    this.totalLabel.textContent=quote?`${this.t('Total','جمع')}: ${this.formatMoney(quote.total_minor)} · ${quote.admissions} ${this.t('admissions','نفر')}`:this.selection.selected.size?this.t('Checking server price…','در حال بررسی قیمت سرور…'):this.t('No places selected.','جایگاهی انتخاب نشده است.');
    const busy=this.selection.busy||this.revalidationRequired,dirty=this.holdSignature!==this.selection.signature();
    const cart=this.config.context==='cart';
    this.holdButton.hidden=Boolean(this.hold)||cart;this.holdButton.disabled=busy||!this.selection.selected.size||!this.selection.quote;
    this.replaceButton.hidden=!this.hold;this.replaceButton.disabled=busy||!dirty||!this.selection.selected.size||!this.selection.quote;
    this.renewButton.hidden=!this.hold||cart;this.renewButton.disabled=busy||dirty;
    this.releaseButton.hidden=!this.hold||cart;this.releaseButton.disabled=busy;
    this.cartButton.hidden=!this.hold||cart||this.config.cartEnabled===false;this.cartButton.disabled=busy||dirty;
    this.checkoutForm.hidden=!this.hold;this.submitOrder.disabled=busy||dirty||!this.hold;
  }
  async quoteSelection(){
    const sequence=++this.pendingQuote,sessionId=this.sessionId,signature=this.selection.signature();const current=()=>sequence===this.pendingQuote&&sessionId===this.sessionId&&signature===this.selection.signature();if(!this.selection.selected.size){this.selection.quote=null;this.renderSelection();return;}
    if(this.hold&&this.holdSignature===this.selection.signature()){this.selection.quote=this.hold.quote;this.renderSelection();return;}
    try{const quote=await this.api.request(`sessions/${sessionId}/quote`,{method:'POST',body:{units:this.selection.payload()}});if(!current())return;this.selection.quote=quote.quote||quote;this.renderSelection();}
    catch(error){if(current()){this.selection.quote=null;this.message(error.message,true);this.renderSelection();}}
  }
  async acquire(){
    if(!this.selection.selected.size||!this.selection.quote)return;const signature=this.selection.signature();
    if(!this.pendingHold||this.pendingHold.signature!==signature)this.pendingHold={signature,key:requestKey()};
    await this.perform(async()=>{const hold=await this.api.request(`sessions/${this.sessionId}/holds`,{method:'POST',body:{units:this.selection.payload(),idempotency_key:this.pendingHold.key}});this.acceptHold(hold,signature);this.pendingHold=null;await this.refreshAvailability();},this.t('Places held. Complete the buyer details to continue.','جایگاه‌ها موقتاً نگهداری شدند. برای ادامه مشخصات خریدار را کامل کنید.'));
  }
  acceptHold(value,signature,defaults={}){
    const hold=value.hold||value;hold.hold_token=hold.hold_token||hold.token||this.hold?.hold_token;
    this.hold=hold;this.holdSignature=signature;this.selection.quote=hold.quote;this.serverOffset=utcMillis(hold.server_time_utc||hold.server_now_utc)-Date.now();if(!Number.isFinite(this.serverOffset))this.serverOffset=0;
    this.ownSeatIds=new Set((hold.quote?.lines||[]).flatMap(line=>line.seat_ids||[]).map(Number));
    if(this.config.context!=='cart')try{sessionStorage.setItem(`pesm-v2-hold-${this.sessionId}`,JSON.stringify({token:hold.hold_token,units:(hold.quote?.lines||[]).map(line=>({type:line.unit_type==='seat'?'seat':line.unit_type==='box'?'box':'table',id:line.unit_type==='seat'?Number(line.unit_id):String(line.group_code||line.unit_id),code:String(line.group_code||line.unit_id),group_code:line.group_code||'',section_code:line.section_code,category_code:line.category_code,capacity:line.admissions}))}));}catch(_){}
    this.buildAttendeeForm(defaults);this.tick();this.renderSelection();
  }
  async resumeHold(){
    if(this.config.context==='cart'){
      try{const data=await this.api.request(`cart/preview?cart_key=${encodeURIComponent(this.config.cartKey)}`);
        if(Number(data.session_id)!==this.sessionId)throw new Error(this.t('Cart session mismatch.','جلسه سبد با رزرو مطابقت ندارد.'));
        const hold=data.hold||data;
        if(!this.selection.selected.size)for(const line of hold.quote?.lines||[]){const unit={type:line.unit_type,id:line.unit_id,code:String(line.group_code||line.unit_id),group_code:line.group_code,section_code:line.section_code,category_code:line.category_code,capacity:line.admissions};this.selection.selected.set(unitKey(unit),unit);}
        this.acceptHold(hold,this.holdSignature||this.selection.signature(),{ticket_modes:data.ticket_modes,attendees:data.attendees});
      }catch(error){this.hold=null;this.holdSignature=null;this.ownSeatIds.clear();this.renderSelection();this.message(error.message,true);throw error;}
      return;
    }
    let stored;try{stored=JSON.parse(sessionStorage.getItem(`pesm-v2-hold-${this.sessionId}`)||'null');}catch(_){return;}
    const token=this.hold?.hold_token||stored?.token;if(!token)return;
    try{const data=await this.api.request(`sessions/${this.sessionId}/holds`,{token});const hold=data.hold||data;
      if(!['active','held'].includes(hold.status||'active'))throw new Error(this.t('Reservation is no longer active.','این رزرو دیگر فعال نیست.'));
      if(!this.selection.selected.size)for(const unit of stored?.units||[])this.selection.selected.set(unitKey(unit),unit);
      hold.hold_token=token;this.acceptHold(hold,this.holdSignature||this.selection.signature());
    }catch(error){this.hold=null;this.holdSignature=null;this.ownSeatIds.clear();try{sessionStorage.removeItem(`pesm-v2-hold-${this.sessionId}`);}catch(_){}this.message(error.message,true);this.renderSelection();}
  }
  buildAttendeeForm(defaults={}){
    const current={ticket_modes:{},attendees:{}};
    for(const input of this.ticketModeFields.querySelectorAll('[data-group-code]'))current.ticket_modes[input.dataset.groupCode]=input.value;
    for(const input of this.attendeeFields.querySelectorAll('[data-seat-id]')){current.attendees[input.dataset.seatId]||={};current.attendees[input.dataset.seatId][input.dataset.part]=input.value;}
    const values=bookingFormState(this.hold?.quote?.lines,current,defaults);
    this.ticketModeFields.replaceChildren();this.attendeeFields.replaceChildren();
    const mode=this.hold?.quote?.ticket_mode||this.overview?.ticket_mode||this.settings?.ticket_mode||'individual';
    for(const line of this.hold?.quote?.lines||[]){
      if(line.group_code&&mode==='buyer_choice'){
        const choose=el('select',{name:`ticket_mode_${line.group_code}`},option('individual',this.t('One QR per guest','یک کد برای هر مهمان')),option('group',this.t('One shared group QR','یک کد مشترک گروه')));
        choose.dataset.groupCode=line.group_code;choose.value=values.ticket_modes[line.group_code];this.ticketModeFields.append(field(`${line.group_code} · ${this.t('Tickets','بلیت‌ها')}`,choose));
      }else if(line.group_code)this.ticketModeFields.append(el('p',{class:'pesm-v-help',text:`${line.group_code} · ${mode==='group'?this.t('Shared group QR','کد گروهی مشترک'):this.t('Individual guest tickets','بلیت جداگانه مهمان‌ها')}`}));
      for(const seatId of line.seat_ids||[]){const known=this.tile?.seats.find(s=>s.id===Number(seatId)),saved=values.attendees[seatId];this.attendeeFields.append(el('div',{class:'pesm-v-form-grid'},field(`${known?.code||seatId} · ${this.t('First name','نام')}`,el('input',{type:'text',name:`attendee_first_${seatId}`,value:saved.first_name,maxLength:90,'data-seat-id':seatId,'data-part':'first_name'})),field(this.t('Last name','نام خانوادگی'),el('input',{type:'text',name:`attendee_last_${seatId}`,value:saved.last_name,maxLength:90,'data-seat-id':seatId,'data-part':'last_name'}))));}
    }
  }
  async mutateHold(action){
    if(!this.hold)return;const signature=this.selection.signature(),mutationSignature=`${action}:${signature}`;
    if(!this.pendingMutation||this.pendingMutation.signature!==mutationSignature)this.pendingMutation={signature:mutationSignature,key:requestKey()};
    await this.perform(async()=>{const cart=this.config.context==='cart';const data=await this.api.request(cart?'cart/replace':`sessions/${this.sessionId}/holds`,{method:cart?'POST':'PATCH',token:cart?undefined:this.hold.hold_token,body:cart?{cart_key:this.config.cartKey,units:this.selection.payload(),request_key:this.pendingMutation.key}:{action,idempotency_key:this.pendingMutation.key,...(action==='replace'?{units:this.selection.payload()}:{})}});this.acceptHold(data,signature);this.pendingMutation=null;await this.refreshAvailability();},action==='renew'?this.t('Hold extended.','مهلت رزرو تمدید شد.'):this.t('Reservation updated.','رزرو به‌روزرسانی شد.'));
  }
  buyerFields(){
    const data=new FormData(this.checkoutForm),buyer={};for(const key of ['first_name','last_name','phone','email'])buyer[key]=String(data.get(key)||'');
    buyer.ticket_modes={};for(const input of this.ticketModeFields.querySelectorAll('[data-group-code]'))buyer.ticket_modes[input.dataset.groupCode]=input.value;
    buyer.attendees={};for(const input of this.attendeeFields.querySelectorAll('[data-seat-id]'))if(input.value.trim()){buyer.attendees[input.dataset.seatId]||={};buyer.attendees[input.dataset.seatId][input.dataset.part]=input.value.trim();}
    if(!Object.keys(buyer.attendees).length)delete buyer.attendees;return buyer;
  }
  async attachCart(){if(!this.hold||this.holdSignature!==this.selection.signature())return;await this.perform(async()=>{const buyer=this.buyerFields();const data=await this.api.request('cart/attach',{method:'POST',token:this.hold.hold_token,body:{session_id:this.sessionId,attendees:buyer.attendees,ticket_modes:buyer.ticket_modes}});location.assign(safeLink(data.cart_url));});}
  async releaseHold(){if(!this.hold)return;await this.perform(async()=>{await this.api.request(`sessions/${this.sessionId}/holds`,{method:'DELETE',token:this.hold.hold_token,body:{request_key:requestKey()}});this.hold=null;this.holdSignature=null;this.ownSeatIds.clear();this.selection.selected.clear();this.selection.quote=null;try{sessionStorage.removeItem(`pesm-v2-hold-${this.sessionId}`);}catch(_){}await this.refreshAvailability();},this.t('Reservation released.','رزرو آزاد شد.'));}
  async checkout(){
    if(!this.hold||!this.checkoutForm.reportValidity()||this.holdSignature!==this.selection.signature())return;
    const buyer=this.buyerFields();
    await this.perform(async()=>{const cart=this.config.context==='cart';const order=await this.api.request(cart?'cart/complete':`sessions/${this.sessionId}/checkout`,{method:'POST',token:cart?undefined:this.hold.hold_token,body:cart?{cart_key:this.config.cartKey,...buyer}:buyer});try{sessionStorage.setItem('pesm-v2-last-order',JSON.stringify({order_id:order.order_id,guest_access_token:order.guest_access_token}));}catch(_){}
      this.hold=null;this.holdSignature=null;this.ownSeatIds.clear();this.selection.selected.clear();this.selection.quote=null;try{sessionStorage.removeItem(`pesm-v2-hold-${this.sessionId}`);}catch(_){}this.receipt.hidden=false;this.receipt.replaceChildren(el('h3',{text:`${this.t('Order','سفارش')} #${order.order_id}`}),el('p',{text:this.t('Keep this private access key to retrieve your tickets.','برای دریافت بلیت‌ها این کلید دسترسی خصوصی را نگهداری کنید.')}),el('code',{class:'pesm-v-access-key',dir:'ltr',text:order.guest_access_token}));
      if(order.payment_required)this.receipt.append(el('a',{class:'pesm-v-button is-primary',href:safeLink(order.payment_url),text:this.t('Continue to WooCommerce payment','ادامه پرداخت ووکامرس')}));
      const ticketBox=el('div');this.receipt.append(button(this.t('Retrieve tickets','دریافت بلیت‌ها'),()=>this.retrieveTickets(order.order_id,order.guest_access_token,ticketBox)),ticketBox);
      if(!order.payment_required)await this.retrieveTickets(order.order_id,order.guest_access_token,ticketBox);await this.refreshAvailability();
    },this.t('Order created. Paid tickets are issued after payment confirmation.','سفارش ایجاد شد. بلیت پولی پس از تأیید پرداخت صادر می‌شود.'));
  }
  async refreshAvailability(){
    if(this.admin||!this.sessionId||!this.overview)return;
    const recoverQuote=async()=>{if(this.needsRequote&&!this.hold&&this.selection.selected.size){this.needsRequote=false;await this.quoteSelection();}};
    const params=new URLSearchParams();if(this.tileCode)params.set('tiles',this.tileCode);else if(this.sectionCode)params.set('sections',this.sectionCode);else{await recoverQuote();return;}
    if(this.availabilityRevision)params.set('since',JSON.stringify(this.availabilityRevision));
    const sequence=this.scopeVersion;
    const data=await this.api.request(`sessions/${this.sessionId}/availability?${params}`);if(sequence!==this.scopeVersion)return;
    if(data.snapshot){for(const seat of this.tile?.seats||[])this.availability.delete(`seat:${seat.id}`);for(const group of this.tile?.groups||[])this.availability.delete(`group:${group.id}`);}
    for(const item of [...(data.items||data.availability||[]),...(data.groups||[])]){if(item.seat_id)this.availability.set(`seat:${Number(item.seat_id)}`,item.status);else if(item.group_code)this.availability.set(`group:${item.group_code}`,item.status);}
    this.availabilityRevision=data.revisions;this.nextExpiry=utcMillis(data.next_expiry_utc);const now=utcMillis(data.server_time_utc);if(Number.isFinite(now))this.serverOffset=now-Date.now();
    clearTimeout(this.expiryTimer);if(Number.isFinite(this.nextExpiry))this.expiryTimer=setTimeout(()=>{if(this.active&&!document.hidden)this.refreshAvailability().catch(error=>this.message(error.message,true));},Math.max(100,this.nextExpiry-(Date.now()+(this.serverOffset||0))+75));
    this.renderer.schedule();this.updateListSelection();this.tick();
    await recoverQuote();
  }
  startTimers(){if(this.admin)return;clearTimeout(this.pollTimer);clearInterval(this.tickTimer);this.tickTimer=setInterval(()=>this.tick(),1000);const poll=async()=>{if(!this.active)return;if(!document.hidden)try{await this.refreshAvailability();this.pollDelay=10000;}catch(error){this.pollDelay=Math.min(60000,(this.pollDelay||10000)*2);this.message(error.message,true);}this.pollTimer=setTimeout(poll,this.pollDelay||10000);};this.pollTimer=setTimeout(poll,10000);}
  tick(){if(!this.timerLabel)return;if(!this.hold){this.timerLabel.textContent='';return;}const remaining=Math.max(0,Math.ceil((utcMillis(this.hold.expires_at_utc)-(Date.now()+(this.serverOffset||0)))/1000));this.timerLabel.textContent=`${this.t('Hold remaining','مهلت رزرو')}: ${Math.floor(remaining/60)}:${String(remaining%60).padStart(2,'0')}`;if(!remaining){this.hold=null;this.holdSignature=null;this.ownSeatIds.clear();this.selection.quote=null;this.pendingQuote++;this.needsRequote=true;try{sessionStorage.removeItem(`pesm-v2-hold-${this.sessionId}`);}catch(_){}this.renderSelection();this.message(this.t('Your hold expired. Recheck availability before reserving again.','مهلت رزرو تمام شد. پیش از رزرو دوباره موجودی را بررسی کنید.'),true);this.refreshAvailability().catch(error=>this.message(error.message,true));}}
  mountTickets(){
    const t=this.t,orderInput=number('order_id','',1,Number.MAX_SAFE_INTEGER),tokenInput=el('input',{type:'password',name:'guest_token',required:true,autocomplete:'off'}),results=el('div',{class:'pesm-v-ticket-results'});
    const form=el('form',{class:'pesm-v-ticket-form'},field(t('Order number','شماره سفارش'),orderInput),field(t('Private access key','کلید دسترسی خصوصی'),tokenInput),el('button',{type:'submit',class:'pesm-v-button is-primary',text:t('Retrieve tickets','دریافت بلیت‌ها')}));
    form.addEventListener('submit',event=>{event.preventDefault();if(!form.reportValidity())return;this.perform(()=>this.retrieveTickets(Number(orderInput.value),tokenInput.value,results));});
    const recoveryOrder=number('order_id','',1,Number.MAX_SAFE_INTEGER),recoveryEmail=el('input',{type:'email',required:true,autocomplete:'email'}),recoveryCode=el('input',{type:'text',required:true,inputMode:'numeric',autocomplete:'one-time-code',maxLength:12});
    const verify=el('form',{hidden:true},field(t('Verification code','کد تأیید'),recoveryCode),el('button',{type:'submit',class:'pesm-v-button',text:t('Verify and retrieve','تأیید و دریافت')}));
    const recovery=el('form',{},field(t('Order number','شماره سفارش'),recoveryOrder),field(t('Order email','ایمیل سفارش'),recoveryEmail),el('button',{type:'submit',class:'pesm-v-button',text:t('Send recovery code','ارسال کد بازیابی')}));
    recovery.addEventListener('submit',event=>{event.preventDefault();if(!recovery.reportValidity())return;this.perform(async()=>{const data=await this.api.request('guest-access/request',{method:'POST',body:{order_id:Number(recoveryOrder.value),email:recoveryEmail.value}});verify.hidden=false;this.message(data.message||t('If the details match, a verification code will be sent.','اگر اطلاعات مطابق باشد، کد تأیید ارسال می‌شود.'));recoveryCode.focus();});});
    verify.addEventListener('submit',event=>{event.preventDefault();if(!verify.reportValidity())return;this.perform(async()=>{const data=await this.api.request('guest-access/verify',{method:'POST',body:{order_id:Number(recoveryOrder.value),code:recoveryCode.value.trim()}});const token=data.guest_access_token||data.token;orderInput.value=String(recoveryOrder.value);tokenInput.value=token;await this.retrieveTickets(Number(recoveryOrder.value),token,results);verify.hidden=true;});});
    this.root.append(form,el('details',{},el('summary',{text:t('Recover ticket access','بازیابی دسترسی بلیت')}),recovery,verify),results);
    try{const recent=JSON.parse(sessionStorage.getItem('pesm-v2-last-order')||'null');if(recent){orderInput.value=String(recent.order_id);tokenInput.value=recent.guest_access_token;}}catch(_){}
  }
  async retrieveTickets(orderId,token,container){
    try{const result=await this.api.request(`tickets?order_id=${encodeURIComponent(orderId)}`,{guestToken:token});container.replaceChildren();
      for(const line of result.lines||[]){const card=el('article',{class:'pesm-v-ticket-group'},el('h4',{text:`${line.unit_id||line.group_code||''} · ${line.admissions} ${this.t('admissions','نفر')}`}));
        if(line.ticket_mode==='group'&&line.group_ticket){const group=line.group_ticket;card.append(this.ticketCard(group),el('p',{text:line.entry_policy==='incremental'?this.t('This shared QR supports counted arrivals. Tell the operator how many guests are entering.','با این کد مشترک می‌توان به‌تدریج وارد شد. تعداد افراد ورودی را به مسئول گیت بگویید.'):this.t('Present this shared QR when the entire party is ready to enter.','این کد مشترک را هنگام آماده بودن همه اعضای گروه ارائه دهید.')}),el('p',{text:`${this.t('Remaining','باقی‌مانده')}: ${group.remaining??group.capacity}`}));}
        else for(const ticket of line.tickets||[])card.append(this.ticketCard(ticket));
        container.append(card);
      }
      if(!result.lines?.length)container.append(el('p',{text:result.message||this.t('No issued tickets yet. If payment is pending, return after confirmation.','هنوز بلیتی صادر نشده است. اگر پرداخت در انتظار تأیید است، پس از تأیید دوباره بررسی کنید.')}));
      if(result.lines?.length)container.append(button(this.t('Print tickets','چاپ بلیت‌ها'),()=>window.print()));return result;
    }catch(error){this.message(error.message,true);throw error;}
  }
  ticketCard(ticket){const card=el('article',{class:'pesm-v-ticket'},el('strong',{text:ticket.ticket_number}),el('p',{text:[ticket.seat_code,ticket.attendee_name,ticket.status].filter(Boolean).join(' · ')}));if(ticket.qr_payload){if(window.PESM_QRCode){const qr=new window.PESM_QRCode(-1,0);qr.addData(ticket.qr_payload);qr.make();const count=qr.getModuleCount(),canvas=el('canvas',{width:(count+8)*5,height:(count+8)*5,role:'img','aria-label':this.t('Admission QR code','کد ورود بلیت')}),context=canvas.getContext('2d');context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.fillStyle='#000';for(let y=0;y<count;y++)for(let x=0;x<count;x++)if(qr.isDark(y,x))context.fillRect((x+4)*5,(y+4)*5,5,5);card.append(canvas);}else card.append(el('p',{class:'pesm-v-help',text:this.t('QR display is unavailable. Use the ticket number at the admission desk.','نمایش کد در دسترس نیست. شماره بلیت را به مسئول ورود بدهید.')}));}return card;}
  mountGate(){
    const t=this.t;this.gateForm=el('form',{class:'pesm-v-gate-form'});const credential=el('textarea',{name:'credential',rows:3,required:true,spellcheck:false,autocomplete:'off'}),quantity=number('quantity',1,1,200),gate=el('input',{type:'text',name:'gate',maxLength:100}),result=el('div',{role:'status',class:'pesm-v-gate-result'}),video=el('video',{hidden:true,playsInline:true,muted:true});
    const submit=el('button',{type:'submit',class:'pesm-v-button is-primary',text:t('Confirm admission','تأیید ورود')});
    this.gateForm.append(field(t('Ticket QR or ticket number','کد QR یا شماره بلیت'),credential),el('div',{class:'pesm-v-form-grid'},field(t('Guests entering now','تعداد افراد ورودی'),quantity,t('Confirm this quantity before scanning a shared group ticket.','برای بلیت گروهی، تعداد ورودی را پیش از ثبت تأیید کنید.')),field(t('Gate label','نام گیت'),gate)),submit);
    let pending=null,stream=null,scanning=false,frame=null;
    const cleanup=()=>{scanning=false;if(frame)cancelAnimationFrame(frame);stream?.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null;video.hidden=true;};
    const scan=async()=>{const value=credential.value.trim(),count=Number(quantity.value);if(!value||!this.gateForm.reportValidity()||this.selection.busy)return;const signature=`${value}:${count}`;if(pending&&pending.signature!==signature){this.message(t('Retry the same pending scan, or look up its current entry count before changing the request.','همان اسکن معلق را دوباره ارسال کنید؛ پیش از تغییر درخواست، تعداد ورود فعلی را بررسی کنید.'),true);return;}if(!pending)pending={signature,key:requestKey()};await this.perform(async()=>{const path=/^(A-[PG]-|P-)/i.test(value)?'gate/manual-check-in':'gate/check-in';const data=await this.api.request(path,{method:'POST',body:{credential:value,qr_payload:value,ticket_number:value,quantity:count,gate:gate.value,request_key:pending.key}});result.textContent=`${t('Admission recorded','ورود ثبت شد')} · ${data.ticket_number||''} · ${t('Remaining','باقی‌مانده')}: ${data.remaining??0}`;result.classList.remove('is-error');pending=null;credential.value='';cleanup();},t('Admission confirmed.','ورود تأیید شد.'));};
    this.gateForm.addEventListener('submit',event=>{event.preventDefault();scan();});
    const start=button(t('Start camera','شروع دوربین'),async()=>{if(scanning)return;if(!navigator.mediaDevices?.getUserMedia||!window.BarcodeDetector){this.message(t('Camera scanning is unavailable. Enter the code or ticket number.','اسکن دوربین در دسترس نیست. کد یا شماره بلیت را وارد کنید.'),true);return;}try{const detector=new BarcodeDetector({formats:['qr_code']});stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});video.srcObject=stream;video.hidden=false;await video.play();scanning=true;const detect=async()=>{if(!scanning)return;try{const codes=await detector.detect(video);if(codes.length){credential.value=codes[0].rawValue;cleanup();credential.focus();this.message(t('QR read. Confirm the arrival count, then record admission.','کد خوانده شد. تعداد افراد را تأیید و ورود را ثبت کنید.'));return;}}catch(_){}frame=requestAnimationFrame(detect);};detect();}catch(_){cleanup();this.message(t('Camera access failed.','دسترسی به دوربین ممکن نشد.'),true);}});
    this.root.append(el('div',{class:'pesm-v-toolbar'},start,button(t('Stop camera','توقف دوربین'),cleanup),button(t('Look up ticket number','بررسی شماره بلیت'),()=>this.perform(async()=>{const number=ticketNumberForLookup(credential.value);if(!number)throw new Error(t('Enter the printed ticket number for lookup.','برای بررسی، شماره چاپ‌شده بلیت را وارد کنید.'));const data=await this.api.request(`gate/lookup?ticket_number=${encodeURIComponent(number)}`);pending=null;result.textContent=`${data.ticket_number||''} · ${this.statusLabel(data.status||'')} · ${t('Remaining','باقی‌مانده')}: ${data.remaining??data.capacity??''}`;}))),video,this.gateForm,result);
    window.addEventListener('pagehide',cleanup,{signal:this.abort.signal});
  }
  renderPresets() {
    const search=this.presetSearch.value.toLocaleLowerCase(),category=this.venueFilter.value;
    const presets=(this.presets||[]).filter(p=>(!category||p.category===category)&&`${p.label} ${p.label_fa} ${p.category}`.toLocaleLowerCase().includes(search));
    this.presetCards.replaceChildren();this.thumbnailObserver?.disconnect();
    this.thumbnailObserver=new IntersectionObserver(entries=>{for(const entry of entries)if(entry.isIntersecting){this.thumbnailObserver.unobserve(entry.target);this.loadThumbnail(entry.target,entry.target.dataset.preset);}}, {rootMargin:'120px'});
    for(const preset of presets){
      const canvas=el('canvas',{width:220,height:120,'aria-hidden':'true'});canvas.dataset.preset=preset.id;
      const name=this.fa?(preset.label_fa||preset.label):preset.label;
      const card=button('',()=>{this.preset=preset;this.renderPresetFields();for(const c of this.presetCards.children)c.setAttribute('aria-pressed',String(c.dataset.preset===preset.id));});
      card.className='pesm-v-preset';card.dataset.preset=preset.id;card.setAttribute('aria-pressed',String(this.preset?.id===preset.id));
      card.append(canvas,el('strong',{text:name}),el('small',{text:this.t('Adjustable capacity','ظرفیت قابل تنظیم')}));this.presetCards.append(card);this.thumbnailObserver.observe(canvas);
    }
    if(!presets.length)this.presetCards.append(el('p',{text:this.t('No matching configurations.','چیدمانی با این جست‌وجو یافت نشد.')}));
  }
  async loadThumbnail(canvas,id){
    try{
      const data=await this.api.request(`presets/${encodeURIComponent(id)}/preview`),doc=data.preview||data;
      const seats=(doc.seats||[]).slice(0,100),objects=doc.objects||[],ctx=canvas.getContext('2d');if(!ctx)return;
      const items=[...seats,...objects];if(!items.length)return;
      const left=Math.min(...items.map(p=>Number(p.x||0))),top=Math.min(...items.map(p=>Number(p.y||0)));
      const right=Math.max(...items.map(p=>Number(p.x||0)+Number(p.width||500))),bottom=Math.max(...items.map(p=>Number(p.y||0)+Number(p.height||500)));
      const scale=Math.min(200/Math.max(1,right-left),100/Math.max(1,bottom-top));ctx.translate((220-(right-left)*scale)/2-left*scale,(120-(bottom-top)*scale)/2-top*scale);ctx.scale(scale,scale);
      ctx.fillStyle='#d7e0ed';for(const object of objects){ctx.save();ctx.translate(object.x+object.width/2,object.y+object.height/2);ctx.rotate(Number(object.rotation||0)*Math.PI/180000);ctx.fillRect(-object.width/2,-object.height/2,object.width,object.height);ctx.restore();}
      ctx.fillStyle='#4073bd';for(const seat of seats){ctx.beginPath();ctx.arc(Number(seat.x)+180,Number(seat.y)+180,180,0,Math.PI*2);ctx.fill();}
    }catch(_){canvas.hidden=true;}
  }
  renderPresetFields(){
    this.presetDetails.replaceChildren();if(!this.preset)return;
    if(this.parameterPresetId!==this.preset.id){this.parameterPresetId=this.preset.id;this.parameterValues=new Map();this.touchedParameters=new Set();}
    else for(const[name,input]of this.parameterInputs||[])this.parameterValues.set(name,input.type==='checkbox'?input.checked:input.value);
    this.capacityInput.min=String(this.preset.min_capacity||1);this.capacityInput.max=String(this.preset.max_capacity||150000);
    this.capacityInput.disabled=this.workflow.value==='advanced';
    this.capacityInput.closest('.pesm-v-field').hidden=this.workflow.value==='advanced';
    const labels={rows:['Rows','ردیف‌ها'],columns:['Seats per row','صندلی در هر ردیف'],sections:['Sections','بخش‌ها'],levels:['Tiers','طبقات'],blocks:['Blocks','بلوک‌ها'],group_count:['Tables or boxes per section','تعداد میز یا باکس در هر بخش'],group_size:['Preferred table/box capacity','ظرفیت ترجیحی میز یا باکس'],min_group_size:['Minimum group capacity','حداقل ظرفیت گروه'],spacing:['Seat spacing (mm)','فاصله صندلی (میلی‌متر)'],aisle_width:['Aisle width (mm)','عرض راهرو (میلی‌متر)'],row_spacing:['Row spacing (mm)','فاصله ردیف‌ها (میلی‌متر)'],seat_spacing:['Seat spacing (mm)','فاصله صندلی (میلی‌متر)'],tables:['Number of tables','تعداد میزها'],table_size:['Places per table','ظرفیت هر میز']};
    const defaults={...this.preset.defaults};
    let controls=Array.isArray(this.preset.controls)?this.preset.controls:Object.keys(this.preset.controls||{});
    controls=controls.map(c=>typeof c==='string'?{name:c}:c);
    if(!controls.length)controls=Object.keys(defaults).filter(key=>key!=='capacity'&&key!=='workflow').map(name=>({name}));
    if(this.workflow.value==='advanced'&&!controls.some(c=>(c.name||c.key)==='group_count'))for(const name of ['rows','columns'])if(!controls.some(c=>(c.name||c.key)===name))controls.push({name});
    this.parameterInputs=new Map();
    const grid=el('div',{class:'pesm-v-form-grid'});
    for(const control of controls){const name=control.name||control.key||control.id;if(!name||['capacity','workflow'].includes(name))continue;
      const label=labels[name]||(name==='fixed_group_size'?['Require fixed group size','ظرفیت ثابت گروه اجباری']:name==='sale_mode'?['Default group sale mode','روش پیش‌فرض فروش گروه']:[control.label||name,control.label_fa||control.label||name]);
      const limits={columns:[1,1000],sections:[1,200],levels:[1,8],blocks:[1,8],group_size:[1,200],min_group_size:[1,200],spacing:[450,3000],row_spacing:[600,5000],aisle_width:[900,10000]};
      let input;
      if(typeof defaults[name]==='boolean') input=el('input',{type:'checkbox',name,checked:defaults[name]});
      else if(name==='sale_mode') input=el('select',{name},option('both',this.t('Individual or whole group','تکی یا گروه کامل')),option('individual',this.t('Individual only','فقط تکی')),option('whole',this.t('Whole group only','فقط گروه کامل')));
      else input=number(name,defaults[name]??control.default??1,control.min??limits[name]?.[0]??1,control.max??limits[name]?.[1]??150000);
      if(name==='sale_mode')input.value=defaults[name]||'both';
      if(this.parameterValues.has(name)){if(input.type==='checkbox')input.checked=Boolean(this.parameterValues.get(name));else input.value=this.parameterValues.get(name);}
      const remember=()=>{this.touchedParameters.add(name);this.parameterValues.set(name,input.type==='checkbox'?input.checked:input.value);};input.addEventListener('input',remember);input.addEventListener('change',remember);
      this.parameterInputs.set(name,input);grid.append(field(this.t(...label),input));}
    const title=el('h3',{text:this.fa?(this.preset.label_fa||this.preset.label):this.preset.label});
    this.presetDetails.append(title,grid,el('p',{class:'pesm-v-help',text:this.t('Partial final rows and groups keep the exact requested capacity. Invalid fixed-size combinations are reported before publication.','ردیف و گروه پایانی می‌توانند کوچک‌تر باشند تا ظرفیت دقیق حفظ شود. ترکیب ناسازگار پیش از انتشار اعلام می‌شود.')}),el('p',{class:'pesm-v-help',text:this.t('In exact capacity mode, unchanged section, tier and block defaults adapt to fit. Edited values are enforced.','در روش ظرفیت دقیق، تعداد پیش‌فرض بخش، طبقه و بلوک در صورت ویرایش‌نشدن با ظرفیت سازگار می‌شود. مقدار ویرایش‌شده دقیقاً اعمال می‌شود.')}));
  }
  async generate(){
    if(!this.preset){this.message(this.t('Choose a configuration first.','ابتدا یک چیدمان انتخاب کنید.'),true);return;}
    if(!this.hallSelect.value){this.hallSelect.reportValidity();return;}
    const values={};for(const[name,input]of this.parameterInputs||[]){if(!input.reportValidity())return;values[name]=input.type==='checkbox'?input.checked:input.tagName==='SELECT'?input.value:Number(input.value);}
    const parameters=generationParameters(this.workflow.value,this.capacityInput.value,values,this.touchedParameters);
    if(this.workflow.value==='exact'&&!this.capacityInput.reportValidity())return;
    if(parameters.workflow==='exact'&&parameters.fixed_group_size&&parameters.capacity%parameters.group_size){const size=parameters.group_size,lower=Math.max(size,Math.floor(parameters.capacity/size)*size),upper=Math.max(lower+size,Math.ceil(parameters.capacity/size)*size);this.message(`${this.t('Fixed group size cannot fit this capacity. Nearby valid capacities','ظرفیت با اندازه ثابت گروه سازگار نیست. ظرفیت‌های معتبر نزدیک')}: ${lower}, ${upper}.`,true);return;}
    await this.perform(async()=>{const hall=(this.config.halls||[]).find(h=>Number(h.id)===Number(this.hallSelect.value));const job=await this.api.request('admin/jobs',{method:'POST',body:{center_id:Number(hall?.center_id||this.config.centerId),hall_id:Number(this.hallSelect.value),kind:'generate',payload:{preset_id:this.preset.id,parameters}}});await this.watchJob(job);});
  }
  async watchJob(initial){
    let job=initial.job||initial;const id=Number(job.id||job.job_id);this.currentJob=id;this.progress.hidden=false;this.resumeJobButton.hidden=false;
    while(this.active){
      const status=String(job.status||'queued');const progress=Number(job.progress||job.percent||(job.total?Number(job.processed||job.cursor||0)/Number(job.total)*100:0));
      this.progress.value=Math.min(100,progress);this.jobLabel.textContent=`${this.t('Processing layout','پردازش چیدمان')} · ${Math.round(progress)}%`;
      if(['failed','error','cancelled'].includes(status)){this.failedJob=id;this.retryJobButton.hidden=false;throw new Error(job.error||job.last_error||this.t('Layout processing failed.','پردازش چیدمان انجام نشد.'));}
      if(['completed','complete','ready','done','published'].includes(status)){
        this.layoutId=Number(job.layout_id||job.result?.layout_id||this.layoutId);this.progress.value=100;this.jobLabel.textContent=this.t('Layout ready.','چیدمان آماده است.');this.resumeJobButton.hidden=true;this.currentJob=null;
        if(['generate','import'].includes(job.kind))this.clearSessionSelection();
        if(this.layoutId)await this.loadOverview();return job;
      }
      await new Promise(resolve=>setTimeout(resolve,1000));if(!this.active)return;
      job=await this.api.request(`admin/jobs/${id}`);job=job.job||job;
    }
  }
  async publish(){if(!this.layoutId)return;if(this.pendingCommand){this.message(this.t('Retry the pending save or reload the draft before publishing.','پیش از انتشار، ذخیره معلق را دوباره ارسال یا پیش‌نویس را بارگذاری کنید.'),true);return;}await this.perform(async()=>{const result=await this.api.request(`admin/layouts/${this.layoutId}/publish`,{method:'POST',body:{revision:this.revision,request_key:requestKey()}});if(result.job||result.job_id||result.kind)await this.watchJob(result);else await this.loadOverview();},this.t('Published for future sessions. Existing sessions keep their original layout.','برای جلسه‌های آینده منتشر شد. جلسه‌های موجود نسخه قبلی خود را حفظ می‌کنند.'));}
  async importLayout(){const file=this.importFile.files?.[0];if(!file||!this.hallSelect.value)return;if(file.size>128*1024*1024){this.message(this.t('The file exceeds 128 MiB.','حجم فایل بیشتر از ۱۲۸ مگابایت است.'),true);return;}await this.perform(async()=>{const data=new FormData();data.append('file',file);data.append('hall_id',this.hallSelect.value);const response=await fetch(restURL('admin/import',this.config.rest),{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':this.config.nonce},body:data});const job=await response.json();if(!response.ok)throw new Error(job.message||'Import failed');await this.watchJob(job);});}
  async download(path,name,open=false){if(!this.layoutId)return;await this.perform(async()=>{const response=await fetch(restURL(path,this.config.rest),{credentials:'same-origin',headers:{'X-WP-Nonce':this.config.nonce}});if(!response.ok){const data=await response.json();throw new Error(data.message||'Download failed');}const url=URL.createObjectURL(await response.blob());if(open)window.open(url,'_blank','noopener');else{const a=el('a',{href:url,download:name});a.click();}setTimeout(()=>URL.revokeObjectURL(url),60000);});}
  mountInspector(){
    const t=this.t;this.inspector=el('aside',{class:'pesm-v-inspector'});this.inspectorTitle=el('h3',{text:t('Selection inspector','ویژگی‌های انتخاب')});
    this.editX=number('x',0,-10000000,10000000);this.editY=number('y',0,-10000000,10000000);
    this.editCategory=el('input',{type:'text',name:'category',maxLength:60});this.editAccessible=el('input',{type:'checkbox'});
    this.editGroupMode=el('select',{},option('both',t('Individual or whole group','تکی یا گروه کامل')),option('individual',t('Individual places','جایگاه تکی')),option('whole',t('Whole group only','فقط گروه کامل')));
    this.inspector.append(this.inspectorTitle,el('div',{class:'pesm-v-form-grid'},field(t('X (mm)','ایکس (میلی‌متر)'),this.editX),field(t('Y (mm)','وای (میلی‌متر)'),this.editY),field(t('Category','دسته'),this.editCategory)),el('label',{},this.editAccessible,t('Accessible place','جایگاه دسترس‌پذیر')),
      field(t('Group sale mode','روش فروش گروه'),this.editGroupMode),button(t('Apply properties','ثبت ویژگی‌ها'),()=>this.applyProperties(),true));
    this.undoButton=button(t('Undo','واگرد'),()=>this.history('undo'));this.redoButton=button(t('Redo','ازنو'));
    this.redoButton.addEventListener('click',()=>this.history('redo'));
    this.inspector.append(el('div',{class:'pesm-v-toolbar'},this.undoButton,this.redoButton,button(t('Align row','هم‌ترازی ردیف'),()=>this.align('y')),button(t('Align column','هم‌ترازی ستون'),()=>this.align('x')),button(t('Clear selection','پاک کردن انتخاب'),()=>this.selection.clear())));
    if(this.config.canBlockSeats)this.inspector.append(el('div',{class:'pesm-v-toolbar'},button(t('Block selected place','مسدود کردن جایگاه انتخابی'),()=>this.blockSelected(true)),button(t('Unblock selected place','رفع مسدودی جایگاه انتخابی'),()=>this.blockSelected(false))));
    const objectType=el('select',{},...['stage','runway','field','round_table','rect_table','aisle','door','emergency_exit','label','separator','accessible_area'].map(type=>option(type,({stage:t('Stage','صحنه'),runway:t('Runway','مسیر نمایش'),field:t('Playing field','زمین بازی'),round_table:t('Round table','میز گرد'),rect_table:t('Rectangular table','میز مستطیل'),aisle:t('Aisle','راهرو'),door:t('Door','در'),emergency_exit:t('Emergency exit','خروج اضطراری'),label:t('Label','برچسب'),separator:t('Separator','جداکننده'),accessible_area:t('Accessible area','محدوده دسترس‌پذیر')})[type])));
    const objectLabel=el('input',{type:'text',maxLength:120}),objectWidth=number('width',3000,1,1000000),objectHeight=number('height',1200,1,1000000);
    this.inspector.append(el('details',{},el('summary',{text:t('Add venue object','افزودن عنصر سالن')}),field(t('Object type','نوع عنصر'),objectType),field(t('Label','عنوان'),objectLabel),field(t('Width (mm)','عرض (میلی‌متر)'),objectWidth),field(t('Height (mm)','ارتفاع (میلی‌متر)'),objectHeight),button(t('Add object at X/Y','افزودن عنصر در مختصات'),()=>{
      const object={id:`OBJ-${requestKey().slice(0,16)}`,type:objectType.value,label:objectLabel.value,x:Number(this.editX.value),y:Number(this.editY.value),width:Number(objectWidth.value),height:Number(objectHeight.value),section_code:this.sectionCode};
      this.saveCommands([{op:'object',object}],[{op:'object',object:{id:object.id},delete:true}]);
    })));
    this.workspace.append(this.inspector);this.mountSessionSettings();
  }
  inspect(unit){this.inspected=unit;this.inspectorTitle.textContent=`${this.t('Selection','انتخاب')} · ${this.selection.selected.size} · ${unit.code||''}`;this.editX.value=String(unit.x);this.editY.value=String(unit.y);this.editCategory.value=unit.category_code||'';this.editAccessible.checked=Boolean(unit.accessible);this.editGroupMode.value=unit.sale_mode||'both';}
  selectedSeats(){return[...this.selection.selected.values()].filter(unit=>unit.type==='seat');}
  async applyProperties(){
    const seats=this.selectedSeats(),groups=[...this.selection.selected.values()].filter(unit=>unit.type!=='seat');
    const operations=[],inverse=[],dx=Number(this.editX.value)-Number(this.inspected?.x||0),dy=Number(this.editY.value)-Number(this.inspected?.y||0);
    for(const seat of seats){operations.push({op:'move',seat_ids:[seat.id],x:seat.x+dx,y:seat.y+dy},{op:'category',seat_ids:[seat.id],category_code:this.editCategory.value},{op:'accessibility',seat_ids:[seat.id],accessible:this.editAccessible.checked});inverse.push({op:'move',seat_ids:[seat.id],x:seat.x,y:seat.y},{op:'category',seat_ids:[seat.id],category_code:seat.category_code},{op:'accessibility',seat_ids:[seat.id],accessible:Boolean(seat.accessible)});}
    for(const group of groups){operations.push({op:'move_group',group_code:group.id,dx,dy},{op:'group_category',group_code:group.id,category_code:this.editCategory.value},{op:'group_mode',group_code:group.id,sale_mode:this.editGroupMode.value});inverse.push({op:'move_group',group_code:group.id,dx:-dx,dy:-dy},{op:'group_category',group_code:group.id,category_code:group.category_code},{op:'group_mode',group_code:group.id,sale_mode:group.sale_mode||'both'});}
    if(operations.length>500||seats.length+groups.reduce((n,g)=>n+Number(g.capacity||1),0)>5000){this.message(this.t('Apply properties to a smaller selection.','ویژگی‌ها را برای تعداد کمتری از جایگاه‌ها ثبت کنید.'),true);return;}await this.saveCommands(operations,inverse);
  }
  async moveSelection(delta){
    const seats=this.selectedSeats(),groups=[...this.selection.selected.values()].filter(unit=>unit.type!=='seat');if(!seats.length&&!groups.length)return;
    const dx=Math.round(delta.x),dy=Math.round(delta.y),operations=[],inverse=[];
    if(seats.length){operations.push({op:'move',seat_ids:seats.map(s=>s.id),dx,dy});inverse.push({op:'move',seat_ids:seats.map(s=>s.id),dx:-dx,dy:-dy});}
    for(const group of groups){operations.push({op:'move_group',group_code:group.id,dx,dy});inverse.push({op:'move_group',group_code:group.id,dx:-dx,dy:-dy});}
    await this.saveCommands(operations,inverse);
  }
  async blockSelected(blocked){const seats=this.selectedSeats();if(!this.sessionId||seats.length!==1){this.message(this.t('Choose a session and exactly one place.','یک جلسه و دقیقاً یک جایگاه انتخاب کنید.'),true);return;}await this.perform(()=>this.api.request(`admin/sessions/${this.sessionId}/block`,{method:'POST',body:{seat_id:seats[0].id,blocked,request_key:requestKey()}}),blocked?this.t('Place blocked.','جایگاه مسدود شد.'):this.t('Place unblocked.','مسدودی جایگاه رفع شد.'));}
  async align(axis){const seats=this.selectedSeats();if(seats.length<2)return;const operations=seats.map(s=>({op:'move',seat_ids:[s.id],x:axis==='x'?seats[0].x:s.x,y:axis==='y'?seats[0].y:s.y}));if(operations.length>500){this.message(this.t('Align at most 500 places at once.','حداکثر ۵۰۰ جایگاه را هم‌زمان هم‌تراز کنید.'),true);return;}await this.saveCommands(operations,seats.map(s=>({op:'move',seat_ids:[s.id],x:s.x,y:s.y})));}
  async saveCommands(operations,inverse=[],record=true){
    if(!operations.length||!this.layoutId||this.sessionConfigPending)return;
    const signature=JSON.stringify(operations);
    if(this.pendingCommand&&this.pendingCommand.signature!==signature){this.message(this.t('Retry the pending save or reload the saved draft before making another change.','پیش از تغییر بعدی، ذخیره معلق را دوباره ارسال یا پیش‌نویس ذخیره‌شده را بارگذاری کنید.'),true);return;}
    if(!this.pendingCommand)this.pendingCommand={signature,operations:structuredClone(operations),inverse:structuredClone(inverse),record,revision:this.revision,key:requestKey()};
    const pending=this.pendingCommand;this.retrySaveButton.hidden=false;
    const result=await this.perform(async()=>{
      this.saveState.textContent=this.t('Saving…','در حال ذخیره…');
      const data=await this.api.request(`admin/layouts/${this.layoutId}/commands`,{method:'PATCH',body:{revision:pending.revision,request_key:pending.key,operations:pending.operations}});
      this.revision=Number(data.revision);this.pendingCommand=null;this.retrySaveButton.hidden=true;
      if(pending.record){this.undo.push({operations:pending.operations,inverse:pending.inverse});this.redo=[];}
      else if(this.pendingHistory){const history=this.pendingHistory;history.source.pop();history.destination.push(history.entry);this.pendingHistory=null;}
      this.saveState.textContent=this.t('Saved','ذخیره شد');this.cache.clear();if(this.tileCode)await this.openTile(this.tileCode,true);
      if(this.tile)for(const[key,unit]of this.selection.selected){const updated=this.tile.seats.find(s=>unit.type==='seat'&&s.id===unit.id)||this.tile.groups.find(g=>unit.type!=='seat'&&g.id===unit.id);if(updated)this.selection.selected.set(key,updated);}
      return data;
    });
    if(!result&&this.pendingCommand)this.saveState.textContent=this.t('Not confirmed — retry the same save.','ذخیره تأیید نشد — همان درخواست را دوباره ارسال کنید.');return result;
  }
  async history(kind){const source=kind==='undo'?this.undo:this.redo,destination=kind==='undo'?this.redo:this.undo;const entry=source[source.length-1];if(!entry)return;this.pendingHistory={source,destination,entry};await this.saveCommands(kind==='undo'?entry.inverse:entry.operations,[],false);}
  mountSessionSettings(){
    const t=this.t;this.sessionSettings=el('details',{class:'pesm-v-session-settings'},el('summary',{text:t('Session pricing, group sales & admission','قیمت، فروش گروهی و ورود جلسه')}));
    this.sessionSelect=el('select',{onchange:()=>{if(this.selection.busy||this.pendingCommand||this.currentJob&&this.currentJob!==this.failedJob){this.sessionSelect.value=String(this.sessionId||'');return;}this.sessionId=Number(this.sessionSelect.value);this.loadSessionSettings();}},option('',t('Choose session','جلسه را انتخاب کنید')),...(this.config.sessions||[]).map(s=>option(s.id,s.title||s.label||`${t('Session','جلسه')} ${s.id}`)));
    if(this.sessionId&&!this.sessionSelect.querySelector(`option[value="${this.sessionId}"]`))this.sessionSelect.append(option(this.sessionId,`${t('Session','جلسه')} ${this.sessionId}`));this.sessionSelect.value=String(this.sessionId||'');
    this.ticketMode=el('select',{},option('individual',t('One ticket per guest','یک بلیت برای هر مهمان')),option('group',t('Shared group QR','کد گروهی مشترک')),option('buyer_choice',t('Buyer chooses','انتخاب خریدار')));
    this.entryMode=el('select',{},option('together',t('Party enters together','ورود یک‌جای گروه')),option('incremental',t('Count separate arrivals','ورود تدریجی با شمارش')));
    this.pricingFields=el('div',{class:'pesm-v-pricing-fields'});
    this.sessionSaveButton=button(t('Save session rules','ذخیره قواعد جلسه'),()=>this.saveSessionSettings(),true);this.sessionPublishButton=button(t('Publish session for sales','انتشار جلسه برای فروش'),()=>this.publishSession(),true);
    this.sessionSettings.append(field(t('Session','جلسه'),this.sessionSelect),el('div',{class:'pesm-v-form-grid'},field(t('Group ticket policy','روش صدور بلیت گروهی'),this.ticketMode),field(t('Shared QR entry','روش ورود با کد گروهی'),this.entryMode)),this.pricingFields,el('div',{class:'pesm-v-toolbar'},this.sessionSaveButton,this.sessionPublishButton));
    this.root.append(this.sessionSettings);
    if(this.config.canManageSessions===false){this.ticketMode.closest('.pesm-v-form-grid').hidden=true;for(const action of this.sessionSettings.querySelectorAll('.pesm-v-toolbar button'))action.hidden=true;}
    if(this.config.canManagePricing===false)this.pricingFields.hidden=true;
    this.mountSessionCreation();if(this.config.canManageRefunds)this.mountAdministration();this.setSessionSettingsPending(Boolean(this.sessionId));if(this.sessionId)this.loadSessionSettings();
  }
  setSessionSettingsPending(pending){
    this.sessionConfigPending=Boolean(pending);const disabled=this.sessionConfigPending||!this.sessionId||this.sessionConfigId!==this.sessionId;
    for(const input of [this.ticketMode,this.entryMode,this.sessionSaveButton,this.sessionPublishButton,...(this.pricingFields?.querySelectorAll('input,select')||[])])if(input)input.disabled=disabled;
    if(this.sessionSettings)this.sessionSettings.setAttribute('aria-busy',String(this.sessionConfigPending));this.renderSelection();
  }
  async loadSessionSettings(){
    const id=this.sessionId,request=this.sessionConfigRequest=(this.sessionConfigRequest||0)+1;
    const current=()=>id===this.sessionId&&request===this.sessionConfigRequest;
    this.overviewRequest=(this.overviewRequest||0)+1;this.listRequest=(this.listRequest||0)+1;this.scopeVersion++;this.adminRequest=(this.adminRequest||0)+1;
    this.sessionConfig=null;this.sessionConfigId=0;this.selection.clear();this.undo=[];this.redo=[];this.adminLines?.replaceChildren();this.setSessionSettingsPending(Boolean(id));
    if(!id)return;
    try{
      const metadata=(this.config.sessions||[]).find(session=>Number(session.id)===id);
      const data=this.config.canManageSessions===false?metadata||{}:await this.api.request(`admin/sessions/${id}/config`);
      if(!current())return;
      this.sessionConfig=data.settings||data.config||{};this.sessionConfigId=id;
      const hallId=Number(data.hall_id||metadata?.hall_id)||Number(this.config.hallId),hall=(this.config.halls||[]).find(item=>Number(item.id)===hallId);
      this.config.hallId=hallId;this.config.centerId=Number(data.center_id||hall?.center_id)||0;
      if(this.hallSelect){if(!this.hallSelect.querySelector(`option[value="${hallId}"]`))this.hallSelect.append(option(hallId,hall?.name||`${this.t('Hall','سالن')} ${hallId}`));this.hallSelect.value=String(hallId);}
      if(data.layout_id||metadata?.layout_id){this.layoutId=Number(data.layout_id||metadata.layout_id);await this.loadOverview({sessionId:id,sessionRequest:request});}
      if(!current())return;
      this.ticketMode.value=this.sessionConfig.ticket_mode||'individual';this.entryMode.value=this.sessionConfig.group_entry_policy||'together';this.loadPricingForm();this.syncDesignerURL();
      if(this.adminLines)this.loadAdministration();
    }catch(error){if(current()){this.sessionConfigId=0;this.message(error.message,true);}}
    finally{if(current())this.setSessionSettingsPending(false);}
  }
  mountAdministration(){
    const t=this.t;this.administration=el('details',{class:'pesm-v-administration'},el('summary',{text:t('Bookings, cancellations & group moves','رزروها، لغو و جابه‌جایی گروهی')}));
    this.adminLines=el('div');this.adminMore=button(t('Next 50 bookings','۵۰ رزرو بعدی'),()=>this.loadAdministration(this.adminCursor));
    this.administration.append(el('p',{class:'pesm-v-help',text:t('A table or box stays indivisible. A move requires the whole group to be unused, with the same capacity and total price.','میز یا باکس به‌صورت یکپارچه باقی می‌ماند. جابه‌جایی فقط برای گروه استفاده‌نشده با ظرفیت و مبلغ یکسان ممکن است.')}),button(t('Refresh bookings','تازه‌سازی رزروها'),()=>this.loadAdministration()),this.adminLines,this.adminMore);this.root.append(this.administration);
  }
  statusLabel(status){return({issued:this.t('Issued','صادرشده'),active:this.t('Active','فعال'),checked_in:this.t('Entered','واردشده'),refund_review:this.t('Refund review','بررسی استرداد'),refunded:this.t('Refunded','مستردشده'),cancelled:this.t('Cancelled','لغوشده'),held:this.t('Held','رزرو موقت'),payment_pending:this.t('Awaiting payment','در انتظار پرداخت'),pending:this.t('Pending','در انتظار')})[status]||status;}
  async loadAdministration(cursor=0){
    if(!this.sessionId||!this.adminLines)return;
    const sessionId=this.sessionId,request=this.adminRequest=(this.adminRequest||0)+1;
    try{const data=await this.api.request(`admin/sessions/${sessionId}/lines?limit=50&cursor=${encodeURIComponent(cursor||0)}`);if(sessionId!==this.sessionId||request!==this.adminRequest)return;this.adminCursor=data.next_cursor;this.adminMore.disabled=!this.adminCursor;this.adminLines.replaceChildren();
      for(const line of data.items||[]){
        const card=el('article',{class:'pesm-v-admin-line'},el('h4',{text:`${line.code||line.unit_id} · ${this.t('Order','سفارش')} #${line.order_id}`}),el('p',{text:`${this.statusLabel(line.status)} · ${line.admissions||line.capacity} ${this.t('guests','نفر')} · ${this.t('Entered','واردشده')}: ${line.used} · ${money(line.total_minor,line.price_decimals,line.currency,this.fa?'fa':'en')}`}));
        const actions=el('div',{class:'pesm-v-toolbar'}),unused=Number(line.used)===0;
        const cancel=button(Number(line.total_minor)>0?this.t('Request full refund','درخواست استرداد کامل'):this.t('Cancel whole booking','لغو کامل رزرو'),()=>this.adminAction(line,'cancel'));cancel.disabled=!unused||!['issued','refund_review'].includes(line.status);actions.append(cancel);
        if(line.unit_type!=='seat'){const move=button(this.t('Move to selected whole group','انتقال به گروه کامل انتخاب‌شده'),()=>{const units=this.selection.payload();if(units.length!==1||units[0].type!=='group'){this.message(this.t('Select one destination table or box on the map or list first.','ابتدا یک میز یا باکس مقصد را روی نقشه یا فهرست انتخاب کنید.'),true);return;}this.adminAction(line,'reallocate',{units});});move.disabled=!unused||line.status!=='issued';actions.append(move);}
        if(line.status==='refund_review'){const refundId=number('refund_id','',1,Number.MAX_SAFE_INTEGER);actions.append(field(this.t('WooCommerce refund number','شماره استرداد ووکامرس'),refundId),button(this.t('Verify refund and release group','تأیید استرداد و آزادسازی گروه'),()=>{if(refundId.reportValidity())this.adminAction(line,'confirm-refund',{refund_id:Number(refundId.value)});}),button(this.t('Deny refund request','رد درخواست استرداد'),()=>this.adminAction(line,'deny-refund')));card.append(el('p',{class:'pesm-v-help',text:this.t('Create the full line refund in WooCommerce first. Inventory is released only after its amount and line are verified.','ابتدا استرداد کامل قلم سفارش را در ووکامرس انجام دهید. موجودی فقط پس از تأیید مبلغ و قلم استرداد آزاد می‌شود.')}));}
        card.append(actions);this.adminLines.append(card);
      }
      if(!data.items?.length)this.adminLines.append(el('p',{text:this.t('No bookings on this page.','رزروی در این صفحه وجود ندارد.')}));
    }catch(error){this.message(error.message,true);}
  }
  async adminAction(line,action,body={}){const signature=JSON.stringify([line.id,action,body]);if(!this.pendingAdmin||this.pendingAdmin.signature!==signature)this.pendingAdmin={signature,key:requestKey()};await this.perform(async()=>{const data=await this.api.request(`admin/lines/${line.id}/${action}`,{method:'POST',body:{...body,request_key:this.pendingAdmin.key}});this.pendingAdmin=null;await this.loadAdministration();if(data.qr_version)this.message(this.t('Booking moved. Previous QR codes are no longer valid; the customer must retrieve the updated tickets.','رزرو جابه‌جا شد. کدهای قبلی دیگر معتبر نیستند؛ مشتری باید بلیت‌های جدید را دریافت کند.'));else this.message(this.statusLabel(data.status||'issued'));});}
  loadPricingForm(){if(!this.pricingFields||!this.overview)return;this.pricingFields.replaceChildren();this.priceInputs=[];this.groupModeInputs=[];const pricing=this.sessionConfig?.pricing||{};
    const add=(scope,key,label,value)=>{const input=number(key,value??'',0,1000000000);input.required=false;input.placeholder=this.t('Use default','قیمت پیش‌فرض');this.priceInputs.push({scope,key,input});return field(label,input);};
    const sections=el('fieldset',{},el('legend',{text:this.t('Section prices (minor currency units)','قیمت بخش‌ها (کوچک‌ترین واحد پول)')}));for(const section of this.overview.sections||[])sections.append(add('sections',section.code,section.label||section.code,pricing.sections?.[section.code]));
    const categories=el('fieldset',{},el('legend',{text:this.t('Category prices','قیمت دسته‌ها')}));for(const category of this.overview.categories||[])categories.append(add('categories',category.code,category.label||category.code,pricing.categories?.[category.code]));
    const overrides=el('fieldset',{},el('legend',{text:this.t('Current section/category overrides','قیمت اختصاصی دسته در بخش فعلی')}));if(this.sectionCode)for(const category of this.overview.categories||[])overrides.append(add('section_categories',`${this.sectionCode}:${category.code}`,`${this.sectionCode} / ${category.label||category.code}`,pricing.section_categories?.[this.sectionCode]?.[category.code]));
    const groups=el('fieldset',{},el('legend',{text:this.t('Whole-unit prices in current area','قیمت گروه کامل در ناحیه فعلی')}));for(const group of this.tile?.groups||[]){groups.append(add('groups',group.code,`${group.code} · ${group.capacity}`,pricing.groups?.[group.code]));const mode=el('select',{},option('both',this.t('Individual or whole','تکی یا کامل')),option('individual',this.t('Individual only','فقط تکی')),option('whole',this.t('Whole group only','فقط گروه کامل')));mode.value=this.sessionConfig?.group_modes?.[group.code]||group.sale_mode||'both';this.groupModeInputs.push({code:group.code,input:mode});groups.append(field(`${group.code} · ${this.t('Sale mode','روش فروش')}`,mode));}
    this.pricingFields.append(el('p',{class:'pesm-v-help',text:this.t('Leave blank to inherit. Priority: section/category, category, section, session. Whole groups inherit the sum of place prices unless overridden.','برای ارث‌بری خالی بگذارید. اولویت قیمت: دسته در بخش، دسته، بخش، جلسه. قیمت گروه کامل در حالت پیش‌فرض مجموع جایگاه‌ها است.')}),sections,categories,overrides,groups);
    this.setSessionSettingsPending(this.sessionConfigPending);
  }
  async saveSessionSettings(){if(!this.sessionId||this.sessionConfigPending||this.sessionConfigId!==this.sessionId)return;const id=this.sessionId,request=this.sessionConfigRequest,settings=structuredClone(this.sessionConfig||{});settings.pricing||={};for(const scope of ['sections','categories','section_categories','groups'])settings.pricing[scope]||={};for(const{scope,key,input}of this.priceInputs||[]){if(!input.reportValidity())return;let container=settings.pricing[scope],fieldKey=key;if(scope==='section_categories'){const[section,category]=key.split(':');container[section]||={};container=container[section];fieldKey=category;}if(input.value==='')delete container[fieldKey];else container[fieldKey]=Number(input.value);}settings.group_modes||={};for(const{code,input}of this.groupModeInputs||[])settings.group_modes[code]=input.value;settings.ticket_mode=this.ticketMode.value;settings.group_entry_policy=this.entryMode.value;if(this.config.canManagePricing===false)delete settings.pricing;await this.perform(async()=>{const data=await this.api.request(`admin/sessions/${id}/config`,{method:'PATCH',body:{settings,request_key:requestKey()}});if(id===this.sessionId&&request===this.sessionConfigRequest)this.sessionConfig=data.settings||data.config||settings;},this.t('Session rules saved. Existing holds keep their accepted price.','قواعد جلسه ذخیره شد. قیمت پذیرفته‌شده رزروهای موجود حفظ می‌شود.'));}
  async publishSession(){if(!this.sessionId||this.sessionConfigPending||this.sessionConfigId!==this.sessionId)return;const id=this.sessionId;await this.perform(()=>this.api.request(`admin/sessions/${id}/publish`,{method:'POST',body:{request_key:requestKey()}}),this.t('Session published for sales.','جلسه برای فروش منتشر شد.'));}
  mountSessionCreation(){if(this.config.canManageSessions===false)return;const t=this.t;const form=el('form',{class:'pesm-v-session-create'});const eventSelect=el('select',{name:'event_id',required:true},option('',t('Choose an event','رویداد را انتخاب کنید')),...(this.config.events||[]).map(e=>option(e.id,e.title)));const start=el('input',{type:'datetime-local',name:'starts_at',required:true}),saleStart=el('input',{type:'datetime-local',name:'sale_starts_at',required:true}),saleEnd=el('input',{type:'datetime-local',name:'sale_ends_at',required:true}),price=number('unit_price_minor',0,0,1000000000);const submit=el('button',{type:'submit',class:'pesm-v-button is-primary',text:t('Create allocated session','ایجاد جلسه با چیدمان جدید'),disabled:!this.config.creationEnabled});form.append(el('h3',{text:t('Create a session for this venue','ایجاد جلسه برای این سالن')}),el('div',{class:'pesm-v-form-grid'},field(t('Event','رویداد'),eventSelect),field(t('Starts (your local time)','شروع اجرا (زمان محلی شما)'),start),field(t('Sales open','شروع فروش'),saleStart),field(t('Sales close','پایان فروش'),saleEnd),field(t('Default price (minor units)','قیمت پایه (کوچک‌ترین واحد پول)'),price)),submit);if(!this.config.creationEnabled)form.append(el('p',{class:'pesm-v-help',text:t('New session creation opens after deployment acceptance passes. Layout design remains available.','پس از تأیید پذیرش محیط، ایجاد جلسه جدید فعال می‌شود. طراحی چیدمان در دسترس است.')}));form.addEventListener('submit',event=>{event.preventDefault();if(!form.reportValidity()||!this.config.creationEnabled)return;const toUTC=input=>new Date(input.value).toISOString().slice(0,19).replace('T',' ');this.perform(async()=>{const data=await this.api.request('admin/sessions',{method:'POST',body:{event_id:Number(eventSelect.value),hall_id:Number(this.hallSelect.value),starts_at:toUTC(start),sale_starts_at:toUTC(saleStart),sale_ends_at:toUTC(saleEnd),unit_price_minor:Number(price.value),settings:{ticket_mode:this.ticketMode.value,group_entry_policy:this.entryMode.value}}});if(data.job)await this.watchJob(data.job);this.sessionId=Number(data.session_id||data.id);this.sessionSelect.append(option(this.sessionId,`${t('Session','جلسه')} ${this.sessionId}`));this.sessionSelect.value=String(this.sessionId);await this.loadSessionSettings();},t('Session created. Inventory must finish provisioning before sales.','جلسه ایجاد شد. موجودی باید پیش از فروش به‌طور کامل آماده شود.'));});this.sessionSettings.append(form);}
  destroy() { this.active=false; this.abort.abort(); clearTimeout(this.pollTimer);clearTimeout(this.expiryTimer); clearInterval(this.tickTimer); this.unsubscribe?.(); this.renderer?.destroy(); this.preview?.dispose(); this.cache.clear(); this.thumbnailObserver?.disconnect(); }
}

const mounted=new WeakMap();
export function mount(root,config={}){
  if(mounted.has(root))return mounted.get(root);
  const app=new VenueApp(root,config);mounted.set(root,app);return app;
}
window.PESMVenue={mount};
const initialize=()=>{for(const root of document.querySelectorAll('.pesm-venue-app')){
  try{const config=JSON.parse(root.dataset.config||'{}');mount(root,config);}catch(error){root.textContent=String(error.message);root.setAttribute('role','alert');}
}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize,{once:true});else initialize();
