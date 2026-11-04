/* PESM editor: isolated draft autosave, optimistic revision and offline JSON fallback. */
(() => {
  'use strict';
  const panel = document.querySelector('.pesm-layout-editor');
  if (!panel) return;
  const svg = panel.querySelector('svg');
  const NS = 'http://www.w3.org/2000/svg';
  const status = panel.querySelector('.pesm-editor-status');
  const input = name => panel.querySelector(`[name="${name}"]`);
  let documentData = null;
  let history = [], redo = [], selected = new Set(), selectedObject = null, dragging = null, marquee = null, panning = null;
  let viewport = { x: 0, y: 0, w: 1000, h: 750 };
  let currentHall = 0, currentLayout = 0, revision = 0;
  let dirty = false, saving = false, conflicted = false, saveTimer = null, pendingRequestKey = null, pendingPayload = null;
  const configuration = typeof pesmEditorConfig === 'object' ? pesmEditorConfig : null;
  function randomKey() {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b=>b.toString(16).padStart(2,'0')).join('');
  }
  function queueSave() {
    dirty = true;
    if (saveTimer) clearTimeout(saveTimer);
    if (!conflicted) saveTimer = setTimeout(saveDraft, 1500);
    message(window.PESM_I18N.text("تغییرات ذخیره‌نشده؛ در انتظار ذخیره خودکار…"));
  }
  async function saveDraft() {
    clearTimeout(saveTimer); saveTimer = null;
    if (!dirty || !documentData || saving || conflicted) return;
    if (!configuration) return message(window.PESM_I18N.text("تنظیمات ذخیره خودکار در دسترس نیست؛ JSON را دانلود کنید."));
    saving = true;
    if (!pendingRequestKey) { pendingRequestKey = randomKey(); pendingPayload = JSON.stringify(documentData); }
    const json = pendingPayload;
    const body = new FormData();
    for (const [key, value] of Object.entries({
      action:'pesm_editor_save',nonce:configuration.nonce,hall_id:currentHall,
      layout_id:currentLayout,revision,request_key:pendingRequestKey,json
    })) body.set(key, String(value));
    message(window.PESM_I18N.text("در حال ذخیره نسخه پیش‌نویس روی سرور…"));
    try {
      const response = await fetch(configuration.ajaxUrl, {
        method:'POST',body,credentials:'same-origin',cache:'no-store',redirect:'error'
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        if (response.status === 409 || result?.data?.conflict) conflicted = true;
        throw new Error(result?.data?.message || window.PESM_I18N.text("ذخیره انجام نشد."));
      }
      const saved = result.data;
      if (!Number.isInteger(saved.layout_id) || !Number.isInteger(saved.revision)) throw new Error(window.PESM_I18N.text("پاسخ ذخیره معتبر نیست."));
      currentLayout = saved.layout_id;
      revision = saved.revision;
      pendingRequestKey = null; pendingPayload = null;
      dirty = JSON.stringify(documentData) !== json;
      message(dirty ? window.PESM_I18N.text("نسخه قبلی ذخیره شد؛ تغییرات جدید در صف ذخیره قرار گرفت.") :
        (window.PESM_I18N.text("پیش‌نویس شماره ") + String(currentLayout) + window.PESM_I18N.text("، بازبینی ") + String(revision) + window.PESM_I18N.text(" با موفقیت ذخیره شد. برای انتشار، صفحه مدیریت را بازخوانی کنید.")));
    } catch (error) {
      dirty = true;
      message((error?.message || window.PESM_I18N.text("ارتباط ذخیره قطع شد.")) + window.PESM_I18N.text(" برای جلوگیری از فقدان داده، فایل JSON را نیز دریافت کنید."));
    } finally {
      saving = false;
      if (dirty && !conflicted && !pendingRequestKey) saveTimer = setTimeout(saveDraft, 1500);
    }
  }
  panel.querySelector('.pesm-save').addEventListener('click', () => saveDraft());
  window.addEventListener('beforeunload', event => {
    if (!dirty && !saving) return;
    event.preventDefault(); event.returnValue = '';
  });
  const message = text => { status.textContent = text; };
  const snapshot = () => JSON.stringify({seats:documentData.seats,objects:documentData.objects || []});
  const restore = json => {const state=JSON.parse(json); documentData.seats=state.seats;documentData.objects=state.objects;};
  function remember() {
    history.push(snapshot());
    if (history.length > 50) history.shift();
    redo = [];
  }
  const seatKey = s => s.code;
  function screenPoint(event) {
    const p = svg.createSVGPoint();
    p.x = event.clientX; p.y = event.clientY;
    const matrix = svg.getScreenCTM();
    if (!matrix) return {x:0, y:0};
    const v = p.matrixTransform(matrix.inverse());
    return {x:v.x, y:v.y};
  }
  function view() {
    svg.setAttribute('viewBox', `${viewport.x} ${viewport.y} ${viewport.w} ${viewport.h}`);
  }
  function autoView() {
    const seats = documentData?.seats || [];
    if (!seats.length) return;
    const objects = documentData?.objects || [];
    const xs = [...seats.map(s => s.x),...objects.map(o => o.x),...objects.map(o => o.x + o.width)];
    const ys = [...seats.map(s => s.y),...objects.map(o => o.y),...objects.map(o => o.y + o.height)];
    const left = Math.min(...xs)-40, top=Math.min(...ys)-40;
    const width = Math.max(240, Math.max(...xs)-left+40);
    const height = Math.max(180, Math.max(...ys)-top+40);
    viewport = {x:left,y:top,w:width,h:height};
    view();
  }
  function draw() {
    if (!documentData) return;
    const seats = documentData.seats;
    const frag = document.createDocumentFragment();
    for (const o of documentData.objects || []) {
      const isRound = o.type === 'round_table', isLine = o.type === 'separator';
      const shape = document.createElementNS(NS, isRound ? 'ellipse' : (isLine ? 'line' : 'rect'));
      if (isRound) {
        shape.setAttribute('cx',o.x+o.width/2);shape.setAttribute('cy',o.y+o.height/2);
        shape.setAttribute('rx',o.width/2);shape.setAttribute('ry',o.height/2);
      } else if (isLine) {
        shape.setAttribute('x1',o.x);shape.setAttribute('y1',o.y);
        shape.setAttribute('x2',o.x+o.width);shape.setAttribute('y2',o.y+o.height);
      } else {
        shape.setAttribute('x',o.x);shape.setAttribute('y',o.y);
        shape.setAttribute('width',o.width);shape.setAttribute('height',o.height);
      }
      shape.setAttribute('class',`pesm-editor-object${o.id===selectedObject?' is-selected':''}`);
      shape.setAttribute('data-object-id',o.id);
      shape.setAttribute('tabindex','0');shape.setAttribute('role','button');
      shape.setAttribute('aria-label',(window.PESM_I18N.text("شیء ") + String(o.label||o.type)));
      frag.appendChild(shape);
      if (o.label) {
        const label = document.createElementNS(NS,'text');
        label.setAttribute('x',o.x+4);label.setAttribute('y',o.y+15);
        label.setAttribute('class','pesm-editor-object-label');label.textContent=o.label;
        frag.appendChild(label);
      }
    }
    const activeObject = (documentData.objects||[]).find(o=>o.id===selectedObject);
    if (activeObject) {
      panel.querySelector('.pesm-object-type').value=activeObject.type;
      panel.querySelector('.pesm-object-label').value=activeObject.label;
      panel.querySelector('.pesm-object-width').value=activeObject.width;
      panel.querySelector('.pesm-object-height').value=activeObject.height;
    }
    panel.querySelector('.pesm-object-count').textContent = (window.PESM_I18N.text("اشیای غیرصندلی: ") + String((documentData.objects||[]).length));
    for (let i=0; i<seats.length; i++) {
      const s = seats[i];
      const shape = document.createElementNS(NS, 'circle');
      shape.setAttribute('cx', s.x); shape.setAttribute('cy', s.y);
      shape.setAttribute('r', '11'); shape.setAttribute('tabindex', '0');
      shape.setAttribute('role', 'button');
      shape.setAttribute('aria-label', (window.PESM_I18N.text("صندلی ") + String(s.code) + window.PESM_I18N.text("، ") + String(s.zone)));
      shape.setAttribute('data-index', i);
      shape.setAttribute('class', selected.has(seatKey(s)) ? 'pesm-editor-seat is-selected' : 'pesm-editor-seat');
      frag.appendChild(shape);
    }
    svg.replaceChildren(frag);
    panel.querySelector('.pesm-selected-total').textContent = String(selected.size);
    const one = seats.find(s => selected.has(s.code));
    if (one) {
      for (const key of ['code','row_label','number','zone','x','y']) input(key).value = String(one[key]);
    }
    if (!dirty && !saving) message((window.PESM_I18N.text("نقشه باز شده: ") + String(seats.length) + window.PESM_I18N.text(" صندلی. با ویرایش، پیش‌نویس به‌طور خودکار ذخیره می‌شود.")));
  }
  async function open(button) {
    if (dirty || saving) return message(window.PESM_I18N.text("ابتدا تغییرات فعلی را ذخیره کنید یا JSON را دریافت و سپس صفحه را بازخوانی کنید."));
    message(window.PESM_I18N.text("در حال دریافت داده‌های نقشه…"));
    try {
      const response = await fetch(button.dataset.url, {credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw new Error(window.PESM_I18N.text("دریافت مجاز نبود یا نسخه پیدا نشد."));
      const raw = await response.text();
      if (raw.length > 2097152) throw new Error(window.PESM_I18N.text("حجم فایل بیش از حد مجاز است."));
      const data = JSON.parse(raw);
      if (data.format !== 'pesm.layout.v1' || !Array.isArray(data.seats) || !data.seats.length || data.seats.length > 10000)
        throw new Error(window.PESM_I18N.text("قالب نقشه معتبر نیست."));
      documentData = {format:data.format,seats:data.seats,objects:Array.isArray(data.objects)?data.objects:[]};
      currentHall = Number(button.dataset.hall);
      currentLayout = Number(button.dataset.layout);
      revision = Number(button.dataset.revision || 0);
      dirty = false; saving = false; conflicted = false; pendingRequestKey = null; pendingPayload = null;
      selected = new Set(); selectedObject = null; history = []; redo = [];
      panel.hidden = false;
      autoView(); draw();
      panel.scrollIntoView({behavior:'smooth', block:'nearest'});
    } catch (error) { message(error.message || window.PESM_I18N.text("خطا در دریافت نقشه.")); panel.hidden = false; }
  }
  document.querySelectorAll('.pesm-edit-layout').forEach(button => button.addEventListener('click', () => open(button)));
  function mutate(fn) { if (!documentData) return; remember(); fn(); draw(); queueSave(); }
  panel.querySelector('.pesm-undo').addEventListener('click', () => {
    if (!history.length || !documentData) return;
    redo.push(snapshot()); restore(history.pop()); selected.clear(); selectedObject=null; draw(); queueSave();
  });
  panel.querySelector('.pesm-redo').addEventListener('click', () => {
    if (!redo.length || !documentData) return;
    history.push(snapshot()); restore(redo.pop()); selected.clear(); selectedObject=null; draw(); queueSave();
  });
  panel.querySelector('.pesm-reset-view').addEventListener('click', autoView);
  panel.querySelector('.pesm-zoom-in').addEventListener('click', () => {
    viewport.x += viewport.w*0.1; viewport.y += viewport.h*0.1;
    viewport.w *= 0.8; viewport.h *= 0.8; view();
  });
  panel.querySelector('.pesm-zoom-out').addEventListener('click', () => {
    viewport.x -= viewport.w*0.125; viewport.y -= viewport.h*0.125;
    viewport.w *= 1.25; viewport.h *= 1.25; view();
  });
  panel.querySelector('.pesm-apply').addEventListener('click', () => {
    if (!selected.size) return message(window.PESM_I18N.text("صندلی برای ویرایش انتخاب نشده است."));
    const codes = [...selected];
    const update = {
      code: input('code').value.trim().toUpperCase(), row_label: input('row_label').value.trim(),
      number:Number(input('number').value),zone:input('zone').value.trim(),
      x:Number(input('x').value),y:Number(input('y').value)
    };
    // Align client validation with the server-side LayoutDocument contract.
    if (!update.zone || /[\x00-\x1f\x7f]/.test(update.zone) ||
        new TextEncoder().encode(update.zone).length > 180 ||
        (codes.length === 1 && (!/^[A-Z0-9][A-Z0-9._-]{0,59}$/.test(update.code) ||
        !/^[A-Za-z0-9_-]{1,20}$/.test(update.row_label) ||
        !Number.isInteger(update.number) || update.number < 1 || update.number > 10000 ||
        !Number.isInteger(update.x) || Math.abs(update.x) > 1000000 ||
        !Number.isInteger(update.y) || Math.abs(update.y) > 1000000))) {
      return message(window.PESM_I18N.text("مشخصات صندلی مطابق قواعد ذخیره‌سازی معتبر نیست."));
    }
    // Change code/number/position only when one seat is selected, but zone for any selection.
    if (codes.length === 1 && documentData.seats.some(s => s.code===update.code && s.code!==codes[0]))
      return message(window.PESM_I18N.text("کد صندلی تکراری است."));
    mutate(() => {
      for (const s of documentData.seats) {
        if (!selected.has(s.code)) continue;
        s.zone=update.zone;
        if (codes.length===1) Object.assign(s,update);
      }
      selected = new Set(codes.length === 1 ? [update.code] : codes);
    });
  });
  // Decorative geometry is never part of the seat selection or inventory.
  const objectKinds=['stage','round_table','rect_table','aisle','door','emergency_exit','label','separator','accessible_area'];
  panel.querySelector('.pesm-object-add').addEventListener('click', () => {
    if (!documentData) return;
    const type=panel.querySelector('.pesm-object-type').value;
    const label=panel.querySelector('.pesm-object-label').value.trim();
    const width=Number(panel.querySelector('.pesm-object-width').value);
    const height=Number(panel.querySelector('.pesm-object-height').value);
    if ((documentData.objects||[]).length >=500 || !objectKinds.includes(type) ||
        !Number.isInteger(width)||width<1||width>10000 || !Number.isInteger(height)||height<1||height>10000 ||
        new TextEncoder().encode(label).length>360 || /[\x00-\x1f\x7f]/.test(label) || [...label].length>120) {
      return message(window.PESM_I18N.text("نوع، ابعاد یا عنوان شیء معتبر نیست."));
    }
    const id=`O-${randomKey().slice(0,28)}`;
    mutate(()=>{
      documentData.objects.push({id,type,x:Math.round(viewport.x+viewport.w/3),
        y:Math.round(viewport.y+viewport.h/3),width,height,label});
      selectedObject=id;selected.clear();
    });
  });
  panel.querySelector('.pesm-object-apply').addEventListener('click',()=>{
    if (!documentData||!selectedObject) return message(window.PESM_I18N.text("برای ویرایش ابتدا شیء را انتخاب کنید."));
    const type=panel.querySelector('.pesm-object-type').value;
    const label=panel.querySelector('.pesm-object-label').value.trim();
    const width=Number(panel.querySelector('.pesm-object-width').value);
    const height=Number(panel.querySelector('.pesm-object-height').value);
    if (!objectKinds.includes(type) || !Number.isInteger(width)||width<1||width>10000 ||
        !Number.isInteger(height)||height<1||height>10000 ||
        new TextEncoder().encode(label).length>360 || [...label].length>120 || /[\x00-\x1f\x7f]/.test(label)) {
      return message(window.PESM_I18N.text("مشخصات شیء نامعتبر است."));
    }
    mutate(()=>{const obj=documentData.objects.find(o=>o.id===selectedObject);
      if (obj) Object.assign(obj,{type,label,width,height});
    });
  });
  panel.querySelector('.pesm-object-remove').addEventListener('click',()=>{
    if (!documentData||!selectedObject) return message(window.PESM_I18N.text("ابتدا شیء موردنظر را انتخاب کنید."));
    mutate(()=>{documentData.objects=documentData.objects.filter(o=>o.id!==selectedObject);selectedObject=null;});
  });
  panel.querySelector('.pesm-add-row').addEventListener('click', () => {
    if (!documentData) return;
    const name = input('new_row_name').value.trim().toUpperCase();
    const count = Number(input('new_row_count').value);
    if (!/^[A-Z0-9_-]{1,20}$/.test(name) || !Number.isInteger(count) || count < 1 || count > 100 ||
        count+documentData.seats.length>10000) return message(window.PESM_I18N.text("نام ردیف یا تعداد صندلی نامعتبر است."));
    const existing=new Set(documentData.seats.map(s=>s.code));
    const codes=Array.from({length:count},(_,i)=>`${name}-${i+1}`);
    if (codes.some(code=>existing.has(code))) return message(window.PESM_I18N.text("کد صندلی این ردیف از قبل وجود دارد."));
    const bottom = Math.max(0,...documentData.seats.map(s=>s.y))+36;
    const left = Math.min(20,...documentData.seats.map(s=>s.x));
    mutate(() => {
      codes.forEach((code,i)=>documentData.seats.push({
        code,row_label:name,number:i+1,zone:window.PESM_I18N.text("عمومی"),x:left+i*36,y:bottom
      }));
      selected=new Set(codes);
    });
    autoView();
  });
  panel.querySelector('.pesm-copy').addEventListener('click', () => {
    if (!documentData || !selected.size || selected.size+documentData.seats.length>10000) return;
    const existing=new Set(documentData.seats.map(s=>s.code));
    const items=[];
    for (const s of documentData.seats) {
      if (!selected.has(s.code)) continue;
      let counter=1, code;
      do {code=`COPY-${counter++}`;} while (existing.has(code));
      existing.add(code);
      items.push({...s,code,x:s.x+36,y:s.y+36});
    }
    mutate(() => {documentData.seats.push(...items); selected=new Set(items.map(s=>s.code));});
  });
  panel.querySelector('.pesm-add').addEventListener('click', () => {
    if (!documentData || documentData.seats.length>=10000) return message(window.PESM_I18N.text("حداکثر ۱۰۰۰۰ صندلی مجاز است."));
    let i=1;
    while (documentData.seats.some(s=>s.code===`NEW-${i}`)) i++;
    mutate(() => documentData.seats.push({code:`NEW-${i}`,row_label:'NEW',number:i,zone:window.PESM_I18N.text("عمومی"),x:0,y:0}));
    autoView();
  });
  panel.querySelector('.pesm-delete').addEventListener('click', () => {
    if (!selected.size || selected.size===documentData?.seats.length) return message(window.PESM_I18N.text("حذف همه صندلی‌ها مجاز نیست."));
    mutate(() => { documentData.seats = documentData.seats.filter(s=>!selected.has(s.code)); selected.clear(); });
  });
  svg.addEventListener('keydown', e => {
    if ((e.key==='Enter'||e.key===' ') && e.target.matches('[data-index], [data-object-id]')) {
      e.preventDefault(); e.target.dispatchEvent(new MouseEvent('click',{bubbles:true,ctrlKey:e.ctrlKey}));
    }
  });
  svg.addEventListener('click', e => {
    const index = e.target.getAttribute('data-index');
    const objectId=e.target.getAttribute('data-object-id');
    if (!documentData || svg.dataset.dragged === '1') return;
    if (objectId !== null) {
      selectedObject = selectedObject === objectId ? null : objectId;
      selected.clear();draw();return;
    }
    if (index === null) return;
    const s = documentData.seats[Number(index)];
    if (!e.ctrlKey && !e.metaKey && !e.shiftKey) selected.clear();
    if (selected.has(s.code)) selected.delete(s.code); else selected.add(s.code);
    draw();
  });
  svg.addEventListener('pointerdown', e => {
    if (!documentData || e.button!==0) return;
    const index = e.target.getAttribute('data-index');
    const objectId=e.target.getAttribute('data-object-id');
    if (objectId!==null) {
      selectedObject=objectId;selected.clear();
      dragging={start:screenPoint(e),original:snapshot(),moved:false,kind:'object',objectId};
      svg.setPointerCapture(e.pointerId);return;
    }
    if (index===null) {
      if (e.target !== svg) return;
      if (e.altKey) {
        panning={x:e.clientX,y:e.clientY,view:{...viewport}};
      } else {
        const point=screenPoint(e);
        marquee={start:point,current:point,add:e.shiftKey||e.ctrlKey};
      }
      svg.setPointerCapture(e.pointerId);
      return;
    }
    const item = documentData.seats[Number(index)];
    if (!selected.has(item.code)) {
      if (!e.ctrlKey && !e.shiftKey) selected.clear();
      selected.add(item.code);
    }
    selectedObject=null;
    dragging = {start:screenPoint(e),original:snapshot(),moved:false,kind:'seat'};
    svg.setPointerCapture(e.pointerId);
  });
  svg.addEventListener('pointermove', e => {
    if (panning) {
      const bounds=svg.getBoundingClientRect();
      viewport.x=panning.view.x-(e.clientX-panning.x)*panning.view.w/bounds.width;
      viewport.y=panning.view.y-(e.clientY-panning.y)*panning.view.h/bounds.height;
      view();return;
    }
    if (marquee) {
      marquee.current=screenPoint(e);
      const x=Math.min(marquee.start.x,marquee.current.x);
      const y=Math.min(marquee.start.y,marquee.current.y);
      const width=Math.abs(marquee.start.x-marquee.current.x);
      const height=Math.abs(marquee.start.y-marquee.current.y);
      let rectangle=svg.querySelector('.pesm-editor-marquee');
      if (!rectangle) {
        rectangle=document.createElementNS(NS,'rect');
        rectangle.setAttribute('class','pesm-editor-marquee');svg.appendChild(rectangle);
      }
      rectangle.setAttribute('x',x);rectangle.setAttribute('y',y);
      rectangle.setAttribute('width',width);rectangle.setAttribute('height',height);
      return;
    }
    if (!dragging) return;
    const delta = screenPoint(e);
    const dx = Math.round(delta.x-dragging.start.x),dy=Math.round(delta.y-dragging.start.y);
    if (Math.abs(dx)+Math.abs(dy)<2 && !dragging.moved) return;
    if (!dragging.moved) {
      history.push(dragging.original); if (history.length>50) history.shift(); redo=[]; dragging.moved=true;
    }
    const base = JSON.parse(dragging.original);
    documentData.seats = base.seats.map(s => dragging.kind==='seat' && selected.has(s.code) ? {...s,x:s.x+dx,y:s.y+dy} : s);
    documentData.objects = base.objects.map(o => dragging.kind==='object' && o.id===dragging.objectId ? {...o,x:o.x+dx,y:o.y+dy} : o);
    draw();
  });
  function finishDrag(e) {
    if (panning) {panning=null;return;}
    if (marquee) {
      const start=marquee.start, end=marquee.current;
      const minX=Math.min(start.x,end.x), maxX=Math.max(start.x,end.x);
      const minY=Math.min(start.y,end.y), maxY=Math.max(start.y,end.y);
      if (!marquee.add) selected.clear();
      for (const s of documentData.seats) {
        if (s.x>=minX && s.x<=maxX && s.y>=minY && s.y<=maxY) selected.add(s.code);
      }
      marquee=null;draw();return;
    }
    if (!dragging) return;
    if (dragging.moved) {
      queueSave();
      e.preventDefault(); // Prevent toggling selection after a drag.
      svg.dataset.dragged = '1';
      setTimeout(() => {delete svg.dataset.dragged;},0);
    }
    dragging=null;
  }
  svg.addEventListener('pointerup',finishDrag);
  svg.addEventListener('pointercancel',finishDrag);
  panel.querySelector('.pesm-download').addEventListener('click', () => {
    if (!documentData) return;
    const codes = new Set();
    const objectIds = new Set();
    if ((documentData.objects||[]).length>500) return message(window.PESM_I18N.text("تعداد اشیا بیش از ۵۰۰ است."));
    for (const o of documentData.objects||[]) {
      if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,59}$/.test(o.id) || objectIds.has(o.id) ||
          !objectKinds.includes(o.type) || !Number.isInteger(o.x) || Math.abs(o.x)>1000000 ||
          !Number.isInteger(o.y) || Math.abs(o.y)>1000000 ||
          !Number.isInteger(o.width) || o.width<1 || o.width>10000 ||
          !Number.isInteger(o.height) || o.height<1 || o.height>10000 ||
          typeof o.label!=='string' || new TextEncoder().encode(o.label).length>360 ||
          [...o.label].length>120 || /[\x00-\x1f\x7f]/.test(o.label)) return message(window.PESM_I18N.text("شیء نقشه نامعتبر است."));
      objectIds.add(o.id);
    }
    for (const s of documentData.seats) {
      if (!/^[A-Z0-9][A-Z0-9._-]{0,59}$/.test(s.code) ||
          !/^[A-Za-z0-9_-]{1,20}$/.test(s.row_label) ||
          !Number.isInteger(s.number) || s.number<1 || s.number>10000 ||
          !Number.isInteger(s.x) || !Number.isInteger(s.y) ||
          Math.abs(s.x)>1000000 || Math.abs(s.y)>1000000 ||
          !s.zone || /[\x00-\x1f\x7f]/.test(s.zone) ||
          new TextEncoder().encode(s.zone).length>180 || codes.has(s.code)) {
        return message((window.PESM_I18N.text("امکان خروجی: خطا یا کد تکراری در صندلی ") + String(s.code)));
      }
      codes.add(s.code);
    }
    const blob = new Blob([JSON.stringify(documentData,null,2)+'\n'],{type:'application/json;charset=utf-8'});
    if (blob.size>2097152) return message(window.PESM_I18N.text("حجم JSON از ۲ مگابایت بیشتر شده است."));
    const href = URL.createObjectURL(blob);
    const link = document.createElement('a'); link.href=href;
    link.download=`pesm-hall-${currentHall}-draft.json`;
    document.body.appendChild(link); link.click(); link.remove();
    setTimeout(()=>URL.revokeObjectURL(href),5000);
    message(window.PESM_I18N.text("فایل پشتیبان دریافت شد. می‌توانید آن را در فرم «واردکردن نسخه پیش‌نویس» نیز بارگذاری کنید."));
  });
})();
