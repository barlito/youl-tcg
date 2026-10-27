<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ProfileComparison;
use App\Dto\ProfileUniverseComparison;
use App\Entity\Extension;
use PHPUnit\Framework\TestCase;

final class ProfileComparisonTest extends TestCase
{
    public function testTheCompletionIsFlooredLikeEveryOtherCompletion(): void
    {
        // 2 / 3 = 66.7 %: shown 66 %, never rounded up to a completion the player has not reached
        $this->assertSame(66, new ProfileComparison([], total: 3, common: 1, profileOnly: 1, visitorOnly: 0, missingBoth: 1)->completionPct());
        $this->assertSame(99, new ProfileUniverseComparison(new Extension(), [], total: 200, common: 199, profileOnly: 0, visitorOnly: 0, missingBoth: 1)->completionPct());
        $this->assertSame(0, new ProfileComparison([], total: 0, common: 0, profileOnly: 0, visitorOnly: 0, missingBoth: 0)->completionPct());
    }
}
