/* Restricted seat inventory snapshot for center managers; never render HTML from API strings. */
(() => {
  'use strict';
  const root=document.querySelector('.pesm-seat-status-report');
  if (!root || typeof pesmSeatStatusConfig !== 'object') return;
  const selector=root.querySelector('.pesm-status-session');
  const msg=root.querySelector('.pesm-seat-status-message');
  const totals=root.querySelector('.pesm-seat-status-totals');
  const svg=root.querySelector('.pesm-seat-status-svg');
  const groups=root.querySelector('.pesm-seat-status-groups');
  const colors={available:'#16a34a',held:'#d97706',payment_pending:'#f59e0b',sold:'#64748b',blocked:'#991b1b',missing:'#1e293b'};
  const words={available:window.PESM_I18N.text("آزاد"),held:window.PESM_I18N.text("نگهداری موقت"),payment_pending:window.PESM_I18N.text("در انتظار پرداخت"),sold:window.PESM_I18N.text("فروخته‌شده"),blocked:window.PESM_I18N.text("مسدود"),missing:window.PESM_I18N.text("نامشخص")};
  let busy=false;
  const ns='http://www.w3.org/2000/svg';
  async function refresh() {
    if (busy || !selector.value) return;
    const [center,session]=selector.value.split(':').map(Number);
    if (!Number.isInteger(center)||!Number.isInteger(session)) return;
    busy=true;msg.textContent=window.PESM_I18N.text("در حال دریافت وضعیت...");
    try {
      const url=new URL(pesmSeatStatusConfig.url);
      url.searchParams.set('center_id',String(center));url.searchParams.set('session_id',String(session));
      const response=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store',
        headers:{'X-WP-Nonce':pesmSeatStatusConfig.nonce,Accept:'application/json'}});
      const data=await response.json();
      if (!response.ok || !Array.isArray(data.seats)) throw new Error(data?.message||window.PESM_I18N.text("دریافت گزارش ممکن نشد."));
      const summary=Object.entries(data.totals||{}).map(([key,value])=>`${words[key]||key}: ${Number(value)}`).join(' | ');
      totals.textContent=summary;
      const dots=document.createDocumentFragment();
      let maxX=100,maxY=100;
      for (const seat of data.seats) {
        const x=Number(seat.pos_x),y=Number(seat.pos_y);
        if (!Number.isFinite(x)||!Number.isFinite(y)) continue;
        maxX=Math.max(maxX,x+20);maxY=Math.max(maxY,y+20);
        const circle=document.createElementNS(ns,'circle');
        circle.setAttribute('cx',String(x));circle.setAttribute('cy',String(y));
        circle.setAttribute('r','10');circle.setAttribute('fill',colors[seat.status]||colors.missing);
        circle.setAttribute('stroke','#fff');
        const title=document.createElementNS(ns,'title');
        title.textContent=(String(seat.zone_label) + window.PESM_I18N.text("، ردیف ") + String(seat.row_label) + window.PESM_I18N.text("، ") + String(seat.seat_code) + ": " + String(words[seat.status]||window.PESM_I18N.text("نامشخص")));
        circle.appendChild(title);dots.appendChild(circle);
      }
      svg.setAttribute('viewBox',`0 0 ${maxX+20} ${maxY+20}`);svg.replaceChildren(dots);
      const table=document.createElement('table');table.className='widefat striped';
      const head=document.createElement('tr');
      for (const text of [window.PESM_I18N.text("زون"),window.PESM_I18N.text("ردیف"),window.PESM_I18N.text("تعداد"),window.PESM_I18N.text("فروخته‌شده"),window.PESM_I18N.text("آزاد")]) {
        const th=document.createElement('th');th.textContent=text;head.appendChild(th);
      }
      table.appendChild(head);
      for (const group of (data.groups||[])) {
        const tr=document.createElement('tr');
        for (const text of [group.zone,group.row,group.total,group.sold,group.available]) {
          const td=document.createElement('td');td.textContent=String(text);tr.appendChild(td);
        }
        table.appendChild(tr);
      }
      groups.replaceChildren(table);
      msg.textContent=(String(data.seats.length) + window.PESM_I18N.text(" صندلی بررسی شد."));
    } catch(error) {
      msg.textContent=error?.message||window.PESM_I18N.text("خطای دریافت گزارش");
      svg.replaceChildren();totals.textContent='';groups.replaceChildren();
    } finally {busy=false;}
  }
  root.querySelector('.pesm-seat-status-refresh').addEventListener('click',refresh);
  selector.addEventListener('change',refresh);
  setInterval(()=>{if (!document.hidden && selector.value) refresh();},30000);
})();
