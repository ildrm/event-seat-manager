<?php

declare(strict_types=1);

namespace PESM\Domain\Guest;

use PESM\Domain\Booking\HoldException;
use PESM\Security\RateLimiter;
use Throwable;

/**
 * Optional email recovery: never grants a WordPress account and never returns a
 * live guest token to an unauthenticated request without a verified mailbox code.
 */
final class GuestAccessService
{
    public const GENERIC_MESSAGE = 'اگر شماره سفارش و ایمیل مطابقت داشته باشند، کد بازیابی ارسال می‌شود.';

    /** @return array{message:string} */
    public function requestCode(int $orderId, string $email): array
    {
        if (!RateLimiter::allow('guest_recovery_request', 5, 900)) {
            throw new HoldException('rate_limited', \PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.'), 429);
        }
        $result = ['message' => \PESM\Support\I18n::text(self::GENERIC_MESSAGE)];
        if ($orderId < 1 || !is_email($email) || !function_exists('wc_get_order')) {
            return $result;
        }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            !hash_equals(strtolower((string) $order->get_billing_email()), strtolower(trim($email)))) {
            return $result;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_guest_recovery_requests';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('سامانه بازیابی در دسترس نیست.'), 503);
        }
        try {
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id=%d FOR UPDATE", $orderId));
            if ($existing && !GuestAccessPolicy::canResend((string) $existing->requested_at, $now)) {
                $wpdb->query('COMMIT');
                return $result;
            }
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $hash = GuestAccessPolicy::codeHash($orderId, $code, wp_salt('secure_auth'));
            $expiry = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + GuestAccessPolicy::CODE_TTL_SECONDS);
            $ok = $existing
                ? $wpdb->update($table, ['code_hash'=>$hash, 'attempts'=>0, 'expires_at'=>$expiry, 'requested_at'=>$now], ['order_id'=>$orderId])
                : $wpdb->insert($table, ['order_id'=>$orderId, 'code_hash'=>$hash, 'attempts'=>0, 'expires_at'=>$expiry, 'requested_at'=>$now]);
            if ($ok !== 1 || $wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Could not record recovery code.');
            }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('سامانه بازیابی در دسترس نیست.'), 503);
        }
        // Do not put OTP in HTTP response, URLs or audit logs.
        $sent = wp_mail((string) $order->get_billing_email(), \PESM\Support\I18n::text('کد بازیابی بلیت رویداد'),
            sprintf(\PESM\Support\I18n::text("کد بازیابی سفارش %1\$d: %2\$s\nاعتبار کد: ۱۰ دقیقه. این کد را در اختیار دیگران قرار ندهید."), $orderId, $code));
        if (!$sent) {
            // Invalidate a code that could not be delivered; never leak mail status or existence.
            $wpdb->delete($table, ['order_id'=>$orderId, 'code_hash'=>$hash]);
        }
        return $result;
    }

    /** @return array{guest_access_token:string,expires_in:int} */
    public function verifyCode(int $orderId, string $code): array
    {
        if ($orderId < 1 || !GuestAccessPolicy::validateCode($code)) {
            throw new HoldException('invalid_code', \PESM\Support\I18n::text('کد بازیابی معتبر نیست.'), 422);
        }
        if (!RateLimiter::allow('guest_recovery_verify', 15, 900)) {
            throw new HoldException('rate_limited', \PESM\Support\I18n::text('تعداد تلاش برای بازیابی بیش از حد مجاز است.'), 429);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('سامانه بازیابی در دسترس نیست.'), 503);
        }
        try {
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}guest_recovery_requests WHERE order_id=%d FOR UPDATE", $orderId
            ));
            if (!$row || !GuestAccessPolicy::canVerify((int) $row->attempts, (string) $row->expires_at, $now)) {
                $wpdb->query('COMMIT');
                throw new HoldException('invalid_code', \PESM\Support\I18n::text('کد بازیابی منقضی یا نامعتبر است.'), 403);
            }
            $provided = GuestAccessPolicy::codeHash($orderId, $code, wp_salt('secure_auth'));
            if (!hash_equals((string) $row->code_hash, $provided)) {
                if ($wpdb->query($wpdb->prepare(
                    "UPDATE {$p}guest_recovery_requests SET attempts=attempts+1 WHERE order_id=%d", $orderId
                )) !== 1 || $wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Could not update attempts.');
                }
                throw new HoldException('invalid_code', \PESM\Support\I18n::text('کد بازیابی منقضی یا نامعتبر است.'), 403);
            }
            $token = bin2hex(random_bytes(32));
            $expiry = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + GuestAccessPolicy::TOKEN_TTL_SECONDS);
            if ($wpdb->insert($p . 'guest_access_tokens', [
                'order_id'=>$orderId, 'token_hash'=>hash('sha256', $token),
                'expires_at'=>$expiry, 'created_at'=>$now,
            ]) !== 1 || $wpdb->delete($p . 'guest_recovery_requests', ['order_id'=>$orderId]) !== 1 ||
                $wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Could not create guest access token.');
            }
            return ['guest_access_token'=>$token, 'expires_in'=>GuestAccessPolicy::TOKEN_TTL_SECONDS];
        } catch (HoldException $e) {
            // An explicit invalid-code error has already COMMITted its attempt counter.
            if ($e->errorCode !== 'invalid_code') { $wpdb->query('ROLLBACK'); }
            throw $e;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('بازیابی در دسترس نیست.'), 503);
        }
    }

    public static function cleanup(): void
    {
        global $wpdb;
        if ((int) get_option('pesm_schema_version', 0) < 4) { return; }
        $wpdb->query("DELETE FROM {$wpdb->prefix}pesm_guest_recovery_requests WHERE expires_at < UTC_TIMESTAMP()");
        $wpdb->query("DELETE FROM {$wpdb->prefix}pesm_guest_access_tokens WHERE expires_at < UTC_TIMESTAMP() OR revoked_at IS NOT NULL");
    }
}
