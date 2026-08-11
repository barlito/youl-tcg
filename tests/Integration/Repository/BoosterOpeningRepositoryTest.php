<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\BoosterOpeningRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoosterOpeningRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private BoosterOpeningRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(BoosterOpeningRepository::class);
    }

    public function testDistinctOpeningDaysAreProjectedOnParisCalendarDays(): void
    {
        [$user, $booster] = $this->createUserAndBooster();

        // 23:30 UTC in winter is already the NEXT Paris day (UTC+1)…
        $this->createOpening($user, $booster, '2026-01-15 23:30:00');
        // …while mid-day UTC stays on the same Paris day; two openings, one day
        $this->createOpening($user, $booster, '2026-01-15 10:00:00');
        $this->createOpening($user, $booster, '2026-01-15 12:00:00');
        // summer: 22:30 UTC is 00:30 Paris the next day (UTC+2)
        $this->createOpening($user, $booster, '2026-06-09 22:30:00');
        $this->entityManager->flush();

        $this->assertSame(
            ['2026-06-10', '2026-01-16', '2026-01-15'],
            $this->repository->findDistinctOpeningDays($user, 'Europe/Paris', 10),
        );
    }

    public function testDistinctOpeningDaysAreScopedToTheUserAndLimited(): void
    {
        [$user, $booster] = $this->createUserAndBooster();
        [$otherUser] = $this->createUserAndBooster();

        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $day) {
            $this->createOpening($user, $booster, $day . ' 12:00:00');
        }
        $this->createOpening($otherUser, $booster, '2026-03-04 12:00:00');
        $this->entityManager->flush();

        $this->assertSame(
            ['2026-03-03', '2026-03-02'],
            $this->repository->findDistinctOpeningDays($user, 'Europe/Paris', 2),
        );
    }

    /**
     * @return array{DiscordUser, Booster}
     */
    private function createUserAndBooster(): array
    {
        $extension = new Extension()
            ->setName('Streak repo extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $user = new DiscordUser()->setDiscordId('streak-repo-' . uniqid())->setUsername('Streak tester');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return [$user, $booster];
    }

    private function createOpening(DiscordUser $user, Booster $booster, string $openedAtUtc): void
    {
        $this->entityManager->persist(
            new BoosterOpening($user, $booster, seed: 42, openedAt: new \DateTimeImmutable($openedAtUtc, new \DateTimeZone('UTC'))),
        );
    }
}
