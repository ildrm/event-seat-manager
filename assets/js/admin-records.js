(() => {
  'use strict';
  const workspace = document.querySelector('.pesm-admin');
  if (!workspace) return;

  const searchable = value => value.normalize('NFKC').toLocaleLowerCase()
    .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\u200d]/g, ' ');

  workspace.querySelectorAll('[data-record-filter]').forEach(input => {
    const scope = input.closest('.pesm-admin-card');
    const items = Array.from(scope.querySelectorAll('[data-record]'));
    const empty = scope.querySelector('[data-record-empty]');
    input.parentElement.hidden = false;
    input.addEventListener('input', () => {
      const query = searchable(input.value.trim());
      items.forEach(item => { item.hidden = !searchable(item.textContent).includes(query); });
      empty.hidden = items.some(item => !item.hidden);
    });
  });

  workspace.querySelectorAll('[data-grid-count]').forEach(output => {
    const form = output.form;
    const rows = form.elements.rows;
    const columns = form.elements.columns;
    const number = new Intl.NumberFormat(workspace.dir === 'rtl' ? 'fa' : 'en');
    const showCount = () => {
      const counts = [rows.valueAsNumber, columns.valueAsNumber];
      output.value = counts.every(value => Number.isInteger(value) && value >= 1 && value <= 100)
        ? output.dataset.gridCount.replace('%s', number.format(counts[0] * counts[1])) : '';
    };
    rows.addEventListener('input', showCount);
    columns.addEventListener('input', showCount);
    showCount();
  });

  const legacy = workspace.querySelector('form:has(input[name="action"][value="pesm_create_session"])');
  if (legacy) {
    const mode = legacy.elements.mode;
    const capacity = legacy.elements.capacity;
    const update = () => {
      capacity.closest('label').hidden = mode.value !== 'general';
      if (mode.value === 'seated') capacity.value = '0';
    };
    mode.addEventListener('change', update);
    update();
  }
})();
