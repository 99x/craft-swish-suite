<?php

namespace NinetyNineX\SwishSuite\enums;

enum PaymentStatus: string
{
    case Created   = 'CREATED';
    case Paid      = 'PAID';
    case Declined  = 'DECLINED';
    case Cancelled = 'CANCELLED';
    case Error     = 'ERROR';

    public function isTerminal(): bool
    {
        return match($this) {
            self::Paid, self::Declined, self::Cancelled, self::Error => true,
            self::Created => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
