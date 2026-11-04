import {boundsOf, nearest, unitKey} from './venue-core.mjs';

/** A bounded Canvas2D view; native controls/list remain the complete accessible interface. */
export class VenueCanvas {
  constructor(host, {controller, onSelect, onSection, onMove, label, isAvailable, onFocus}) {
    Object.assign(this, {host, controller, onSelect, onSection, onMove, label, isAvailable, onFocus});
    this.seats = []; this.groups = []; this.objects = []; this.sections = []; this.kind = 'overview';
    this.mode = 'seat'; this.scale = 1; this.offset = {x: 0, y: 0}; this.pendingFrame = null;
    this.canvas = document.createElement('canvas'); this.canvas.className = 'pesm-v-canvas'; this.canvas.setAttribute('aria-hidden', 'true');
    this.active = document.createElement('button'); this.active.type = 'button'; this.active.className = 'pesm-v-active-place';
    this.active.hidden = true; this.active.addEventListener('click', () => { if (this.focused) this.onSelect(this.focused); });
    this.active.addEventListener('keydown', event => {
      if (!this.focused) return;
      const next = nearest(this.pickables(), this.focused, event.key, this.isAvailable);
      if (next) { event.preventDefault(); this.focus(next); }
    });
    this.host.append(this.canvas, this.active); this.ctx = this.canvas.getContext('2d');
    this.unsubscribe = controller.subscribe(() => this.schedule());
    this.resize = new ResizeObserver(() => { const first = !this.width; this.width = Math.max(200, host.clientWidth); this.height = Math.max(320, Math.min(620, host.clientHeight || 480)); if (first) this.fit(); this.schedule(); });
    this.resize.observe(host);
    this.abort = new AbortController(); const signal = this.abort.signal;
    this.canvas.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      const item = this.hit(event.offsetX, event.offsetY);
      this.drag = {x: event.clientX, y: event.clientY, start: {...this.offset}, item, moved: false};
      this.canvas.setPointerCapture(event.pointerId);
    }, {signal});
    this.canvas.addEventListener('pointermove', event => {
      if (!this.drag) return;
      const dx = event.clientX - this.drag.x, dy = event.clientY - this.drag.y;
      if (Math.hypot(dx, dy) > 5) this.drag.moved = true;
      if (!this.drag.moved) return;
      if (this.onMove && this.drag.item && this.controller.has(this.drag.item) && this.kind === 'detail') {
        this.ghost = {x: dx / this.scale, y: dy / this.scale};
      } else this.offset = {x: this.drag.start.x + dx, y: this.drag.start.y + dy};
      this.schedule();
    }, {signal});
    this.canvas.addEventListener('pointerup', event => {
      if (!this.drag) return;
      const drag = this.drag; this.drag = null;
      if (this.ghost) { const delta = this.ghost; this.ghost = null; this.onMove?.(delta); }
      else if (!drag.moved) {
        const item = this.hit(event.offsetX, event.offsetY);
        if (item && this.kind === 'overview') this.onSection(item.code);
        else if (item) {
          this.focus(item);
          const coarse=globalThis.matchMedia?.('(pointer: coarse)').matches;
          const targetSize=(item.type==='seat'?380:Math.min(item.width||500,item.height||500))*this.scale;
          if(coarse&&targetSize<24)this.zoom(24/Math.max(targetSize,0.1),event.offsetX,event.offsetY);
          else this.onSelect(item);
        }
      }
      this.schedule();
    }, {signal});
    this.canvas.addEventListener('pointercancel', () => { this.drag = null; this.ghost = null; this.schedule(); }, {signal});
    this.canvas.addEventListener('wheel', event => {
      if (!event.ctrlKey) return; event.preventDefault(); this.zoom(event.deltaY < 0 ? 1.15 : 1 / 1.15, event.offsetX, event.offsetY);
    }, {signal, passive: false});
  }
  setOverview(sections, objects = []) {
    this.kind = 'overview'; this.sections = sections; this.objects = objects; this.seats = []; this.groups = [];
    this.focused = null; this.active.hidden = true; this.fit();
  }
  setDetail(seats, groups, objects,preserveView=false) {
    const focusedKey=this.focused?unitKey(this.focused):null;
    this.kind = 'detail'; this.seats = seats; this.groups = groups; this.objects = objects;
    this.index(); this.focused = null; this.active.hidden = true;
    const previous=focusedKey?[...seats,...groups].find(item=>unitKey(item)===focusedKey):null;if(previous)this.focus(previous);
    if(preserveView)this.schedule();else this.fit();
  }
  setMode(mode){this.mode=mode;this.focused=null;this.active.hidden=true;const first=this.pickables().find(this.isAvailable);if(first)this.focus(first);this.schedule();}
  index() {
    this.grid = new Map();
    for (const item of this.seats) {
      const key = `${Math.floor(item.x / 1200)}:${Math.floor(item.y / 1200)}`;
      if (!this.grid.has(key)) this.grid.set(key, []); this.grid.get(key).push(item);
    }
  }
  pickables() { return this.mode === 'seat' ? this.seats : this.groups.filter(g => this.mode === 'group' || g.type === this.mode); }
  hit(px, py) {
    const x = (px - this.offset.x) / this.scale, y = (py - this.offset.y) / this.scale;
    if (this.kind === 'overview') return [...this.sections].reverse().find(section => { const b = section.bounds; return b && x >= b.x && y >= b.y && x <= b.x+b.width && y <= b.y+b.height; });
    if (this.mode !== 'seat') return [...this.pickables()].reverse().find(g => x >= g.x && y >= g.y && x <= g.x+g.width && y <= g.y+g.height);
    let best = null, distance = Infinity;
    const gx = Math.floor(x / 1200), gy = Math.floor(y / 1200);
    for (let dx = -1; dx <= 1; dx++) for (let dy = -1; dy <= 1; dy++) for (const item of this.grid.get(`${gx+dx}:${gy+dy}`) || []) {
      const d = Math.hypot(x-item.x-200, y-item.y-200);
      if (d <= 340 && d < distance) { best = item; distance = d; }
    }
    return best;
  }
  fit() {
    const items = this.kind === 'overview' ? this.sections.map(s => s.bounds || {}) : [...this.seats, ...this.groups, ...this.objects];
    const b = boundsOf(items, this.kind === 'overview' ? 1500 : 700);
    this.width = Math.max(200, this.host.clientWidth); this.height = Math.max(320, Math.min(620, this.host.clientHeight || 480));
    this.scale = Math.min((this.width-32)/b.width, (this.height-32)/b.height);
    this.offset = {x: (this.width-b.width*this.scale)/2-b.x*this.scale, y: (this.height-b.height*this.scale)/2-b.y*this.scale}; this.schedule();
  }
  zoom(factor, x = this.width/2, y = this.height/2) {
    const next = Math.max(0.00005, Math.min(0.5, this.scale * factor));
    this.offset.x = x-(x-this.offset.x)*next/this.scale; this.offset.y = y-(y-this.offset.y)*next/this.scale;
    this.scale = next; this.schedule();
  }
  focus(item) { this.focused = item; this.active.hidden = false; this.active.textContent = this.label(item); this.active.setAttribute('aria-pressed', String(this.controller.has(item))); this.active.disabled = !this.isAvailable(item); this.onFocus?.(item); this.schedule(); }
  schedule() { if (this.pendingFrame === null) this.pendingFrame = requestAnimationFrame(() => { this.pendingFrame = null; this.draw(); }); }
  draw() {
    const ctx = this.ctx; if (!ctx || !this.width) return;
    if(this.kind==='detail'&&(!this.focused||!this.isAvailable(this.focused))){const first=this.pickables().find(item=>this.controller.has(item)&&this.isAvailable(item))||this.pickables().find(this.isAvailable);if(first)this.focus(first);}
    const ratio = Math.min(devicePixelRatio || 1, 1.5);
    if (this.canvas.width !== Math.round(this.width*ratio) || this.canvas.height !== Math.round(this.height*ratio)) {
      this.canvas.width = Math.round(this.width*ratio); this.canvas.height = Math.round(this.height*ratio);
      this.canvas.style.width = `${this.width}px`; this.canvas.style.height = `${this.height}px`;
    }
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0); ctx.clearRect(0, 0, this.width, this.height);
    ctx.fillStyle = '#f4f6fa'; ctx.fillRect(0, 0, this.width, this.height);
    ctx.translate(this.offset.x, this.offset.y); ctx.scale(this.scale, this.scale);
    const visible = item => item.x*this.scale+this.offset.x < this.width && item.y*this.scale+this.offset.y < this.height && (item.x+(item.width||500))*this.scale+this.offset.x > 0 && (item.y+(item.height||500))*this.scale+this.offset.y > 0;
    const rect = (item, fill, stroke = '#95a7be') => {
      ctx.save();ctx.translate(item.x+item.width/2,item.y+item.height/2);ctx.rotate(Number(item.rotation||0)*Math.PI/180000);
      ctx.fillStyle = fill; ctx.strokeStyle = stroke; ctx.lineWidth = Math.max(20, 1/this.scale);
      ctx.beginPath();
      if (item.type === 'round_table'||item.shape==='ellipse') ctx.ellipse(0,0,item.width/2,item.height/2,0,0,Math.PI*2);
      else ctx.rect(-item.width/2,-item.height/2,item.width,item.height);
      ctx.fill(); ctx.stroke();ctx.restore();
    };
    const text = (value, x, y, size = 14, color = '#17243b') => { ctx.fillStyle = color; ctx.font = `${size/this.scale}px system-ui,sans-serif`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(String(value),x,y); };
    for (const object of this.objects) if (visible(object)) { rect(object, '#e1e8f1'); if (object.label && object.width*this.scale > 40) text(object.label,object.x+object.width/2,object.y+object.height/2,12); }
    if (this.kind === 'overview') {
      for (const section of this.sections) {
        if (!section.bounds) continue;
        const box = section.bounds; rect(box,'#e4edfe','#7e9fd9');
        if (box.width*this.scale > 24) { text(section.label || section.code,box.x+box.width/2,box.y+box.height/2-8,13); text(section.capacity || '',box.x+box.width/2,box.y+box.height/2+12,11,'#52627a'); }
      }
    } else {
      for (const group of this.groups) if (visible(group)) {
        const delta=this.controller.has(group)&&this.ghost?this.ghost:{x:0,y:0};const shape={...group,x:group.x+delta.x,y:group.y+delta.y};
        rect(shape, this.controller.has(group) ? '#c8d9ff' : '#e8e0d3', this.controller.has(group) ? '#2563eb' : '#b3a997');
        if (group.width*this.scale > 24) text(group.code,shape.x+group.width/2,shape.y+group.height/2,11);
      }
      for (const seat of this.seats) if (visible(seat)) {
        const selected = this.controller.has(seat), available = this.isAvailable(seat);
        const delta = selected && this.ghost ? this.ghost : {x:0,y:0};
        ctx.save(); ctx.translate(seat.x+200+delta.x,seat.y+200+delta.y); ctx.rotate(seat.rotation*Math.PI/180000);
        ctx.fillStyle = selected ? '#2563eb' : available ? '#bce2d8' : '#c8cfdb';
        ctx.strokeStyle = selected ? '#1d4ed8' : available ? '#0f766e' : '#8c98aa'; ctx.lineWidth = Math.max(18,1/this.scale);
        ctx.beginPath(); ctx.roundRect(-190,-190,380,380,70); ctx.fill(); ctx.stroke();
        if (seat.accessible) { ctx.fillStyle = selected ? '#fff' : '#0f766e'; ctx.fillRect(-80,-80,160,160); }
        ctx.restore();
        if (this.scale*380 > 17) text(seat.number || seat.code,seat.x+200+delta.x,seat.y+200+delta.y,10,selected?'#fff':'#17243b');
      }
    }
    if (this.focused) {
      const item = this.focused, x = (item.x+200)*this.scale+this.offset.x, y = (item.y+200)*this.scale+this.offset.y;
      this.active.style.left = `${Math.max(0,Math.min(this.width-44,x-22))}px`; this.active.style.top = `${Math.max(0,Math.min(this.height-44,y-22))}px`;
      this.active.setAttribute('aria-pressed',String(this.controller.has(item)));
    }
  }
  destroy() { this.abort.abort(); this.resize.disconnect(); this.unsubscribe(); if (this.pendingFrame !== null) cancelAnimationFrame(this.pendingFrame); this.host.replaceChildren(); }
}
