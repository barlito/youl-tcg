<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Booster;
use App\Entity\BoosterClaim;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\BoosterClaimRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoosterClaimRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private BoosterClaimRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(BoosterClaimRepository::class);
    }

    /**
     * Regression: Doctrine binds datetimes without timezone conversion, so a
     * "midnight Europe/Paris" boundary used to reach PostgreSQL 1-2h ahead of
     * the UTC-stored claimed_at values — claims made just after midnight Paris
     * were not counted, making the daily quota bypassable every night.
     */
    public function testCountSinceComparesInstantsNotWallClockStrings(): void
    {
        $user = $this->createUserWithClaims([
            // 22:30 UTC = 00:30 Paris (CEST) the NEXT day → inside today's quota
            new \DateTimeImmutable('2026-07-06 22:30:00', new \DateTimeZone('UTC')),
            // 21:30 UTC = 23:30 Paris the previous day → outside
            new \DateTimeImmutable('2026-07-06 21:30:00', new \DateTimeZone('UTC')),
        ]);

        // the boundary as DailyBoosterClaimQuota builds it: midnight Paris
        $parisMidnight = new \DateTimeImmutable('2026-07-07 00:00:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame(1, $this->repository->countSince($user, $parisMidnight));
    }

    /**
     * @param list<\DateTimeImmutable> $claimedAts
     */
    private function createUserWithClaims(array $claimedAts): DiscordUser
    {
        $extension = new Extension()
            ->setName('TZ test extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->entityManager->persist($booster);

        $user = new DiscordUser()
            ->setDiscordId('tz-test-' . uniqid())
            ->setUsername('TZ tester')
        ;
        $this->entityManager->persist($user);

        foreach ($claimedAts as $claimedAt) {
            $this->entityManager->persist(new BoosterClaim($user, $booster, $claimedAt));
        }

        $this->entityManager->flush();

        return $user;
    }
}
