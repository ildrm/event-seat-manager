/* global wp */
(() => {
  'use strict';
  if (!window.wp?.blocks?.registerBlockType || !window.wp?.element?.createElement) return;
  const el = wp.element.createElement;
  const TextControl = wp.components.TextControl;
  const Placeholder = wp.components.Placeholder;
  const configs = [
    ['events', window.PESM_I18N.text("فهرست رویدادها")],
    ['event', window.PESM_I18N.text("جزئیات رویداد"), 'eventId', window.PESM_I18N.text("شناسه رویداد")],
    ['seat-map', window.PESM_I18N.text("نقشه تعاملی صندلی‌ها"), 'sessionId', window.PESM_I18N.text("شناسه جلسه")],
    ['my-tickets', window.PESM_I18N.text("پنل بلیت‌های من")],
    ['gate-scanner', window.PESM_I18N.text("کنترل ورود با QR")],
  ];
  configs.forEach(([slug, title, attribute, label]) => {
    wp.blocks.registerBlockType(`pesm/${slug}`, {
      title,
      edit: ({ attributes, setAttributes }) => {
        const controls = [el('p', { key: 'description' }, window.PESM_I18N.text("محتوای اصلی در نمای سایت به‌صورت پویا و با مجوزهای سرور نمایش داده می‌شود."))];
        if (attribute) {
          controls.push(el(TextControl, {
            key: 'id', label, type: 'number', min: 1,
            value: attributes[attribute] || '',
            onChange: value => setAttributes({ [attribute]: Math.max(0, parseInt(value, 10) || 0) }),
          }));
        }
        return el(Placeholder, { label: title, icon: 'tickets-alt' }, ...controls);
      },
      save: () => null,
    });
  });
})();
