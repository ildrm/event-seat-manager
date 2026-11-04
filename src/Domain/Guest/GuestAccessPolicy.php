<?php

declare(strict_types=1);

namespace PESM\Domain\Guest;

/** Deterministic, independently testable limits for email-based guest recovery. */
final class GuestAccessPolicy
{
    public const CODE_TTL_SECONDS = 600;
    public const TOKEN_TTL_SECONDS = 86400;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 90;

    public static function codeHash(int $orderId, string $code, string $secret): string
    {
        return hash_hmac('sha256', 'guest-recovery:' . $orderId . ':' . $code, $secret);
    }

    public static function validateCode(string $code): bool
    {
        return (bool) preg_match('/^[0-9]{8}$/D', $code);
    }

    public static function validateToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/D', $token);
    }

    public static function canVerify(int $attempts, string $expires, string $now): bool
    {
        return $attempts < self::MAX_ATTEMPTS && $expires > $now;
    }

    public static function canResend(string $requested, string $now): bool
    {
        return (strtotime($now . ' UTC') - strtotime($requested . ' UTC')) >= self::RESEND_SECONDS;
    }
}
