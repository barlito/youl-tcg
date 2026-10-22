<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Home;

use App\Entity\Extension;
use App\Service\Home\HomeDashboardBuilder;
use PHPUnit\Framework\TestCase;

final class HomeDashboardHeadlineTest extends TestCase
{
    public function testItSaysWhatIsWaitingThenTheClosestUniverse(): void
    {
        $headline = HomeDashboardBuilder::headline(
            $this->today(claims: 2, trades: 1),
            [['extension' => new Extension()->setName('Cosmos'), 'owned' => 15, 'total' => 16]],
        );

        $this->assertSame('2 packs gratuits t\'attendent et une offre d\'échange attend ta réponse. Il te manque 1 carte pour finir Cosmos.', $headline);
    }

    public function testUnopenedPacksAndAStreakAtRiskReplaceTheClaims(): void
    {
        $headline = HomeDashboardBuilder::headline($this->today(unopened: 3, atRisk: true), []);

        $this->assertSame('3 packs attendent d\'être ouverts et ta série de 4 j s\'arrête si tu n\'ouvres rien aujourd\'hui.', $headline);
    }

    public function testASwitchedOffTradeFeatureIsNeverMentioned(): void
    {
        $this->assertStringNotContainsString('échange', HomeDashboardBuilder::headline($this->today(claims: 1, trades: null), []));
    }

    public function testNothingLeftToDo(): void
    {
        $this->assertSame(
            'Tout est fait pour aujourd\'hui : tes packs gratuits reviennent à minuit.',
            HomeDashboardBuilder::headline($this->today(), []),
        );
    }

    /**
     * @return array{claims: array{remaining: int}, unopenedBoosters: int, pendingTrades: int|null, streak: array{length: int, atRisk: bool}}
     */
    private function today(int $claims = 0, int $unopened = 0, ?int $trades = 0, bool $atRisk = false): array
    {
        return [
            'claims' => ['remaining' => $claims],
            'unopenedBoosters' => $unopened,
            'pendingTrades' => $trades,
            'streak' => ['length' => 4, 'atRisk' => $atRisk],
        ];
    }
}
