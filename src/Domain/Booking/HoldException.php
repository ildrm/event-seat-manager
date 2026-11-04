<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

use RuntimeException;

final class HoldException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
