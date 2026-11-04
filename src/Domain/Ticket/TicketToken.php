<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

/** The QR code conveys no name, phone number, seat ID or order ID. */
final class TicketToken
{
    public static function create(string $publicId, int $version, string $secret): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId) || $version < 1 || strlen($secret) < 32) {
            throw new \InvalidArgumentException('Invalid QR input.');
        }
        return $publicId . '.' . hash_hmac('sha256', "pesm:qr:v1:{$publicId}:{$version}", $secret);
    }

    public static function valid(string $token, string $publicId, int $version, string $secret): bool
    {
        if (!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token)) { return false; }
        return hash_equals(self::create($publicId, $version, $secret), $token);
    }
}
