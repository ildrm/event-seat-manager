/* Read-only, center-scoped ticket issuance analysis; no financial revenue or PII. */
(() => {
  'use strict';
  const createCell = (tr, value) => {
    const td = document.createElement('td');
    td.textContent = String(value ?? '');
    tr.appendChild(td);
  };
  const fillTable = (table, records, columns) => {
    const fragment = document.createDocumentFragment();
    for (const record of records) {
      const tr = document.createElement('tr');
      for (const key of columns) createCell(tr, record[key]);
      fragment.appendChild(tr);
    }
    table.replaceChildren(fragment);
  };
  const renderTrend = (svg, records) => {
    if (!svg) return;
    const NS = 'http://www.w3.org/2000/svg';
    svg.replaceChildren();
    const title = document.createElementNS(NS, 'title');
    title.textContent = window.PESM_I18N.text("روند تعداد بلیت‌های معتبر فعلی بر اساس تاریخ صدور (UTC)");
    svg.appendChild(title);
    const max = Math.max(1, ...records.map(r => Math.max(0, Number(r.issued_active_tickets) || 0)));
    const points = records.map((r, i) => {
      const count = Math.max(0, Number(r.issued_active_tickets) || 0);
      const x = 12 + (776 * i / Math.max(1, records.length - 1));
      const y = 145 - (130 * count / max);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    const line = document.createElementNS(NS, 'polyline');
    line.setAttribute('points', points.join(' '));
    line.setAttribute('stroke', 'currentColor');
    line.setAttribute('stroke-width', '2.5');
    line.setAttribute('fill', 'none');
    svg.appendChild(line);
  };
  const renderEvents = (table, records) => {
    const fragment = document.createDocumentFragment();
    for (const item of records) {
      const tr = document.createElement('tr');
      for (const key of ['title', 'sessions', 'sold', 'capacity']) createCell(tr, item[key]);
      const td = document.createElement('td');
      const percentage = Number(item.occupancy_percent) || 0;
      const meter = document.createElement('progress');
      meter.max = 100;
      meter.value = Math.min(100, Math.max(0, percentage));
      meter.setAttribute('aria-label', window.PESM_I18N.text("درصد تکمیل ظرفیت"));
      td.appendChild(meter);
      td.appendChild(document.createTextNode(' ' + String(percentage) + window.PESM_I18N.text("٪")));
      tr.appendChild(td);
      fragment.appendChild(tr);
    }
    table.replaceChildren(fragment);
  };
  const activate = (root) => {
    if (root.dataset.loaded === 'yes') return;
    root.dataset.loaded = 'yes';
    const center = Number(root.dataset.centerId);
    const endpoint = root.dataset.endpoint;
    const nonce = root.dataset.nonce;
    const days = root.querySelector('.pesm-insights-days');
    const session = root.querySelector('.pesm-insights-session');
    const status = root.querySelector('.pesm-insights-status');
    const trend = root.querySelector('.pesm-insights-trend');
    const graph = root.querySelector('.pesm-insights-trend-chart');
    const events = root.querySelector('.pesm-insights-events');
    const groups = root.querySelector('.pesm-insights-zones');
    const loadButton = root.querySelector('.pesm-insights-load');
    if (!center || !endpoint || !nonce || !status || !trend || !events || !groups) return;
    let active = null;
    const load = async () => {
      if (active) active.abort();
      const controller = new AbortController();
      active = controller;
      status.textContent = window.PESM_I18N.text("در حال دریافت داده‌های صدور بلیت…");
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set('center_id', String(center));
      url.searchParams.set('days', String(Number(days?.value || 30)));
      url.searchParams.set('session_id', String(Number(session?.value || 0)));
      try {
        const response = await fetch(url.toString(), {
          credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();
        if (data.center_id !== center || data.not_financial_revenue !== true ||
            !Array.isArray(data.trend) || !Array.isArray(data.events) || !Array.isArray(data.zone_rows)) {
          throw new Error('Invalid data');
        }
        if (active !== controller) return;
        fillTable(trend, data.trend, ['date', 'issued_active_tickets']);
        renderTrend(graph, data.trend);
        renderEvents(events, data.events);
        fillTable(groups, data.zone_rows, ['zone', 'row', 'tickets']);
        status.textContent = window.PESM_I18N.text("آخرین به‌روزرسانی: ") + new Date().toLocaleTimeString(window.PESM_I18N.locale) +
          window.PESM_I18N.text("؛ آمار بلیت‌های معتبر موجود است و به‌معنای درآمد یا فروش مالی قطعی نیست.");
      } catch (error) {
        if (error.name !== 'AbortError' && active === controller) {
          status.textContent = window.PESM_I18N.text("دریافت گزارش ممکن نشد؛ مجوز یا اتصال سرور را بررسی کنید.");
        }
      } finally {
        if (active === controller) active = null;
      }
    };
    loadButton?.addEventListener('click', load);
    days?.addEventListener('change', load);
    session?.addEventListener('change', load);
    load();
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.pesm-sales-insights[data-center-id]').forEach(activate);
  });
})();
