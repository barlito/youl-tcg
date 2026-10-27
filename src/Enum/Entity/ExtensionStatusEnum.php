<?php

declare(strict_types=1);

namespace App\Enum\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ExtensionStatusEnum: int implements TranslatableInterface
{
    case DRAFT = 1;
    case PUBLISHED = 2;

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::PUBLISHED => 'Publié',
        };
    }

    /**
     * French label in Symfony forms (EnumType) and EasyAdmin choice fields/filters.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
