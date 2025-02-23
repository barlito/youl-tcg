<?php

declare(strict_types=1);

namespace App\Enum\Entity;

enum CardStatusEnum: int
{
    case DRAFT = 1;
    case PUBLISHED = 2;
}
