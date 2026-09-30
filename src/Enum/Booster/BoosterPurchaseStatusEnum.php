<?php

declare(strict_types=1);

namespace App\Enum\Booster;

enum BoosterPurchaseStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En vérification',
            self::COMPLETED => 'Payé',
            self::FAILED => 'Échoué',
        };
    }
}
