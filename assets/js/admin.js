/* Scoped, progressive navigation for the WordPress event workspace. */
(() => {
  'use strict';
  const workspace = document.querySelector('.pesm-admin');
  if (!workspace) return;
  const panels = Array.from(workspace.querySelectorAll('[data-pesm-panel]'));
  const links = Array.from(workspace.querySelectorAll('[data-pesm-nav]'));
  const navigation = Array.from(workspace.querySelectorAll('.pesm-admin-nav-link'));
  const heading = workspace.querySelector('[data-pesm-view-title]');
  const description = workspace.querySelector('[data-pesm-view-description]');
  const content = workspace.querySelector('#pesm-workspace-content');
  const skipLink = workspace.querySelector('.pesm-admin-skip');
  const views = new Map(navigation.map(link => [link.dataset.pesmNav, link]));
  if (!panels.length || !heading || !views.size) return;
  const fallback = views.has('overview') ? 'overview' : navigation[0].dataset.pesmNav;
  const requestedView = () => {
    const hash = window.location.hash;
    return hash.startsWith('#pesm-') ? hash.slice(6) : fallback;
  };
  let current = null;
  const show = (requested, focus = false) => {
    const next = views.has(requested) && panels.some(panel => panel.dataset.pesmPanel === requested) ? requested : fallback;
    const link = views.get(next);
    panels.forEach(panel => { panel.hidden = panel.dataset.pesmPanel !== next; });
    navigation.forEach(item => {
      if (item.dataset.pesmNav === next) item.setAttribute('aria-current', 'page');
      else item.removeAttribute('aria-current');
    });
    heading.textContent = link.querySelector('span')?.textContent || link.textContent.trim();
    if (description) description.textContent = link.dataset.description || '';
    current = next;
    workspace.classList.add('pesm-admin-enhanced');
    if (focus) {
      heading.focus({ preventScroll: true });
      heading.scrollIntoView({ block: 'start', behavior: 'auto' });
    }
    workspace.dispatchEvent(new CustomEvent('pesm:viewchange', { detail: { view: next } }));
  };
  links.forEach(link => link.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const view = link.dataset.pesmNav;
    if (!views.has(view)) return;
    event.preventDefault();
    if (window.location.hash !== `#pesm-${view}`) window.history.pushState(null, '', `#pesm-${view}`);
    show(view, true);
  }));
  if (skipLink && content) {
    skipLink.addEventListener('click', event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      // Skipping focuses the active workspace; it must not enter the view hash router.
      event.preventDefault();
      content.focus({ preventScroll: true });
      content.scrollIntoView({ block: 'start', behavior: 'auto' });
    });
  }
  window.addEventListener('hashchange', () => show(requestedView()));
  window.addEventListener('popstate', () => show(requestedView()));
  // Server-rendered view fields keep successful/failed submissions in their area.
  // Sync the value only for forms that actually belong to the active panel.
  workspace.addEventListener('submit', event => {
    const form = event.target;
    const panel = form.closest?.('[data-pesm-panel]');
    const field = form.querySelector?.('input[name="pesm_view"]');
    if (panel && field && views.has(panel.dataset.pesmPanel)) field.value = panel.dataset.pesmPanel;
  });
  show(requestedView());
})();
