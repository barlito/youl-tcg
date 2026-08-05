<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BoosterAvailabilityServiceTest extends TestCase
{
    public function testAClaimableBoosterIsVisibleEvenWithoutCopies(): void
    {
        $booster = $this->booster();

        $this->assertTrue($this->service()->isVisible($booster, []));
    }

    public function testANonClaimableBoosterIsOnlyVisibleForOwners(): void
    {
        $booster = $this->booster()->setClaimable(false);
        $service = $this->service();

        $this->assertFalse($service->isVisible($booster, []));
        $this->assertFalse($service->isVisible($booster, [(string) $booster->getId() => 0]));
        $this->assertTrue($service->isVisible($booster, [(string) $booster->getId() => 1]));
    }

    public function testFilterVisibleKeepsClaimableAndOwnedBoostersAsAList(): void
    {
        $claimable = $this->booster();
        $ownedEvent = $this->booster()->setClaimable(false);
        $hiddenEvent = $this->booster()->setClaimable(false);

        $visible = $this->service()->filterVisible(
            [$hiddenEvent, $claimable, $ownedEvent],
            [(string) $ownedEvent->getId() => 2],
        );

        // array_filter keys must be re-indexed: the result is a list
        $this->assertSame([$claimable, $ownedEvent], $visible);
    }

    public function testIsClaimableDelegatesToTheBoosterFlag(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isClaimable($this->booster()));
        $this->assertFalse($service->isClaimable($this->booster()->setClaimable(false)));
    }

    public function testHasPublishedExtensionRefusesADraftExtension(): void
    {
        $service = $this->service();

        $this->assertTrue($service->hasPublishedExtension($this->booster()));
        $this->assertFalse($service->hasPublishedExtension($this->booster(ExtensionStatusEnum::DRAFT)));
    }

    public function testIsDrawableRequiresTheExtensionToHavePublishedCards(): void
    {
        $drawable = $this->booster();
        $empty = $this->booster();
        $service = $this->service([(string) $drawable->getExtension()->getId()]);

        $this->assertTrue($service->isDrawable($drawable));
        $this->assertFalse($service->isDrawable($empty));
    }

    public function testDrawableBoosterIdsMapsBoostersOfDrawableExtensionsInOneBatch(): void
    {
        $drawable = $this->booster();
        $sibling = $this->booster();
        $sibling->setExtension($drawable->getExtension());
        $empty = $this->booster();

        $service = $this->service([(string) $drawable->getExtension()->getId()]);

        $this->assertSame(
            [(string) $drawable->getId() => true, (string) $sibling->getId() => true],
            $service->drawableBoosterIds([$drawable, $sibling, $empty]),
        );
    }

    public function testIsOpenableNeedsADrawableBoosterAndAtLeastOneOwnedCopy(): void
    {
        $booster = $this->booster();
        $service = $this->service([(string) $booster->getExtension()->getId()]);

        $this->assertTrue($service->isOpenable($booster, 1));
        $this->assertFalse($service->isOpenable($booster, 0));
        $this->assertFalse($this->service()->isOpenable($booster, 1));
    }

    /**
     * @param list<string> $drawableExtensionIds
     */
    private function service(array $drawableExtensionIds = []): BoosterAvailabilityService
    {
        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findExtensionIdsWithPublishedCards')->willReturn($drawableExtensionIds);

        return new BoosterAvailabilityService($cardRepository);
    }

    private function booster(ExtensionStatusEnum $status = ExtensionStatusEnum::PUBLISHED): Booster
    {
        $extension = $this->withId(new Extension()->setName('Test ext')->setStatus($status));
        \assert($extension instanceof Extension);

        $booster = $this->withId(new Booster()->setExtension($extension));
        \assert($booster instanceof Booster);

        return $booster;
    }

    /**
     * The uuid is normally Doctrine-generated; hand-built entities get one via
     * reflection so the id-keyed maps have real keys to match on.
     */
    private function withId(Booster | Extension $entity): Booster | Extension
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, Uuid::v4());

        return $entity;
    }
}
