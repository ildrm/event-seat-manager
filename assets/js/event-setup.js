(() => {
  'use strict';

  const attachPosterPicker = poster => {
    if (!window.wp?.media) return;
    const pick = poster.querySelector('[data-media-picker]');
    const remove = poster.querySelector('[data-media-remove]');
    const value = poster.querySelector('[name="poster_attachment_id"]');
    const target = poster.querySelector('[data-poster-image]');
    poster.querySelector('[data-poster-manual]').hidden = true;
    pick.hidden = false;
    remove.hidden = !value.value;
    let frame;
    pick.addEventListener('click', () => {
      if (!frame) {
        frame = window.wp.media({title: pick.textContent, library: {type: 'image'}, multiple: false});
        frame.on('select', () => {
          const image = frame.state().get('selection').first().toJSON();
          const preview = document.createElement('img');
          preview.src = image.sizes?.medium?.url || image.url;
          preview.alt = image.alt || '';
          value.value = image.id;
          target.replaceChildren(preview);
          remove.hidden = false;
        });
      }
      frame.open();
    });
    remove.addEventListener('click', () => {
      target.replaceChildren();
      value.value = '';
      remove.hidden = true;
      pick.focus();
    });
  };
  document.querySelectorAll(".pesm-setup [data-poster], .pesm-admin [data-poster]").forEach(attachPosterPicker);

  const root = document.querySelector('[data-pesm-setup]');
  if (!root) return;

  const form = root.querySelector('[data-setup-form]');
  if (!form) return;

  const panels = Array.from(form.querySelectorAll('[data-mode-panel]'));
  const showMode = () => {
    const selected = form.querySelector('[name="mode"]:checked');
    if (!selected) return;
    const next = form.querySelector('[name="intent"][value="next"]');
    if (next && panels.length) {
      next.hidden = panels.some(panel => panel.dataset.modePanel === selected.value && panel.hasAttribute('data-no-layout'));
    }
    panels.forEach(panel => {
      panel.hidden = panel.dataset.modePanel !== selected.value;
      panel.querySelectorAll('input, select, button').forEach(field => {
        field.disabled = panel.hidden;
      });
    });
  };
  form.querySelectorAll('[name="mode"]').forEach(mode => mode.addEventListener('change', showMode));
  showMode();

  const labelFor = index => {
    let label = '';
    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) {
      label = String.fromCharCode(65 + (n - 1) % 26) + label;
    }
    return label;
  };
  const grid = form.querySelector('[data-grid]');
  if (grid) {
    const rows = grid.querySelector('[name="rows"]');
    const columns = grid.querySelector('[name="columns"]');
    const preview = grid.querySelector('[data-grid-preview]');
    const format = new Intl.NumberFormat(root.dir === 'rtl' ? 'fa' : 'en');
    const showGrid = () => {
      preview.replaceChildren();
      const rowCount = Number(rows.value);
      const columnCount = Number(columns.value);
      if (!rows.checkValidity() || !columns.checkValidity() || rowCount < 1 || columnCount < 1) return;
      const summary = document.createElement('p');
      summary.setAttribute('role', 'status');
      summary.textContent = preview.dataset.seatCount.replace('%s', format.format(rowCount * columnCount));
      const map = document.createElement('div');
      map.className = 'pesm-setup-grid';
      map.tabIndex = 0;
      map.setAttribute('role', 'img');
      map.setAttribute('aria-label', summary.textContent);
      for (let row = 0; row < Math.min(rowCount, 7); row++) {
        const line = document.createElement('div');
        line.className = 'pesm-setup-grid-line';
        const heading = document.createElement('strong');
        heading.textContent = labelFor(row);
        line.append(heading);
        for (let column = 1; column <= Math.min(columnCount, 14); column++) {
          const cell = document.createElement('span');
          cell.textContent = column;
          cell.title = labelFor(row) + '-' + column;
          line.append(cell);
        }
        if (columnCount > 14) line.append('…');
        map.append(line);
      }
      if (rowCount > 7) map.append('… ' + labelFor(rowCount - 1));
      preview.append(summary, map);
    };
    [rows, columns].forEach(field => field.addEventListener('input', showGrid));
    grid.querySelector('.pesm-setup-preview-submit').hidden = true;
  }

  panels.forEach(panel => {
    const select = panel.querySelector('[data-layout-select]');
    const search = panel.querySelector('[data-search-input]');
    const figure = panel.querySelector('[data-existing-map]');
    if (!select || !search || !figure) return;
    panel.querySelector('[data-layout-search]').hidden = select.querySelectorAll('option[data-hall]').length <= 8;
    search.addEventListener('input', () => {
      const term = search.value.trim().toLocaleLowerCase();
      select.querySelectorAll('option[data-hall]').forEach(option => {
        const matches = option.dataset.hall.toLocaleLowerCase().includes(term);
        option.hidden = !matches && !option.selected;
        option.disabled = option.hidden;
      });
    });
    let request;
    const showPreview = async () => {
      if (request) request.abort();
      figure.hidden = true;
      if (select.value === '0' || panel.hidden) return;
      const controller = new AbortController();
      request = controller;
      try {
        const url = new URL(select.dataset.previewUrl);
        url.searchParams.set('layout', select.value);
        const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
        const result = await response.json();
        if (controller.signal.aborted || !result.success || !result.data.seats.length) return;
        const canvas = figure.querySelector('canvas');
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const {bounds, seats} = result.data;
        const minX = Number(bounds.min_x);
        const minY = Number(bounds.min_y);
        const spanX = Math.max(1, Number(bounds.max_x) - minX);
        const spanY = Math.max(1, Number(bounds.max_y) - minY);
        const scale = Math.min((canvas.width - 28) / spanX, (canvas.height - 28) / spanY);
        const left = (canvas.width - spanX * scale) / 2;
        const top = (canvas.height - spanY * scale) / 2;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#2563eb';
        seats.forEach(({x, y}) => {
          ctx.fillRect(left + (Number(x) - minX) * scale - 2, top + (Number(y) - minY) * scale - 2, 4, 4);
        });
        figure.hidden = panel.hidden;
      } catch (error) {
        if (!controller.signal.aborted) figure.hidden = true;
      }
    };
    select.addEventListener('change', showPreview);
    form.querySelectorAll('[name="mode"]').forEach(mode => mode.addEventListener('change', showPreview));
    showPreview();
  });

  form.querySelectorAll('[data-session-row]').forEach(row => {
    const price = row.querySelector('[data-price-mode]');
    const amount = row.querySelector('[data-price-amount]');
    const custom = row.querySelector('[data-custom-sales]');
    const dates = row.querySelector('[data-custom-fields]');
    if (price && amount) {
      const updatePrice = () => { amount.hidden = price.value !== 'paid'; };
      price.addEventListener('change', updatePrice);
      updatePrice();
    }
    if (custom && dates) {
      const updateDates = () => { dates.hidden = !custom.checked; };
      custom.addEventListener('change', updateDates);
      updateDates();
    }
  });

  form.querySelectorAll('[data-copy]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', async () => {
      const input = button.previousElementSibling.querySelector('[data-copy-value]');
      if (navigator.clipboard) {
        try {
          await navigator.clipboard.writeText(input.value);
          button.nextElementSibling.textContent = button.dataset.copied;
          return;
        } catch (error) {
          // The link remains selectable in browsers that deny clipboard access.
        }
      }
      input.focus();
      input.select();
    });
  });

  const error = root.querySelector('[data-setup-error]');
  if (error) {
    const number = error.dataset.errorRow;
    const scope = number ? form.querySelector('[data-session-row="' + number + '"]') : form;
    const target = scope?.querySelector('[data-field~="' + error.dataset.setupError + '"]');
    if (target) {
      const parent = target.closest('details');
      if (parent) parent.open = true;
      const label = target.closest('label');
      if (label) label.hidden = false;
      error.id = 'pesm-setup-field-error';
      target.setAttribute('aria-invalid', 'true');
      target.setAttribute('aria-describedby', error.id);
      target.focus();
    } else {
      error.focus();
    }
  }

  form.addEventListener('submit', event => {
    if (form.dataset.submitting) {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
  });
  window.addEventListener('pageshow', () => {
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');
  });
})();
