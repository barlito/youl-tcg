<?php

declare(strict_types=1);

namespace App\Enum\Entity;

enum ExtensionStatusEnum: int
{
    case DRAFT = 1;
    case PUBLISHED = 2;
}
