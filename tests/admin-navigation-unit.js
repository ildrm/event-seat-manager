/* Behavioral checks for the progressively enhanced admin workspace; no browser claims. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');
let checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; };

class Node {
  constructor(dataset = {}, textContent = '') {
    this.dataset = dataset;
    this.textContent = textContent;
    this.hidden = false;
    this.attributes = new Map();
    this.listeners = new Map();
    this.classes = new Set();
    this.classList = {add: name => this.classes.add(name), contains: name => this.classes.has(name)};
    this.focusCount = 0;
    this.scrollCount = 0;
  }
  setAttribute(name, value) { this.attributes.set(name, value); }
  removeAttribute(name) { this.attributes.delete(name); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  addEventListener(name, handler) { this.listeners.set(name, handler); }
  focus() { this.focusCount++; }
  scrollIntoView() { this.scrollCount++; }
  fire(type, details = {}) {
    let prevented = false;
    const event = {button: 0, preventDefault() { prevented = true; }, ...details};
    this.listeners.get(type)?.(event);
    return prevented;
  }
}

function mount({hash = '', allowed = ['overview', 'events', 'halls'], complete = true} = {}) {
  const panels = allowed.map(name => new Node({pesmPanel: name}));
  const navigation = allowed.map(name => {
    const node = new Node({pesmNav: name, description: `${name} description`}, `${name} title`);
    node.querySelector = selector => selector === 'span' ? {textContent: `${name} title`} : null;
    return node;
  });
  const cta = new Node({pesmNav: allowed.at(-1)}, 'Open section');
  const heading = new Node();
  const description = new Node();
  const content = new Node();
  const skipLink = new Node();
  const workspace = new Node();
  const changes = [];
  workspace.querySelectorAll = selector => ({
    '[data-pesm-panel]': panels, '[data-pesm-nav]': [...navigation, cta], '.pesm-admin-nav-link': navigation,
  })[selector] || [];
  workspace.querySelector = selector => ({
    '[data-pesm-view-title]': complete ? heading : null,
    '[data-pesm-view-description]': description,
    '#pesm-workspace-content': content,
    '.pesm-admin-skip': skipLink,
  })[selector] || null;
  workspace.dispatchEvent = event => changes.push(event.detail.view);
  const events = new Map();
  const history = [];
  const window = {
    location: {hash},
    history: {pushState(_state, _title, value) { history.push(value); window.location.hash = value; }},
    addEventListener(name, callback) { events.set(name, callback); },
  };
  vm.runInNewContext(source, {
    document: {querySelector: () => workspace}, window,
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
  });
  return {panels, navigation, cta, heading, description, content, skipLink, workspace, changes, history, window, events};
}

const direct = mount({hash: '#pesm-halls'});
check(direct.panels.filter(panel => !panel.hidden).map(panel => panel.dataset.pesmPanel).join() === 'halls', 'Direct links open exactly the permitted target section');
check(direct.navigation[2].getAttribute('aria-current') === 'page', 'The current navigation item is exposed to assistive technology');
check(direct.navigation.slice(0, 2).every(link => link.getAttribute('aria-current') === null), 'Inactive links do not claim current state');
check(direct.heading.textContent === 'halls title' && direct.description.textContent === 'halls description', 'Page context matches the opened section');
check(direct.heading.focusCount === 0, 'An initial deep link does not steal keyboard focus');
check(direct.navigation[1].fire('click'), 'Ordinary navigation is handled locally');
check(direct.window.location.hash === '#pesm-events' && direct.history.length === 1, 'Navigation creates a shareable browser-history entry');
check(direct.panels.filter(panel => !panel.hidden)[0].dataset.pesmPanel === 'events', 'Clicking another section updates visible content');
check(direct.heading.focusCount === 1 && direct.heading.scrollCount === 1, 'Explicit navigation moves focus to the visible page heading');
check(direct.changes.at(-1) === 'events', 'Section observers receive the active view');
const unchangedHistory = direct.history.length;
check(!direct.navigation[0].fire('click', {ctrlKey: true}) && direct.history.length === unchangedHistory, 'Modified clicks keep normal browser new-tab behavior');
direct.window.location.hash = '#pesm-halls';
direct.events.get('popstate')();
check(direct.panels.filter(panel => !panel.hidden)[0].dataset.pesmPanel === 'halls', 'Back/forward navigation restores the matching section');
check(direct.heading.focusCount === 1, 'History navigation does not unexpectedly refocus the page');
direct.cta.fire('click');
check(direct.heading.textContent === 'halls title', 'A secondary shortcut uses the canonical section label');
const beforeSkipHash = direct.window.location.hash;
const beforeSkipHistory = direct.history.length;
check(direct.skipLink.fire('click'), 'The enhanced skip link focuses content without navigating');
check(direct.window.location.hash === beforeSkipHash && direct.history.length === beforeSkipHistory, 'Skipping content preserves the active section URL and history');
check(direct.panels.filter(panel => !panel.hidden)[0].dataset.pesmPanel === 'halls', 'Skipping from a task view does not switch to overview');
check(direct.content.focusCount === 1 && direct.content.scrollCount === 1, 'Skipping places focus on the current main content');
check(!direct.skipLink.fire('click', {ctrlKey: true}) && direct.content.focusCount === 1, 'Modified skip-link clicks preserve native browser behavior');

const forbidden = mount({hash: '#pesm-settings', allowed: ['overview', 'reports']});
check(forbidden.panels.filter(panel => !panel.hidden)[0].dataset.pesmPanel === 'overview', 'Unavailable or unpermitted hashes safely fall back');
forbidden.window.location.hash = '#pesm-%3Cscript%3E';
forbidden.events.get('hashchange')();
check(forbidden.heading.textContent === 'overview title', 'Arbitrary hashes are not inserted into page labels');
const field = {value: 'wrong'};
forbidden.workspace.fire('submit', {target: {
  closest: () => forbidden.panels[1], querySelector: () => field,
}});
check(field.value === 'reports', 'Form submissions retain their own section for success/error return');
const foreignField = {value: 'unchanged'};
forbidden.workspace.fire('submit', {target: {
  closest: () => new Node({pesmPanel: 'settings'}), querySelector: () => foreignField,
}});
check(foreignField.value === 'unchanged', 'Forms outside the rendered permission set are not remapped');
const incomplete = mount({complete: false});
check(incomplete.panels.every(panel => !panel.hidden) && !incomplete.workspace.classes.size, 'Unavailable enhancement leaves server-rendered sections accessible');
check(mount({allowed: ['reports']}).panels[0].hidden === false, 'A restricted workspace falls back to its first available section');

console.log(`PASS: ${checks} admin navigation, history, focus, permission-fallback and form-return checks.`);
