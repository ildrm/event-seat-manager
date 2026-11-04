/* Independent attendee data, scoped to each checkout form; never uses innerHTML. */
(() => {
  'use strict';
  window.PESM_Attendees = {
    mount(form) {
      const toggle = form.querySelector('.pesm-attendees-toggle');
      const container = form.querySelector('.pesm-attendee-fields');
      let entries = [];
      const build = () => {
        container.replaceChildren();
        container.hidden = !toggle.checked;
        entries.forEach((entry, index) => {
          const fieldset = document.createElement('fieldset');
          const legend = document.createElement('legend');
          legend.textContent = entry.label || (window.PESM_I18N.text("بلیت شماره ") + String(index + 1));
          fieldset.append(legend);
          for (const [key, title] of [['first_name', window.PESM_I18N.text("نام")], ['last_name', window.PESM_I18N.text("نام خانوادگی")]]) {
            const label = document.createElement('label');
            label.textContent = key === 'first_name' ? window.PESM_I18N.text('نام شرکت‌کننده') : window.PESM_I18N.text('نام خانوادگی شرکت‌کننده');
            const input = document.createElement('input');
            input.type = 'text';
            input.maxLength = 90;
            input.required = toggle.checked;
            input.dataset.attendeeField = key;
            input.value = entry[key] || '';
            label.append(input);
            fieldset.append(label);
          }
          container.append(fieldset);
        });
      };
      const saveValues = () => {
        container.querySelectorAll('fieldset').forEach((fieldset, index) => {
          if (!entries[index]) return;
          fieldset.querySelectorAll('[data-attendee-field]').forEach(input => {
            entries[index][input.dataset.attendeeField] = input.value;
          });
        });
      };
      toggle.addEventListener('change', () => { saveValues(); build(); });
      return {
        set(nextEntries) {
          saveValues();
          const old = new Map(entries.map(e => [String(e.seat_id ?? e.admission_index), e]));
          entries = nextEntries.map(e => ({...e, ...(old.get(String(e.seat_id ?? e.admission_index)) || {})}));
          build();
        },
        payload() {
          if (!toggle.checked) return null;
          saveValues();
          for (const fieldset of container.querySelectorAll('fieldset')) {
            for (const input of fieldset.querySelectorAll('input')) {
              if (!input.checkValidity()) { input.reportValidity(); throw new Error(window.PESM_I18N.text("نام و نام خانوادگی هر شرکت‌کننده را وارد کنید.")); }
            }
          }
          if (entries.length === 0) throw new Error(window.PESM_I18N.text("ابتدا ظرفیت یا صندلی‌های خود را نگهداری کنید."));
          return entries.map(e => ({
            ...(e.seat_id ? {seat_id: e.seat_id} : {}),
            first_name: String(e.first_name || '').trim(),
            last_name: String(e.last_name || '').trim()
          }));
        }
      };
    }
  };
})();
