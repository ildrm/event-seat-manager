<?php

declare(strict_types=1);

namespace PESM\Http;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldService;
use PESM\Domain\Booking\CapacityService;
use PESM\Domain\Booking\SeatSuggestions;
use PESM\Security\RateLimiter;
use PESM\Domain\Checkout\CheckoutService;
use PESM\Domain\Pricing\DiscountService;
use PESM\Domain\Ticket\TicketService;
use PESM\Domain\Ticket\OwnershipTransferService;
use PESM\Domain\Ticket\SeatTransferService;
use PESM\Domain\Ticket\BundleService;
use PESM\Domain\Ticket\AttendeeChangeService;
use PESM\Domain\Ticket\FreeTicketCancellationService;
use PESM\Domain\Ticket\RefundReviewService;
use PESM\Domain\Gate\CheckinService;
use PESM\Domain\Reconciliation\ReconciliationService;
use PESM\Domain\Guest\GuestAccessService;
use PESM\Domain\Reporting\AttendanceReport;
use PESM\Domain\Reporting\SeatStatusReport;
use PESM\Domain\Reporting\SalesInsights;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Public read data excludes customer information; public mutations are rate-limited. */
final class RestController
{
    public static function register(): void
    {
        foreach (['/events' => 'events', '/events/(?P<id>\d+)' => 'event',
            '/events/(?P<id>\d+)/sessions' => 'sessions',
            '/sessions/(?P<id>\d+)/layout' => 'layout',
            '/sessions/(?P<id>\d+)/availability' => 'availability'] as $route => $method) {
            register_rest_route('pesm/v1', $route, [
                'methods' => 'GET', 'callback' => [self::class, $method],
                'permission_callback' => '__return_true',
                'args' => str_contains($route, '(?P<id>')
                    ? ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]] : [],
            ]);
        }
        register_rest_route('pesm/v1', '/sessions/(?P<id>\d+)/suggest', [
            'methods' => 'GET', 'callback' => [self::class, 'suggest'], 'permission_callback' => '__return_true',
            'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                       'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'required' => true]],
        ]);
        register_rest_route('pesm/v1', '/holds', [
            ['methods' => 'POST', 'callback' => [self::class, 'hold'], 'permission_callback' => '__return_true',
                'args' => [
                    'session_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                    'seat_ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer', 'minimum' => 1],
                        'minItems' => 1, 'maxItems' => HoldService::MAX_SEATS],
                    'idempotency_key' => ['type' => 'string', 'required' => true, 'minLength' => 32, 'maxLength' => 128],
                ]],
            ['methods' => 'PATCH', 'callback' => [self::class, 'modifyHold'], 'permission_callback' => '__return_true',
                'args' => [
                    'session_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                    'action' => ['type' => 'string', 'enum' => ['replace', 'renew'], 'required' => true],
                    'idempotency_key' => ['type' => 'string', 'required' => true, 'minLength' => 32, 'maxLength' => 128],
                    'seat_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
                        'minItems' => 1, 'maxItems' => HoldService::MAX_SEATS],
                ]],
            ['methods' => 'DELETE', 'callback' => [self::class, 'release'], 'permission_callback' => '__return_true',
                'args' => ['session_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]]],
        ]);
        register_rest_route('pesm/v1', '/sessions/(?P<id>\d+)/capacity', [
            'methods' => 'GET', 'callback' => [self::class, 'capacityAvailability'],
            'permission_callback' => '__return_true',
            'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
        register_rest_route('pesm/v1', '/capacity/holds', [
            ['methods' => 'POST', 'callback' => [self::class, 'capacityHold'],
                'permission_callback' => '__return_true',
                'args' => [
                    'session_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'required' => true],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 128, 'required' => true],
                ]],
            ['methods' => 'DELETE', 'callback' => [self::class, 'capacityRelease'],
                'permission_callback' => '__return_true',
                'args' => ['session_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]]],
        ]);
        register_rest_route('pesm/v1', '/checkout/discount-quote', [
            'methods' => 'POST', 'callback' => [self::class, 'discountQuote'],
            'permission_callback' => '__return_true',
            'args' => [
                'session_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'coupon_code'=>['type'=>'string','minLength'=>4,'maxLength'=>32,'required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/discounts', [
            'methods' => 'POST', 'callback' => [self::class, 'createDiscount'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_manage_pricing'),
            'args' => [
                'event_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'session_id'=>['type'=>'integer','minimum'=>0],
                'code'=>['type'=>'string','minLength'=>4,'maxLength'=>32,'required'=>true],
                'kind'=>['type'=>'string','enum'=>['percent','fixed'],'required'=>true],
                'value'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'zone_label'=>['type'=>'string','maxLength'=>60],
                'min_order_minor'=>['type'=>'integer','minimum'=>0],
                'max_uses'=>['type'=>'integer','minimum'=>0],
                'starts_at'=>['type'=>'string','required'=>true],
                'ends_at'=>['type'=>'string','required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/checkout/prepare', [
            'methods' => 'POST', 'callback' => [self::class, 'checkout'], 'permission_callback' => '__return_true',
            'args' => [
                'session_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'first_name' => ['type' => 'string', 'required' => true, 'maxLength' => 90],
                'last_name' => ['type' => 'string', 'required' => true, 'maxLength' => 90],
                'phone' => ['type' => 'string', 'required' => true, 'maxLength' => 30],
                'email' => ['type' => 'string', 'maxLength' => 190],
                'coupon_code' => ['type' => 'string', 'maxLength' => 32],
                'attendees' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20,
                    'items' => ['type' => 'object', 'additionalProperties' => false,
                        'properties' => [
                            'seat_id' => ['type' => 'integer', 'minimum' => 1],
                            'first_name' => ['type' => 'string', 'maxLength' => 90],
                            'last_name' => ['type' => 'string', 'maxLength' => 90],
                        ], 'required' => ['first_name', 'last_name']]],
            ],
        ]);
        register_rest_route('pesm/v1', '/guest-access/request', [
            'methods'=>'POST', 'callback'=>[self::class, 'guestRecoveryRequest'], 'permission_callback'=>'__return_true',
            'args'=>['order_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                     'email'=>['type'=>'string','format'=>'email','maxLength'=>190,'required'=>true]],
        ]);
        register_rest_route('pesm/v1', '/guest-access/verify', [
            'methods'=>'POST', 'callback'=>[self::class, 'guestRecoveryVerify'], 'permission_callback'=>'__return_true',
            'args'=>['order_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                     'code'=>['type'=>'string','pattern'=>'^[0-9]{8}$','required'=>true]],
        ]);
        register_rest_route('pesm/v1', '/admin/events/(?P<id>\d+)/change', [
            'methods' => 'POST', 'callback' => [self::class, 'eventChange'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_manage_events') || current_user_can('pesm_manage_sessions'),
            'args' => [
                'id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'entity'=>['type'=>'string','enum'=>['event','session'],'required'=>true],
                'action'=>['type'=>'string','enum'=>['cancel','postpone'],'required'=>true],
                'reason'=>['type'=>'string','minLength'=>10,'maxLength'=>500,'required'=>true],
                'request_key'=>['type'=>'string','minLength'=>32,'maxLength'=>128,'required'=>true],
                'new_starts_at'=>['type'=>'string'],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/event-changes/(?P<id>\d+)/cases', [
            'methods'=>'GET','callback'=>[self::class,'eventChangeCases'],
            'permission_callback'=>static fn(): bool=>current_user_can('pesm_manage_refunds'),
            'args'=>['id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                    'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200]],
        ]);
        register_rest_route('pesm/v1', '/admin/reports/seat-status', [
            'methods' => 'GET',
            'callback' => [self::class, 'adminSeatStatus'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_view_reports'),
            'args' => [
                'center_id' => ['type'=>'integer','required'=>true,'minimum'=>1],
                'session_id' => ['type'=>'integer','required'=>true,'minimum'=>1],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/reports/attendance', [
            'methods'=>'GET', 'callback'=>[self::class, 'attendance'],
            'permission_callback'=>static fn(): bool => current_user_can('pesm_view_reports'),
            'args'=>['center_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                     'session_id'=>['type'=>'integer','minimum'=>0]],
        ]);
        register_rest_route('pesm/v1', '/admin/reports/insights', [
            'methods'=>'GET', 'callback'=>[self::class, 'salesInsights'],
            'permission_callback'=>static fn(): bool => current_user_can('pesm_view_reports'),
            'args'=>[
                'center_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'days'=>['type'=>'integer','minimum'=>7,'maximum'=>90,'default'=>30],
                'session_id'=>['type'=>'integer','minimum'=>0,'default'=>0],
            ],
        ]);
        register_rest_route('pesm/v1', '/tickets/me', [
            'methods' => 'GET', 'callback' => [self::class, 'tickets'], 'permission_callback' => '__return_true',
            'args' => ['order_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
        register_rest_route('pesm/v1', '/admin/reconciliation', [
            'methods' => 'GET', 'callback' => [self::class, 'reconciliation'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_view_orders'),
            'args' => ['center_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                       'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ]);
        register_rest_route('pesm/v1', '/admin/reconciliation/retry', [
            'methods' => 'POST', 'callback' => [self::class, 'retryReconciliation'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_manage_refunds'),
            'args' => ['center_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                       'order_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
        register_rest_route('pesm/v1', '/admin/reconciliation/retry-paid-general', [
            'methods' => 'POST', 'callback' => [self::class, 'retryPaidAdmission'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_manage_refunds'),
            'args' => [
                'center_id' => ['type'=>'integer','minimum'=>1,'required'=>true],
                'order_id' => ['type'=>'integer','minimum'=>1,'required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/reconciliation/retry-free-general', [
            'methods' => 'POST', 'callback' => [self::class, 'retryFreeAdmission'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_manage_refunds'),
            'args' => [
                'center_id' => ['type'=>'integer','minimum'=>1,'required'=>true],
                'order_id' => ['type'=>'integer','minimum'=>1,'required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/ticket-ownership/request', [
            'methods'=>'POST','callback'=>[self::class,'requestOwnershipTransfer'],'permission_callback'=>'__return_true',
            'args'=>[
                'type'=>['type'=>'string','enum'=>['seated','general'],'required'=>true],
                'ticket_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'recipient_email'=>['type'=>'string','format'=>'email','maxLength'=>190,'required'=>true],
                'recipient_name'=>['type'=>'string','minLength'=>1,'maxLength'=>190,'required'=>true],
                'request_key'=>['type'=>'string','pattern'=>'^[a-f0-9]{32,128}$','required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/ticket-ownership/accept', [
            'methods'=>'POST','callback'=>[self::class,'acceptOwnershipTransfer'],'permission_callback'=>'__return_true',
            'args'=>['transfer_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                     'code'=>['type'=>'string','pattern'=>'^[0-9]{8}$','required'=>true]],
        ]);
        register_rest_route('pesm/v1', '/ticket-ownership/view', [
            'methods'=>'POST','callback'=>[self::class,'viewOwnedTicket'],'permission_callback'=>'__return_true',
        ]);
        register_rest_route('pesm/v1', '/admin/tickets/(?P<id>\d+)/attendee', [
            'methods' => 'PATCH', 'callback' => [self::class, 'changeAttendee'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_issue_tickets'),
            'args' => [
                'id' => ['type'=>'integer','minimum'=>1,'required'=>true],
                'type' => ['type'=>'string','enum'=>['seated','general'],'required'=>true],
                'attendee_name' => ['type'=>'string','minLength'=>1,'maxLength'=>190,'required'=>true],
                'request_key' => ['type'=>'string','pattern'=>'^[a-f0-9]{32,128}$','required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/tickets/(?P<id>\d+)/refund-request', [
            'methods'=>'POST', 'callback'=>[self::class,'requestTicketRefund'],
            'permission_callback'=>static fn(): bool => current_user_can('pesm_manage_refunds'),
            'args'=>[
                'id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'reason'=>['type'=>'string','minLength'=>10,'maxLength'=>500,'required'=>true],
                'request_key'=>['type'=>'string','pattern'=>'^[a-f0-9]{32,128}$','required'=>true],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/tickets/(?P<id>\d+)/refund-reconcile', [
            'methods'=>'POST', 'callback'=>[self::class,'reconcileTicketRefund'],
            'permission_callback'=>static fn(): bool => current_user_can('pesm_manage_refunds'),
            'args'=>['id'=>['type'=>'integer','minimum'=>1,'required'=>true]],
        ]);
        register_rest_route('pesm/v1', '/admin/tickets/(?P<id>\d+)/cancel-free', [
            'methods' => 'POST', 'callback' => [self::class, 'cancelFreeTicket'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_manage_refunds'),
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);
        register_rest_route('pesm/v1', '/admin/tickets/(?P<id>\d+)/transfers', [
            'methods' => 'GET', 'callback' => [self::class, 'ticketTransferHistory'],
            'permission_callback' => static fn(): bool =>
                current_user_can('pesm_view_orders') || current_user_can('pesm_issue_tickets') ||
                current_user_can('pesm_manage_refunds'),
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);
        register_rest_route('pesm/v1', '/admin/tickets/(?P<id>\d+)/transfer-seat', [
            'methods' => 'POST', 'callback' => [self::class, 'transferSeat'],
            'permission_callback' => static fn(): bool =>
                current_user_can('pesm_issue_tickets') || current_user_can('pesm_manage_refunds'),
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'destination_seat_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'request_key' => ['type' => 'string', 'required' => true, 'minLength' => 32, 'maxLength' => 128],
            ],
        ]);
        // Administrative preview: group existing paid tickets for multiple sessions.
        register_rest_route('pesm/v1', '/admin/ticket-bundles', [
            'methods'=>'POST', 'callback'=>[self::class,'createTicketBundle'],
            'permission_callback'=>static fn(): bool=>current_user_can('pesm_issue_tickets'),
            'args'=>[
                'ticket_ids'=>['type'=>'array','required'=>true,'minItems'=>2,'maxItems'=>8,
                    'items'=>['type'=>'integer','minimum'=>1]],
                'request_key'=>['type'=>'string','required'=>true,'minLength'=>32,'maxLength'=>128],
            ],
        ]);
        register_rest_route('pesm/v1', '/admin/ticket-bundles/(?P<id>\d+)', [
            'methods'=>'GET', 'callback'=>[self::class,'viewTicketBundle'],
            'permission_callback'=>static fn(): bool=>current_user_can('pesm_issue_tickets'),
            'args'=>['id'=>['type'=>'integer','minimum'=>1,'required'=>true]],
        ]);
        register_rest_route('pesm/v1', '/gate/lookup', [
            'methods' => 'POST', 'callback' => [self::class, 'gateLookup'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_scan_tickets'),
            'args' => ['ticket_number' => ['type'=>'string', 'required'=>true, 'pattern'=>'^P-[A-F0-9]{16}$']],
        ]);
        register_rest_route('pesm/v1', '/gate/manual-check-in', [
            'methods' => 'POST', 'callback' => [self::class, 'gateManual'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_scan_tickets'),
            'args' => ['ticket_number' => ['type'=>'string', 'required'=>true, 'pattern'=>'^P-[A-F0-9]{16}$'],
                       'gate' => ['type'=>'string','maxLength'=>100]],
        ]);
        register_rest_route('pesm/v1', '/gate/check-in', [
            'methods' => 'POST', 'callback' => [self::class, 'checkin'],
            'permission_callback' => static fn(): bool => current_user_can('pesm_scan_tickets'),
            'args' => [
                'qr_payload' => ['type' => 'string', 'minLength' => 97, 'maxLength' => 97, 'required' => true],
                'gate' => ['type' => 'string', 'maxLength' => 100],
            ],
        ]);
    }

    public static function guestRecoveryRequest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new GuestAccessService())->requestCode(
                (int) $request['order_id'], (string) $request['email']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function guestRecoveryVerify(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new GuestAccessService())->verifyCode(
                (int) $request['order_id'], (string) $request['code']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function eventChange(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $service = new \PESM\Domain\Event\EventChangeService();
            $result = (string)$request['action'] === 'postpone'
                ? $service->postponeSession((int)$request['id'], (string)$request['new_starts_at'],
                    (string)$request['reason'], (string)$request['request_key'], get_current_user_id())
                : $service->cancel((string)$request['entity'], (int)$request['id'],
                    (string)$request['reason'], (string)$request['request_key'], get_current_user_id());
            return self::publicResponse($result);
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function eventChangeCases(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new \PESM\Domain\Event\EventChangeService())->cases(
                (int)$request['id'], get_current_user_id(), (int)($request['limit'] ?: 100)));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function salesInsights(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new SalesInsights())->summary(
                get_current_user_id(), (int)$request['center_id'],
                (int)$request['days'], (int)$request['session_id']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function attendance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new AttendanceReport())->summary(
                get_current_user_id(), (int) $request['center_id'], (int) ($request['session_id'] ?: 0)
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function reconciliation(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new ReconciliationService())->anomalies(
                get_current_user_id(), (int) $request['center_id'], (int) ($request['limit'] ?: 100),
                ['seated' => (int) $request['before_seated'], 'general' => (int) $request['before_general'], 'allocated' => (int) $request['after_allocated']]
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function retryReconciliation(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse(['result' => (new ReconciliationService())->retryPaidOrder(
                get_current_user_id(), (int) $request['center_id'], (int) $request['order_id']
            )]);
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function retryPaidAdmission(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_ga_paid_retry')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse(['result' => (new ReconciliationService())->retryPaidAdmission(
                get_current_user_id(), (int) $request['center_id'], (int) $request['order_id']
            )]);
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function retryFreeAdmission(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_ga_retry')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse(['result' => (new ReconciliationService())->retryFreeAdmission(
                get_current_user_id(), (int) $request['center_id'], (int) $request['order_id']
            )]);
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function discountQuote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('discount_quote', 25, 60)) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بررسی کد تخفیف زیاد است.'), 429);
        }
        try {
            return self::publicResponse((new DiscountService())->preview(
                (int)$request['session_id'], (string)$request->get_header('X-PESM-Hold-Token'),
                (string)$request['coupon_code']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function createDiscount(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $keys = ['event_id','session_id','code','kind','value','zone_label',
                'min_order_minor','max_uses','starts_at','ends_at'];
            $input = [];
            foreach ($keys as $key) { $input[$key] = $request->get_param($key); }
            return self::publicResponse((new DiscountService())->create($input, get_current_user_id()));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function checkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('checkout')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            $data = (new CheckoutService())->prepare(
                (int) $request['session_id'], (string) $request->get_header('X-PESM-Hold-Token'),
                ['first_name' => (string) $request['first_name'], 'last_name' => (string) $request['last_name'],
                 'phone' => (string) $request['phone'], 'email' => (string) $request['email'],
                 'attendees' => $request->get_param('attendees'),
                 'coupon_code' => (string) ($request->get_param('coupon_code') ?? '')]
            );
            return self::publicResponse($data);
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function tickets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('tickets')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new TicketService())->forGuest(
                (int) $request['order_id'], (string) $request->get_header('X-PESM-Guest-Token')
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function requestOwnershipTransfer(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('owner_transfer_request', 4, 3600)) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌های زیادی ثبت شده است.'), 429);
        }
        try {
            return self::publicResponse((new OwnershipTransferService())->request(
                (string) $request['type'], (int) $request['ticket_id'],
                (string) $request->get_header('X-PESM-Guest-Token'),
                (string) $request['recipient_email'], (string) $request['recipient_name'],
                (string) $request['request_key']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function acceptOwnershipTransfer(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('owner_transfer_accept', 8, 900)) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌های زیادی ثبت شده است.'), 429);
        }
        try {
            return self::publicResponse((new OwnershipTransferService())->accept(
                (int) $request['transfer_id'], (string) $request['code']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function viewOwnedTicket(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('owner_transfer_view', 20, 900)) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌های زیادی ثبت شده است.'), 429);
        }
        try {
            return self::publicResponse((new OwnershipTransferService())->view(
                (string) $request->get_header('X-PESM-Ownership-Token')
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function cancelFreeTicket(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_free_cancel')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new FreeTicketCancellationService())->cancel(
                (int) $request['id'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function requestTicketRefund(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_refund_request')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new RefundReviewService())->request(
                (int)$request['id'], (string)$request['reason'],
                (string)$request['request_key'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function reconcileTicketRefund(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_refund_reconcile')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new RefundReviewService())->reconcile(
                (int)$request['id'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function changeAttendee(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_attendee_change')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new AttendeeChangeService())->change(
                (string) $request['type'], (int) $request['id'],
                (string) $request['attendee_name'], (string) $request['request_key'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function ticketTransferHistory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new SeatTransferService())->history(
                (int) $request['id'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function transferSeat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_transfer')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new SeatTransferService())->transfer(
                (int) $request['id'], (int) $request['destination_seat_id'],
                (string) $request['request_key'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function createTicketBundle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('admin_bundle_create')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new BundleService())->create(
                (array)$request['ticket_ids'], (string)$request['request_key'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function viewTicketBundle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new BundleService())->view((int)$request['id'], get_current_user_id()));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function gateLookup(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::operator('gate_lookup', get_current_user_id())) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new CheckinService())->lookupNumber(
                (string) $request['ticket_number'], get_current_user_id()
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function gateManual(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::operator('gate_manual', get_current_user_id())) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new CheckinService())->manualCheckin(
                (string) $request['ticket_number'], get_current_user_id(), (string) $request['gate']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function checkin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::operator('gate', get_current_user_id())) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new CheckinService())->scan(
                (string) $request['qr_payload'], get_current_user_id(), (string) $request['gate']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function events(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $events = $wpdb->get_results("SELECT id,center_id,title FROM {$p}events WHERE status='published' ORDER BY id DESC LIMIT 100", ARRAY_A);
        if (!is_array($events)) {
            return self::error('db_unavailable', \PESM\Support\I18n::text('دریافت فهرست رویدادها ممکن نشد.'), 503);
        }
        return self::publicResponse(['events' => $events]);
    }

    public static function event(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT id,center_id,title FROM {$p}events WHERE id=%d AND status='published'", (int) $request['id']
        ), ARRAY_A);
        return $event ? self::publicResponse(['event' => $event])
            : self::error('invalid_event', \PESM\Support\I18n::text('رویداد منتشرشده پیدا نشد.'), 404);
    }

    public static function sessions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $id = (int) $request['id'];
        if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}events WHERE id=%d AND status='published'", $id))) {
            return self::error('invalid_event', \PESM\Support\I18n::text('رویداد منتشرشده پیدا نشد.'), 404);
        }
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT id,mode,starts_at,sale_starts_at,sale_ends_at,unit_price_minor FROM {$p}sessions
             WHERE event_id=%d AND status='published' ORDER BY starts_at,id LIMIT 100", $id
        ), ARRAY_A);
        if (!is_array($sessions)) {
            return self::error('db_unavailable', \PESM\Support\I18n::text('دریافت جلسات ممکن نشد.'), 503);
        }
        return self::publicResponse(['event_id' => $id, 'sessions' => $sessions]);
    }

    private static function published(int $sessionId): bool
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$p}sessions s JOIN {$p}events e ON e.id = s.event_id
             WHERE s.id = %d AND s.status = 'published' AND e.status = 'published'", $sessionId
        ));
    }

    public static function adminSeatStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return self::publicResponse((new SeatStatusReport())->snapshot(
                get_current_user_id(), (int)$request['center_id'], (int)$request['session_id']
            ));
        } catch (HoldException $e) {
            return self::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public static function layout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $sessionId = (int) $request['id'];
        if (!self::published($sessionId)) {
            return self::error('invalid_session', \PESM\Support\I18n::text('جلسه منتشرشده‌ای با این شناسه وجود ندارد.'), 404);
        }
        $p = $wpdb->prefix . 'pesm_';
        if ((string) $wpdb->get_var($wpdb->prepare(
            "SELECT mode FROM {$p}sessions WHERE id=%d", $sessionId
        )) !== 'seated') {
            return self::error('session_without_seats', \PESM\Support\I18n::text('این جلسه انتخاب صندلی ندارد.'), 409);
        }
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT seat.id,seat.seat_code,seat.row_label,seat.seat_number,seat.zone_label,seat.pos_x,seat.pos_y
             FROM {$p}sessions s JOIN {$p}seats seat ON seat.layout_id = s.layout_id
             WHERE s.id = %d ORDER BY seat.id", $sessionId
        ), ARRAY_A);
        if (!is_array($items)) {
            return self::error('db_unavailable', \PESM\Support\I18n::text('نقشه صندلی‌ها دریافت نشد.'), 503);
        }
        $objects = $wpdb->get_results($wpdb->prepare(
            "SELECT o.object_code AS id,o.object_type AS type,o.pos_x AS x,o.pos_y AS y,
                    o.width,o.height,o.label
             FROM {$p}sessions s JOIN {$p}layout_objects o ON o.layout_id=s.layout_id
             WHERE s.id=%d ORDER BY o.id LIMIT 500", $sessionId
        ), ARRAY_A);
        if (!is_array($objects)) {
            return self::error('db_unavailable', \PESM\Support\I18n::text('دریافت اشیای سالن ممکن نشد.'), 503);
        }
        $objects = array_map(static function (array $row): array {
            foreach (['x','y','width','height'] as $name) { $row[$name] = (int) $row[$name]; }
            return $row;
        }, $objects);
        $unitPriceMinor = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT unit_price_minor FROM {$p}sessions WHERE id=%d", $sessionId
        ));
        return self::publicResponse([
            'session_id' => $sessionId, 'seats' => $items, 'objects' => $objects,
            'unit_price_minor' => $unitPriceMinor,
            'rules' => (array) $wpdb->get_row($wpdb->prepare(
                "SELECT require_adjacent,avoid_single_gap FROM {$p}sessions WHERE id=%d", $sessionId), ARRAY_A),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'currency_decimals' => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0,
        ]);
    }

    public static function availability(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $sessionId = (int) $request['id'];
        if (!self::published($sessionId)) {
            return self::error('invalid_session', \PESM\Support\I18n::text('جلسه منتشرشده‌ای با این شناسه وجود ندارد.'), 404);
        }
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->get_var($wpdb->prepare("SELECT mode FROM {$p}sessions WHERE id=%d", $sessionId)) === 'allocated') {
            return self::error('layout_requires_v2', 'Use the bounded venue availability API.', 409);
        }
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT seat_id,
             CASE WHEN status IN ('held','payment_pending') AND expires_at <= UTC_TIMESTAMP() THEN 'available' ELSE status END AS status
             FROM {$p}session_seats WHERE session_id = %d ORDER BY seat_id", $sessionId
        ), ARRAY_A);
        if (!is_array($items)) {
            return self::error('db_unavailable', \PESM\Support\I18n::text('موجودی صندلی‌ها دریافت نشد.'), 503);
        }
        $counts = array_count_values(array_column($items, 'status'));
        return self::publicResponse([
            'session_id' => $sessionId, 'availability' => $items,
            'counts' => $counts, 'server_now_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public static function suggest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('seat_suggest')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('تعداد درخواست‌ها بیش از حد مجاز است.'), 429);
        }
        $sessionId = (int) $request['id'];
        if (!self::published($sessionId)) {
            return self::error('invalid_session', \PESM\Support\I18n::text('جلسه منتشرشده وجود ندارد.'), 404);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT require_adjacent,avoid_single_gap,starts_at,sale_starts_at,sale_ends_at,mode
             FROM {$p}sessions WHERE id=%d", $sessionId));
        $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!$session || !$now) { return self::error('db_unavailable', \PESM\Support\I18n::text('ارتباط پایگاه داده در دسترس نیست.'), 503); }
        if (($session->mode ?? 'seated') === 'allocated') { return self::error('layout_requires_v2', 'Use the section-based venue browser.', 409); }
        if ($session->sale_starts_at > $now || $session->sale_ends_at <= $now || $session->starts_at <= $now) {
            return self::error('event_closed', \PESM\Support\I18n::text('زمان فروش این جلسه فعال نیست.'), 409);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT inv.seat_id,inv.status,inv.expires_at,inv.hold_id,seat.row_label,seat.zone_label,seat.pos_x,seat.pos_y
             FROM {$p}session_seats inv JOIN {$p}seats seat ON seat.id=inv.seat_id
             WHERE inv.session_id=%d ORDER BY inv.seat_id", $sessionId));
        if (!is_array($rows)) { return self::error('db_unavailable', \PESM\Support\I18n::text('دریافت نقشه امکان‌پذیر نیست.'), 503); }
        try {
            return self::publicResponse([
                'session_id' => $sessionId, 'suggestions' => SeatSuggestions::recommend(
                    \PESM\Domain\Booking\SeatingRules::fromObjects($rows), (int) $request['count'],
                    $now, (bool) $session->avoid_single_gap),
                'guaranteed' => false, 'message' => \PESM\Support\I18n::text('این پیشنهاد موجودی را نگه نمی‌دارد؛ ثبت قطعی به درخواست رزرو نیاز دارد.'),
            ]);
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function capacityAvailability(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try { return self::publicResponse((new CapacityService())->availability((int) $request['id'])); }
        catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function capacityHold(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('capacity_hold')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌ها بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new CapacityService())->acquire(
                (int) $request['session_id'], (int) $request['quantity'], (string) $request['idempotency_key']
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function capacityRelease(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('capacity_release')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌ها بیش از حد مجاز است.'), 429);
        }
        try {
            return self::publicResponse((new CapacityService())->release(
                (int) $request['session_id'], (string) $request->get_header('X-PESM-Hold-Token')
            ));
        } catch (HoldException $e) { return self::error($e->errorCode, $e->getMessage(), $e->httpStatus); }
    }

    public static function hold(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('hold')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌ها بیش از حد مجاز است؛ بعداً تلاش کنید.'), 429);
        }
        try {
            $seats = $request['seat_ids'];
            $data = (new HoldService())->acquire(
                (int) $request['session_id'], is_array($seats) ? $seats : [], (string) $request['idempotency_key']
            );
            return self::publicResponse($data);
        } catch (HoldException $e) {
            return self::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public static function modifyHold(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('hold_modify')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌ها بیش از حد مجاز است.'), 429);
        }
        try {
            $action = (string) $request['action'];
            $seats = $request['seat_ids'];
            if ($action === 'replace' && !is_array($seats)) {
                return self::error('invalid_selection', \PESM\Support\I18n::text('صندلی‌های جایگزین را وارد کنید.'), 422);
            }
            return self::publicResponse((new HoldService())->modify(
                (int) $request['session_id'], (string) $request->get_header('X-PESM-Hold-Token'),
                (string) $request['idempotency_key'], $action === 'renew' ? null : $seats
            ));
        } catch (HoldException $e) {
            return self::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public static function release(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!RateLimiter::allow('release')) {
            return self::error('rate_limited', \PESM\Support\I18n::text('درخواست‌ها بیش از حد مجاز است؛ بعداً تلاش کنید.'), 429);
        }
        try {
            $data = (new HoldService())->release(
                (int) $request['session_id'], (string) $request->get_header('X-PESM-Hold-Token')
            );
            return self::publicResponse($data);
        } catch (HoldException $e) {
            return self::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /** @param array<string,mixed> $data */
    private static function publicResponse(array $data): WP_REST_Response
    {
        $response = new WP_REST_Response($data, 200);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private static function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
