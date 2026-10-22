<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Recycle\RecycleAutoFill;
use App\Service\Recycle\RecycleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecycleAutoFillTest extends TestCase
{
    public function testFillsWithTheCheapestDuplicatesUpToExactlyTheCost(): void
    {
        $additions = RecycleAutoFill::complete(0, null, [
            'common' => self::card(1, normal: 12, holo: 0, total: 12),
            'rare' => self::card(3, normal: 4, holo: 0, total: 4),
            'legendary' => self::card(5, normal: 3, holo: 0, total: 3),
        ]);

        $this->assertSame(['common' => ['normal' => 10, 'holo' => 0]], $additions);
    }

    public function testPrefersCheapCopiesOverAPreciousOneWhenBothFit(): void
    {
        $additions = RecycleAutoFill::complete(0, null, [
            'uncommon' => self::card(2, normal: 5, holo: 0, total: 5),
            'legendary' => self::card(5, normal: 2, holo: 0, total: 2),
        ]);

        $this->assertSame(['uncommon' => ['normal' => 5, 'holo' => 0]], $additions);
    }

    public function testCompletesTheCurrentSelectionWithoutOvershooting(): void
    {
        // 7 points selected, cheapest copy 1: the total must land on 10 exactly (10 − 1 < 10)
        $additions = RecycleAutoFill::complete(7, 1, [
            'legendary' => self::card(5, normal: 3, holo: 0, total: 3),
            'rare' => self::card(3, normal: 2, holo: 0, total: 2),
            'common' => self::card(1, normal: 5, holo: 0, total: 5),
        ]);

        $this->assertNotNull($additions);
        $this->assertSame(10, 7 + self::points($additions, ['legendary' => 5, 'rare' => 3, 'common' => 1]));
        $this->assertSame(['common' => ['normal' => 3, 'holo' => 0]], $additions, 'Three commons beat one rare: the least precious copies first.');
    }

    public function testReturnsNullWhenThereAreNotEnoughPoints(): void
    {
        $this->assertNull(RecycleAutoFill::complete(0, null, [
            'common' => self::card(1, normal: 4, holo: 0, total: 4),
            'rare' => self::card(3, normal: 1, holo: 0, total: 1),
        ]));
    }

    public function testReturnsNullWithoutAnyCandidate(): void
    {
        $this->assertNull(RecycleAutoFill::complete(3, 1, []));
    }

    public function testReturnsNullWhenEveryCompletionWouldOvershoot(): void
    {
        // 1 point selected (cheapest 1): only 10 exactly is minimal, unreachable with 6-point holos
        $this->assertNull(RecycleAutoFill::complete(1, 1, [
            'holo-legendary' => self::card(5, normal: 0, holo: 5, total: 5),
        ]));
    }

    public function testNothingToAddOnceTheCostIsReached(): void
    {
        $this->assertSame([], RecycleAutoFill::complete(10, 1, [
            'common' => self::card(1, normal: 5, holo: 0, total: 5),
        ]));
    }

    public function testUsesHolosWhenNormalsAreMissing(): void
    {
        // holo common = 2 points
        $additions = RecycleAutoFill::complete(0, null, [
            'common' => self::card(1, normal: 0, holo: 6, total: 6),
        ]);

        $this->assertSame(['common' => ['normal' => 0, 'holo' => 5]], $additions);
    }

    public function testRespectsTheSharedCapOfACardOwningBothKinds(): void
    {
        // 2 normals + 1 holo free, but only 2 copies may leave (keep-one): 3 + 4 = 7 points at most
        $this->assertNull(RecycleAutoFill::complete(0, null, [
            'rare' => self::card(3, normal: 2, holo: 1, total: 2),
        ]));

        // with one more copy allowed: 3 + 3 + 4 = 10
        $this->assertSame(
            ['rare' => ['normal' => 2, 'holo' => 1]],
            RecycleAutoFill::complete(0, null, ['rare' => self::card(3, normal: 2, holo: 1, total: 3)]),
        );
    }

    public function testReroutesACopyWhenTheGreedyCardIsFull(): void
    {
        // the 2-point normal and the 3-point holo of `a-mixed` share ONE free copy: the 2-point
        // copy first lands on it, then moves to `uncommon` so the holo can be taken (2 + 3 + 5 = 10)
        $additions = RecycleAutoFill::complete(0, null, [
            'a-mixed' => self::card(2, normal: 1, holo: 1, total: 1),
            'uncommon' => self::card(2, normal: 1, holo: 0, total: 1),
            'legendary' => self::card(5, normal: 1, holo: 0, total: 1),
        ]);

        $this->assertEquals([
            'a-mixed' => ['normal' => 0, 'holo' => 1],
            'uncommon' => ['normal' => 1, 'holo' => 0],
            'legendary' => ['normal' => 1, 'holo' => 0],
        ], $additions);
    }

    /**
     * @param array<string, array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int}> $candidates
     */
    #[DataProvider('selections')]
    public function testEveryResultIsAMinimalSelectionAcceptedByTheService(int $points, ?int $cheapest, array $candidates): void
    {
        $additions = RecycleAutoFill::complete($points, $cheapest, $candidates);
        $this->assertNotNull($additions);

        $total = $points;
        $min = $cheapest ?? PHP_INT_MAX;
        foreach ($additions as $cardId => $copies) {
            $candidate = $candidates[$cardId];
            $this->assertLessThanOrEqual($candidate['normal'], $copies['normal']);
            $this->assertLessThanOrEqual($candidate['holo'], $copies['holo']);
            $this->assertLessThanOrEqual($candidate['total'], $copies['normal'] + $copies['holo']);
            $total += $copies['normal'] * $candidate['normalPoints'] + $copies['holo'] * $candidate['holoPoints'];
            if ($copies['normal'] > 0) {
                $min = min($min, $candidate['normalPoints']);
            }
            if ($copies['holo'] > 0) {
                $min = min($min, $candidate['holoPoints']);
            }
        }

        $this->assertGreaterThanOrEqual(RecycleService::BOOSTER_COST, $total);
        $this->assertFalse(RecycleService::isOvershooting($total, $min));
    }

    /** @return iterable<string, array{int, int|null, array<string, array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int}>}> */
    public static function selections(): iterable
    {
        yield 'legendaries only' => [0, null, ['a' => self::card(5, 4, 0, 4)]];
        yield 'legendary holos only' => [0, null, ['a' => self::card(5, 0, 3, 3)]];
        yield 'mixed rarities' => [0, null, ['a' => self::card(3, 2, 1, 2), 'b' => self::card(5, 1, 1, 1), 'c' => self::card(2, 1, 0, 1)]];
        yield 'started with a legendary' => [5, 5, ['a' => self::card(1, 9, 0, 9), 'b' => self::card(3, 2, 0, 2)]];
        yield 'started with a common' => [1, 1, ['a' => self::card(3, 3, 0, 3)]];
        yield 'nine points selected' => [9, 3, ['a' => self::card(5, 1, 0, 1), 'b' => self::card(2, 1, 0, 1)]];
    }

    /**
     * @param array<string, array{normal: int, holo: int}> $additions
     * @param array<string, int>                           $normalPoints
     */
    private static function points(array $additions, array $normalPoints): int
    {
        $points = 0;
        foreach ($additions as $cardId => $copies) {
            $points += $copies['normal'] * $normalPoints[$cardId] + $copies['holo'] * ($normalPoints[$cardId] + 1);
        }

        return $points;
    }

    /** @return array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int} */
    private static function card(int $normalPoints, int $normal, int $holo, int $total): array
    {
        return ['normalPoints' => $normalPoints, 'holoPoints' => $normalPoints + 1, 'normal' => $normal, 'holo' => $holo, 'total' => $total];
    }
}
