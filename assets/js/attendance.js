/* Center-scoped aggregate reporting: refreshes through the authenticated WordPress REST route. */
(() => {
  'use strict';
  const ROOT_SELECTOR = '.pesm-attendance[data-center-id]';
  const columns = ['title', 'session_id', 'capacity', 'sold', 'checked_in', 'no_show'];
  function activate(section) {
    if (section.dataset.initialized === '1') return;
    section.dataset.initialized = '1';
    const select = section.querySelector('.pesm-attendance-session');
    const rows = section.querySelector('.pesm-attendance-rows');
    const status = section.querySelector('.pesm-attendance-status');
    const refresh = section.querySelector('.pesm-attendance-refresh');
    const print = section.querySelector('.pesm-attendance-print');
    const center = Number(section.dataset.centerId);
    const endpoint = section.dataset.endpoint;
    const nonce = section.dataset.nonce;
    if (!center || !endpoint || !nonce || !rows || !status) return;
    let activeRequest = null;
    async function load() {
      if (document.hidden) return;
      if (activeRequest) activeRequest.abort();
      const controller = new AbortController();
      activeRequest = controller;
      const session = Number(select?.value || 0);
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set('center_id', String(center));
      if (session > 0) url.searchParams.set('session_id', String(session));
      status.textContent = window.PESM_I18N.text("در حال دریافت آمار جدید…");
      try {
        const response = await fetch(url.toString(), {
          credentials: 'same-origin', cache: 'no-store',
          headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' },
          signal: controller.signal
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();
        if (!Array.isArray(data.sessions) || Number(data.center_id) !== center) throw new Error('Invalid response');
        const fragment = document.createDocumentFragment();
        for (const row of data.sessions) {
          const tr = document.createElement('tr');
          for (const key of columns) {
            const td = document.createElement('td');
            // textContent intentionally prevents HTML injection from event titles.
            td.textContent = String(row[key] ?? '');
            tr.appendChild(td);
          }
          fragment.appendChild(tr);
        }
        // Stale/aborted requests cannot overwrite the current filter's response.
        if (activeRequest === controller) {
          rows.replaceChildren(fragment);
          status.textContent = window.PESM_I18N.text("آخرین به‌روزرسانی: ") + new Date().toLocaleTimeString(window.PESM_I18N.locale);
        }
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = window.PESM_I18N.text("به‌روزرسانی انجام نشد. دسترسی یا اتصال شبکه را بررسی کنید.");
      } finally {
        if (activeRequest === controller) activeRequest = null;
      }
    }
    select?.addEventListener('change', load);
    refresh?.addEventListener('click', load);
    print?.addEventListener('click', () => window.print());
    // WP-Cron is not involved in the read-only refresh.
    window.setInterval(load, 60000);
  }
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(ROOT_SELECTOR).forEach(activate);
  });
})();
