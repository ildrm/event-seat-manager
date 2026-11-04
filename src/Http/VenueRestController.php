<?php

declare(strict_types=1);

namespace PESM\Http;

use PESM\Domain\Allocation\CheckoutService;
use PESM\Domain\Allocation\GateService;
use PESM\Domain\Allocation\GroupAdministration;
use PESM\Domain\Allocation\ReservationService;
use PESM\Domain\Allocation\SessionService;
use PESM\Domain\Booking\HoldException;
use PESM\Domain\Hall\HallService;
use PESM\Domain\Venue\Generator;
use PESM\Domain\Venue\JobService;
use PESM\Domain\Venue\LayoutRepository;
use PESM\Domain\Venue\PresetCatalog;
use PESM\Security\Capabilities;
use PESM\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Bounded v2 adapters. Inventory, geometry and financial decisions live in services. */
final class VenueRestController
{
    public static function register(): void
    {
        $routes = [
            ['/presets', 'GET', 'presets', null],
            ['/presets/(?P<preset>[a-z0-9_-]+)/preview', 'GET', 'preview', null],
            ['/admin/jobs', 'POST', 'createJob', 'pesm_edit_layouts'],
            ['/admin/jobs/(?P<id>\d+)', 'GET', 'job', 'pesm_edit_layouts'],
            ['/admin/jobs/(?P<id>\d+)/retry', 'POST', 'retryJob', 'pesm_edit_layouts'],
            ['/admin/jobs/(?P<id>\d+)/run', 'POST', 'runJob', 'pesm_edit_layouts'],
            ['/admin/import', 'POST', 'import', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)', 'GET', 'adminLayout', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/tiles/(?P<tile>[a-zA-Z0-9_.-]+)', 'GET', 'adminTile', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/units', 'GET', 'adminUnits', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/locate', 'GET', 'adminLocate', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/commands', 'PATCH', 'commands', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/publish', 'POST', 'publish', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/export', 'GET', 'export', 'pesm_edit_layouts'],
            ['/admin/layouts/(?P<id>\d+)/print', 'GET', 'print', 'pesm_edit_layouts'],
            ['/admin/sessions', 'POST', 'createSession', 'pesm_manage_sessions'],
            ['/admin/sessions/(?P<id>\d+)/config', 'GET,POST,PATCH', 'config', 'pesm_manage_sessions'],
            ['/admin/sessions/(?P<id>\d+)/publish', 'POST', 'publishSession', 'pesm_manage_sessions'],
            ['/admin/sessions/(?P<id>\d+)/block', 'POST', 'block', 'pesm_edit_layouts'],
            ['/admin/sessions/(?P<id>\d+)/lines', 'GET', 'lines', 'pesm_manage_refunds'],
            ['/admin/lines/(?P<id>\d+)/cancel', 'POST', 'cancel', 'pesm_manage_refunds'],
            ['/admin/lines/(?P<id>\d+)/confirm-refund', 'POST', 'confirmRefund', 'pesm_manage_refunds'],
            ['/admin/lines/(?P<id>\d+)/deny-refund', 'POST', 'denyRefund', 'pesm_manage_refunds'],
            ['/admin/lines/(?P<id>\d+)/reallocate', 'POST', 'reallocate', 'pesm_manage_refunds'],
            ['/sessions/(?P<id>\d+)/layout', 'GET', 'layout', null],
            ['/sessions/(?P<id>\d+)/tiles/(?P<tile>[a-zA-Z0-9_.-]+)', 'GET', 'tile', null],
            ['/sessions/(?P<id>\d+)/units', 'GET', 'units', null],
            ['/sessions/(?P<id>\d+)/locate', 'GET', 'locate', null],
            ['/sessions/(?P<id>\d+)/availability', 'GET', 'availability', null],
            ['/sessions/(?P<id>\d+)/quote', 'POST', 'quote', null],
            ['/sessions/(?P<id>\d+)/holds', 'GET,POST,PATCH,DELETE', 'holds', null],
            ['/sessions/(?P<id>\d+)/checkout', 'POST', 'checkout', null],
            ['/tickets', 'GET', 'tickets', null],
            ['/guest-access/request', 'POST', 'recoveryRequest', null],
            ['/guest-access/verify', 'POST', 'recoveryVerify', null],
            ['/cart/attach', 'POST', 'cartAttach', null],
            ['/cart/preview', 'GET', 'cartPreview', null],
            ['/cart/replace', 'POST', 'cartReplace', null],
            ['/cart/complete', 'POST', 'cartComplete', null],
            ['/gate/check-in', 'POST', 'gate', 'pesm_scan_tickets'],
            ['/gate/lookup', 'GET', 'gateLookup', 'pesm_scan_tickets'],
            ['/gate/manual-check-in', 'POST', 'gateManual', 'pesm_scan_tickets'],
        ];
        foreach ($routes as [$path, $methods, $action, $capability]) {
            register_rest_route('pesm/v2', $path, [
                'methods' => $methods,
                'permission_callback' => $capability === null ? '__return_true'
                    : static fn(): bool => get_current_user_id() > 0 && current_user_can($capability),
                'callback' => static fn(WP_REST_Request $request): WP_REST_Response|WP_Error => self::dispatch($action, $request),
            ]);
        }
    }

    public static function dispatch(string $action, WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        try {
            if (strlen((string) $r->get_header('content-length')) > 12) { throw new \InvalidArgumentException('Request too large.'); }
            if (str_contains((string) $r->get_header('content-type'), 'application/json') && (int) $r->get_header('content-length') > 4194304) {
                throw new \InvalidArgumentException('Request too large.');
            }
            $json = $r->get_json_params();
            $body = is_array($json) ? $json : [];
            $id = (int) $r['id'];
            $repository = new LayoutRepository();
            switch ($action) {
                case 'presets': return self::response(['presets' => PresetCatalog::all(), 'max_capacity' => 150000]);
                case 'preview':
                    return self::response(Generator::generate((string) $r['preset'], ['capacity' => 48, 'workflow' => 'exact']));
                case 'createJob':
                    $hall = self::hall((int) ($body['hall_id'] ?? 0));
                    if (($body['kind'] ?? 'generate') !== 'generate') { throw new \InvalidArgumentException('Use the dedicated import or publication endpoint.'); }
                    $payload = $body['payload'] ?? $body;
                    if (!is_array($payload) || !is_array($payload['parameters'] ?? [])) { throw new \InvalidArgumentException('Invalid generation parameters.'); }
                    return self::response((new JobService())->create((int) $hall->center_id, (int) $hall->id, 'generate', [
                        'preset_id' => (string) ($payload['preset_id'] ?? ''), 'parameters' => $payload['parameters'] ?? [],
                    ], get_current_user_id()), 202);
                case 'job':
                case 'retryJob':
                case 'runJob':
                    $job = (new JobService())->status($id);
                    self::center((int) ($job['center_id'] ?? 0));
                    if ($action === 'retryJob') { $job = (new JobService())->retry($id); }
                    if ($action === 'runJob') { (new JobService())->run($id); $job = (new JobService())->status($id); }
                    return self::response($job);
                case 'import':
                    $hall = self::hall((int) $r->get_param('hall_id'));
                    $files = $r->get_file_params();
                    $file = $files['file'] ?? null;
                    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > 134217728) {
                        throw new \InvalidArgumentException('Upload a JSON Lines layout no larger than 128 MiB.');
                    }
                    $stream = fopen($file['tmp_name'], 'rb');
                    if ($stream === false) { throw new \RuntimeException('Import is unavailable.'); }
                    try { $token = (new JobService())->stageImport($stream, get_current_user_id()); }
                    finally { fclose($stream); }
                    return self::response((new JobService())->create((int) $hall->center_id, (int) $hall->id, 'import', ['upload_token' => $token], get_current_user_id()), 202);
                case 'adminLayout': self::layoutScope($id); return self::response($repository->overview($id));
                case 'adminTile': self::layoutScope($id); return self::response($repository->tile($id, (string) $r['tile']));
                case 'adminUnits': self::layoutScope($id); return self::response($repository->units($id, self::filters($r)));
                case 'adminLocate': self::layoutScope($id); return self::response($repository->locate($id, (string) $r['code']));
                case 'commands':
                    self::layoutScope($id);
                    return self::response($repository->commands($id, (int) ($body['revision'] ?? -1), (string) ($body['request_key'] ?? ''), (array) ($body['operations'] ?? [])));
                case 'publish':
                    $layout = self::layoutScope($id);
                    return self::response((new JobService())->create((int) $layout->center_id, (int) $layout->hall_id, 'publish', ['layout_id' => $id], get_current_user_id()), 202);
                case 'export': self::layoutScope($id); self::export($id); break;
                case 'print': self::layoutScope($id); self::printLayout($id, (string) $r['section']); break;
                case 'createSession':
                    $settings = $body['settings'] ?? [];
                    if (!is_array($settings)) { throw new \InvalidArgumentException('Invalid session settings.'); }
                    if (array_key_exists('pricing', $settings) && !current_user_can('pesm_manage_pricing')) { throw new HoldException('permission_denied', 'Pricing permission is required.', 403); }
                    \PESM\Domain\Allocation\Policy::settings($settings);
                    $event = self::event((int) ($body['event_id'] ?? 0));
                    $hall = self::hall((int) ($body['hall_id'] ?? 0));
                    if ((int) $hall->center_id !== (int) $event->center_id) { throw new \InvalidArgumentException('Event and hall must belong to the same center.'); }
                    $session = HallService::createSession((int) $event->id, (int) $hall->id,
                        self::utc((string) ($body['starts_at'] ?? '')), self::utc((string) ($body['sale_starts_at'] ?? '')),
                        self::utc((string) ($body['sale_ends_at'] ?? '')), (int) ($body['unit_price_minor'] ?? 0), 'allocated');
                    if ($settings) { SessionService::configure($session, $settings); }
                    global $wpdb;
                    $provision = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}pesm_venue_jobs WHERE session_id=%d AND kind='provision' ORDER BY id DESC LIMIT 1", $session));
                    return self::response(['session_id' => $session, 'status' => 'draft', 'settings' => SessionService::settings($session), 'job' => $provision ? JobService::status($provision) : null], 201);
                case 'config':
                    $session = self::session($id, true);
                    if ($r->get_method() !== 'GET') {
                        $settings = $body['settings'] ?? $body;
                        if (isset($settings['pricing']) && !current_user_can('pesm_manage_pricing')) { throw new HoldException('permission_denied', 'Pricing permission is required.', 403); }
                        SessionService::configure($id, $settings);
                    }
                    $settings = SessionService::settings($id);
                    return self::response(['settings' => $settings, 'session_id' => $id, 'layout_id' => (int) $session->layout_id, 'hall_id' => (int) $session->hall_id, 'status' => $session->status] + $settings);
                case 'publishSession':
                    self::session($id, true);
                    \PESM\Domain\Event\LifecycleService::change('session', $id, 'published', get_current_user_id());
                    return self::response(['session_id' => $id, 'status' => 'published']);
                case 'block':
                    self::session($id, true);
                    return self::response(SessionService::block($id, (int) ($body['seat_id'] ?? 0), (bool) ($body['blocked'] ?? true), get_current_user_id()));
                case 'cancel':
                    // The service repeats ownership checks on its authoritative line/session data.
                    return self::response(GroupAdministration::cancel($id, (string) ($body['request_key'] ?? ''), get_current_user_id()));
                case 'lines':
                    self::session($id, true);
                    return self::response(GroupAdministration::page($id, (int) $r['cursor'], max(1, min(100, (int) ($r['limit'] ?: 50))), get_current_user_id()));
                case 'reallocate':
                    $units = $body['units'] ?? [];
                    $destination = $body['destination'] ?? (is_array($units) && count($units) === 1 ? $units[0] : null);
                    if (!is_array($destination)) { throw new \InvalidArgumentException('Select one destination group.'); }
                    return self::response(GroupAdministration::reallocate($id, $destination, (string) ($body['request_key'] ?? ''), get_current_user_id()));
                case 'confirmRefund':
                    return self::response(GroupAdministration::confirmRefund($id, (int) ($body['refund_id'] ?? 0), (string) ($body['request_key'] ?? ''), get_current_user_id()));
                case 'denyRefund':
                    return self::response(GroupAdministration::denyRefund($id, (string) ($body['request_key'] ?? ''), get_current_user_id()));
                case 'layout':
                    $session = self::session($id);
                    $overview = $repository->overview((int) $session->layout_id);
                    $settings = SessionService::settings($id);
                    return self::response(array_merge(self::priceRanges($overview, $session, $settings), $settings, ['session_id' => $id, 'unit_price_minor' => (int) $session->unit_price_minor]));
                case 'tile':
                    $session = self::session($id);
                    return self::response($repository->tile((int) $session->layout_id, (string) $r['tile']));
                case 'units':
                    $session = self::session($id);
                    return self::response(\PESM\Domain\Allocation\UnitBrowser::page($id, self::filters($r)));
                case 'locate':
                    $session = self::session($id);
                    return self::response($repository->locate((int) $session->layout_id, (string) $r['code']));
                case 'availability':
                    self::session($id);
                    $sections = self::list($r->get_param('tiles') ?? $r->get_param('sections') ?? $r->get_param('section') ?? []);
                    $since = $r->get_param('since') ?? [];
                    if (is_string($since)) { $since = json_decode($since, true, 8) ?: []; }
                    return self::response(ReservationService::availability($id, $sections, is_array($since) ? $since : []));
                case 'quote':
                    self::session($id);
                    self::limit('venue_quote', 240);
                    return self::response(ReservationService::quote($id, (array) ($body['units'] ?? [])));
                case 'holds':
                    self::session($id);
                    self::limit('venue_holds', 1200);
                    if ($r->get_method() === 'GET') { return self::response(ReservationService::view($id, $r->get_header('X-PESM-Hold-Token'))); }
                    $key = (string) ($body['idempotency_key'] ?? $body['request_key'] ?? '');
                    $units = (array) ($body['units'] ?? []);
                    if ($r->get_method() === 'POST') { return self::response(ReservationService::acquire($id, $units, $key), 201); }
                    return self::response(ReservationService::mutate($id, $r->get_header('X-PESM-Hold-Token'),
                        $r->get_method() === 'DELETE' ? 'release' : (string) ($body['action'] ?? 'replace'), $units, $key));
                case 'checkout':
                    self::session($id);
                    self::limit('venue_checkout', 1200);
                    return self::response(CheckoutService::prepare($id, $r->get_header('X-PESM-Hold-Token'), $body));
                case 'tickets':
                    self::limit('venue_tickets', 240);
                    return self::response(CheckoutService::tickets((int) $r['order_id'], $r->get_header('X-PESM-Guest-Token')));
                case 'recoveryRequest': return self::response(CheckoutService::requestRecovery((int) ($body['order_id'] ?? 0), (string) ($body['email'] ?? '')));
                case 'recoveryVerify': return self::response(CheckoutService::verifyRecovery((int) ($body['order_id'] ?? 0), (string) ($body['code'] ?? '')));
                case 'cartAttach':
                    self::sameOrigin($r);
                    self::session((int) ($body['session_id'] ?? 0));
                    self::limit('venue_cart_attach', 240);
                    return self::response(\PESM\Domain\Allocation\CartService::attach((int) $body['session_id'], $r->get_header('X-PESM-Hold-Token'), (array) ($body['attendees'] ?? []), (array) ($body['ticket_modes'] ?? [])));
                case 'cartPreview':
                    return self::response(\PESM\Domain\Allocation\CartService::preview((string) $r['cart_key'], $r->get_header('X-PESM-Cart-Nonce')));
                case 'cartReplace':
                    self::sameOrigin($r);
                    return self::response(\PESM\Domain\Allocation\CartService::replace((string) ($body['cart_key'] ?? ''), (array) ($body['units'] ?? []), (string) ($body['request_key'] ?? ''), $r->get_header('X-PESM-Cart-Nonce')));
                case 'cartComplete':
                    self::sameOrigin($r);
                    return self::response(\PESM\Domain\Allocation\CartService::complete((string) ($body['cart_key'] ?? ''), $body, $r->get_header('X-PESM-Cart-Nonce')));
                case 'gateLookup':
                    if (!RateLimiter::operator('venue_lookup', get_current_user_id())) { throw new HoldException('rate_limited', 'Too many requests.', 429); }
                    $number = (string) $r['ticket_number'];
                    return self::response(str_starts_with($number, 'A-') ? GateService::lookupNumber($number, get_current_user_id())
                        : (new \PESM\Domain\Gate\CheckinService())->lookupNumber($number, get_current_user_id()));
                case 'gateManual':
                    if (!RateLimiter::operator('venue_manual', get_current_user_id())) { throw new HoldException('rate_limited', 'Too many requests.', 429); }
                    $number = (string) ($body['ticket_number'] ?? '');
                    return self::response(str_starts_with($number, 'A-')
                        ? GateService::manualCheckin($number, get_current_user_id(), (string) ($body['gate'] ?? ''), (string) ($body['request_key'] ?? ''), (int) ($body['quantity'] ?? 1))
                        : (new \PESM\Domain\Gate\CheckinService())->manualCheckin($number, get_current_user_id(), (string) ($body['gate'] ?? '')));
                case 'gate':
                    if (!RateLimiter::operator('venue_gate', get_current_user_id())) { throw new HoldException('rate_limited', 'Too many scan requests.', 429); }
                    $payload = (string) ($body['qr_payload'] ?? '');
                    if (!str_starts_with($payload, 'A.')) {
                        return self::response((new \PESM\Domain\Gate\CheckinService())->scan($payload, get_current_user_id(), (string) ($body['gate'] ?? '')));
                    }
                    return self::response(GateService::scan($payload, get_current_user_id(), (string) ($body['gate'] ?? ''),
                        (string) ($body['request_key'] ?? $body['idempotency_key'] ?? ''), (int) ($body['quantity'] ?? 1)));
            }
            return new WP_Error('venue_route_missing', 'Unknown venue operation.', ['status' => 404]);
        } catch (HoldException $e) {
            return new WP_Error($e->errorCode, $e->getMessage(), ['status' => $e->httpStatus]);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('invalid_venue_request', $e->getMessage(), ['status' => 422]);
        } catch (\Throwable $e) {
            error_log('PESM venue request failed: ' . $action . ' (' . get_class($e) . ')');
            return new WP_Error('venue_request_failed', 'The venue operation could not be completed. Refresh and try again.', ['status' => 409]);
        }
    }

    private static function response(array $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response($data, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function limit(string $scope, int $maximum): void
    {
        if (!RateLimiter::allow($scope, $maximum, 60)) { throw new HoldException('rate_limited', 'Too many requests. Try again shortly.', 429); }
    }

    private static function sameOrigin(WP_REST_Request $request): void
    {
        $origin = $request->get_header('origin');
        if ($origin === '') { return; } // Non-browser clients still require the bearer or private WC nonce.
        $site = wp_parse_url(home_url('/'));
        $incoming = wp_parse_url($origin);
        if (!is_array($site) || !is_array($incoming) ||
            strtolower($incoming['host'] ?? '') !== strtolower($site['host'] ?? '') ||
            ($incoming['scheme'] ?? '') !== ($site['scheme'] ?? '') ||
            (int) ($incoming['port'] ?? (($incoming['scheme'] ?? '') === 'https' ? 443 : 80)) !== (int) ($site['port'] ?? (($site['scheme'] ?? '') === 'https' ? 443 : 80))) {
            throw new HoldException('permission_denied', 'Cart requests must use the site origin.', 403);
        }
    }

    private static function priceRanges(array $overview, object $session, array $settings): array
    {
        global $wpdb;
        $categories = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT section_code,category_code FROM {$wpdb->prefix}pesm_venue_places WHERE layout_id=%d", (int) $session->layout_id), ARRAY_A);
        if (!is_array($categories)) { throw new HoldException('db_unavailable', 'Section prices are unavailable.', 503); }
        $ranges = [];
        foreach ($categories as $row) {
            $section = $row['section_code'];
            $price = \PESM\Domain\Allocation\Policy::price((int) $session->unit_price_minor, $section, $row['category_code'], $settings);
            $ranges[$section] = isset($ranges[$section]) ? [min($ranges[$section][0], $price), max($ranges[$section][1], $price)] : [$price, $price];
        }
        foreach ($overview['sections'] as &$section) {
            $range = $ranges[$section['section_code'] ?? $section['code']] ?? [(int) $session->unit_price_minor, (int) $session->unit_price_minor];
            $section['price_min_minor'] = $range[0]; $section['price_max_minor'] = $range[1];
        }
        unset($section);
        return $overview;
    }

    private static function center(int $id): void
    {
        if ($id < 1 || !Capabilities::centerAllowed($id, get_current_user_id())) { throw new HoldException('permission_denied', 'This center is not accessible.', 403); }
    }

    private static function hall(int $id): object
    {
        global $wpdb;
        $hall = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pesm_halls WHERE id=%d", $id));
        if (!$hall) { throw new HoldException('hall_not_found', 'Hall not found.', 404); }
        self::center((int) $hall->center_id);
        return $hall;
    }

    private static function event(int $id): object
    {
        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare("SELECT id,center_id FROM {$wpdb->prefix}pesm_events WHERE id=%d", $id));
        if (!$event) { throw new HoldException('event_not_found', 'Event not found.', 404); }
        self::center((int) $event->center_id);
        return $event;
    }

    private static function layoutScope(int $id): object
    {
        global $wpdb;
        $layout = $wpdb->get_row($wpdb->prepare("SELECT l.hall_id,h.center_id FROM {$wpdb->prefix}pesm_layout_versions l JOIN {$wpdb->prefix}pesm_halls h ON h.id=l.hall_id WHERE l.id=%d", $id));
        if (!$layout) { throw new HoldException('layout_not_found', 'Layout not found.', 404); }
        self::center((int) $layout->center_id);
        return $layout;
    }

    private static function session(int $id, bool $admin = false): object
    {
        global $wpdb;
        $s = $wpdb->get_row($wpdb->prepare("SELECT s.*,e.center_id,e.status AS event_status FROM {$wpdb->prefix}pesm_sessions s JOIN {$wpdb->prefix}pesm_events e ON e.id=s.event_id WHERE s.id=%d", $id));
        if (!$s || $s->mode !== 'allocated' || (!$admin && ($s->status !== 'published' || $s->event_status !== 'published'))) {
            throw new HoldException('session_not_found', 'Allocated session not found.', 404);
        }
        if ($admin) { self::center((int) $s->center_id); }
        return $s;
    }

    private static function filters(WP_REST_Request $r): array
    {
        $out = [];
        foreach (['section','row','category','type','search','cursor','available'] as $key) {
            $v = $r->get_param($key);
            if ($v !== null && !is_scalar($v)) { throw new \InvalidArgumentException('Invalid inventory filter.'); }
            if ($v !== null) { $out[$key] = (string) $v; }
        }
        $out['limit'] = max(1, min(100, (int) ($r->get_param('limit') ?: 50)));
        return $out;
    }

    private static function list(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($items) || count($items) > 12) { throw new \InvalidArgumentException('Select no more than twelve sections.'); }
        foreach ($items as $item) { if (!is_string($item) || preg_match('/^[A-Za-z0-9_.-]{1,60}$/D', $item) !== 1) { throw new \InvalidArgumentException('Invalid section.'); } }
        return array_values(array_unique($items));
    }

    private static function utc(string $input): string
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:Z|[+-][0-9]{2}:[0-9]{2})?$/D', $input) !== 1) { throw new \InvalidArgumentException('An ISO date or UTC timestamp is required.'); }
        $date = new \DateTimeImmutable($input, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) { throw new \InvalidArgumentException('The date is invalid.'); }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function export(int $id): never
    {
        nocache_headers();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="venue-' . $id . '.jsonl"');
        $stream = fopen('php://output', 'wb');
        (new LayoutRepository())->export($id, $stream);
        fclose($stream);
        exit;
    }

    private static function printLayout(int $id, string $section): never
    {
        $repo = new LayoutRepository();
        $overview = $repo->overview($id);
        $seats = []; $groups = []; $objects = [];
        foreach ($overview['objects'] ?? [] as $object) { $objects[$object['code'] ?? $object['id']] = $object; }
        if ($section !== '') {
            $known = false;
            foreach ($overview['sections'] as $item) { if (($item['section_code'] ?? $item['code']) === $section) { $known = true; } }
            if (!$known) { throw new \InvalidArgumentException('Unknown print section.'); }
            foreach ($overview['tiles'] as $tile) {
                if ($tile['section_code'] !== $section) { continue; }
                $detail = $repo->tile($id, (string) ($tile['tile_code'] ?? $tile['code']));
                array_push($seats, ...array_slice($detail['seats'], 0, 1000 - count($seats)));
                foreach ($detail['groups'] as $group) { $groups[$group['code'] ?? $group['id']] = $group; }
                foreach ($detail['objects'] as $object) { $objects[$object['code'] ?? $object['id']] = $object; }
                if (count($seats) >= 1000) { break; }
            }
            $overview['selected_section'] = $section;
        }
        $overview['locale'] = \PESM\Support\I18n::locale();
        $html = \PESM\Support\VenuePrint::render($overview, $seats, array_values($groups), array_values($objects), 'Venue #' . $id);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        header('Referrer-Policy: no-referrer');
        echo $html;
        exit;
    }
}
