/* Exact static message lookup shared by all Event Seat Manager interfaces. */
(() => {
    'use strict';
    const config = window.PESM_I18N_CONFIG || {};
    const messages = config.messages || {};
    const statuses = {active: 'فعال', checked_in: 'ورود ثبت‌شده', cancelled: 'لغوشده', refunded: 'مستردشده', failed: 'ناموفق', pending: 'در انتظار', 'on-hold': 'در انتظار بررسی', processing: 'در حال انجام', completed: 'تکمیل‌شده', issued: 'صادرشده', held: 'نگهداری موقت', payment_pending: 'در انتظار پرداخت', sold: 'فروخته‌شده', blocked: 'مسدود', available: 'آزاد', refund_pending: 'در انتظار استرداد'};
    const text = (message) => Object.prototype.hasOwnProperty.call(messages, message) ? messages[message] : message;
    // WordPress may use /wp-json/ routes or index.php?rest_route= with plain permalinks.
    // Resolve only a relative API endpoint, preserving the site's subdirectory and query.
    window.PESM_restURL = (path, base) => {
        if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(path)) throw new Error('API endpoint must be relative.');
        const root = new URL(base);
        if (!root.searchParams.has('rest_route')) return new URL(path, root);
        const relative = new URL(path.replace(/^\/+/, ''), 'https://pesm.invalid/');
        const route = root.searchParams.get('rest_route').replace(/\/+$/, '') + relative.pathname;
        root.searchParams.set('rest_route', route);
        relative.searchParams.forEach((value, key) => root.searchParams.set(key, value));
        return root;
    };
    window.PESM_I18N = Object.freeze({
        locale: String(config.locale || 'fa_IR').split('@')[0].replaceAll('_', '-'),
        direction: config.direction || 'rtl',
        text(message) {
            return text(message);
        },
        status(value) {
            return Object.prototype.hasOwnProperty.call(statuses, value) ? text(statuses[value]) : value;
        },
    });
})();
