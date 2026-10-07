<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Entity\ExtensionBanner;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Admin\ManageApiException;
use App\Repository\ExtensionRepository;
use App\Service\Admin\ImportApiInputValidator;
use App\Service\Extension\ExtensionDepublicationGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final readonly class ExtensionManageService
{
    private const array PATCH_FIELDS = ['name', 'description', 'status', 'upcoming', 'completionRewardCoins', 'visualConfig'];

    private const array MIME_IMAGES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionRepository $extensionRepository,
        private ExtensionDepublicationGuard $depublicationGuard,
        private ManageApiValidator $validator,
        private ImportApiInputValidator $uploadValidator,
        private ManageApiAudit $audit,
        private AdminApiCacheInvalidator $caches,
    ) {
    }

    public function update(Extension $extension, ApiInput $input): void
    {
        $input->allowOnly(self::PATCH_FIELDS);
        if ($input->isEmpty()) {
            $input->error('body', 'Aucun champ à modifier.');
        }

        $name = $input->has('name') ? $input->string('name') : null;
        $description = $input->has('description') ? $input->string('description') : null;
        $status = $input->has('status') ? $input->enum('status', ExtensionStatusEnum::class) : null;
        $upcoming = $input->has('upcoming') ? $input->bool('upcoming') : null;
        $reward = $input->has('completionRewardCoins') ? $input->int('completionRewardCoins', true) : null;
        $visualConfig = $extension->getVisualConfig();
        if ($input->has('visualConfig')) {
            $visualConfig = VisualConfigPatch::apply($visualConfig, null === $input->raw('visualConfig') ? null : $input->structure('visualConfig'), $input, 'visualConfig');
        }
        $this->validator->assertInput($input);

        $before = $this->snapshot($extension);

        if (null !== $name && 0 !== strcasecmp($name, $extension->getName())) {
            $existing = $this->extensionRepository->findOneByNameIgnoringCase($name);
            if ($existing instanceof Extension && $existing !== $extension) {
                throw ManageApiException::conflict('Une extension porte déjà ce nom.', ['name' => ['Nom déjà utilisé.']], ['extension' => ['slug' => $existing->getSlug()]]);
            }
        }

        if (ExtensionStatusEnum::DRAFT === $status && ExtensionStatusEnum::PUBLISHED === $extension->getStatus()) {
            $reason = $this->depublicationGuard->blockReason($extension);
            if (null !== $reason) {
                throw ManageApiException::conflict($reason, ['status' => [$reason]]);
            }
        }

        $this->applyScalars($extension, $name, $description, $status, $upcoming, $input->has('completionRewardCoins'), $reward);
        if ($input->has('visualConfig')) {
            $extension->setVisualConfig($visualConfig);
        }

        $this->validator->assertEntity($extension);
        $this->flush($extension);

        $this->audit->record('extension:' . $extension->getSlug(), ManageApiAudit::diff($before, $this->snapshot($extension)));
        $this->caches->afterWrite();
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(Extension $extension, bool $dryRun): array
    {
        $drafts = $extension->getCards()->filter(static fn (Card $card): bool => CardStatusEnum::DRAFT === $card->getStatus());
        $publishExtension = ExtensionStatusEnum::PUBLISHED !== $extension->getStatus();
        $report = [
            'dryRun' => $dryRun,
            'extension' => ['slug' => $extension->getSlug(), 'name' => $extension->getName(), 'wasDraft' => $publishExtension],
            'cards' => array_values($drafts->map(static fn (Card $card): array => [
                'id' => $card->getId(),
                'name' => $card->getName(),
                'rarity' => $card->getRarity()->value,
                'hasImage' => null !== $card->getImageName(),
            ])->toArray()),
            'warnings' => $this->warnings($extension),
        ];

        if ($dryRun) {
            return $report;
        }

        $extension->setStatus(ExtensionStatusEnum::PUBLISHED);
        foreach ($drafts as $card) {
            $card->setStatus(CardStatusEnum::PUBLISHED);
        }

        // the cascade validates every card: one invalid card aborts the whole publication
        $this->validator->assertEntity($extension);
        $this->entityManager->wrapInTransaction(fn () => $this->entityManager->flush());

        $this->audit->record('extension:' . $extension->getSlug(), [
            'status' => ['from' => $publishExtension ? 'DRAFT' : 'PUBLISHED', 'to' => 'PUBLISHED'],
            'cardsPublished' => $drafts->count(),
        ]);
        $this->caches->afterWrite();

        return $report;
    }

    public function replaceFiles(Extension $extension, Request $request): void
    {
        $errors = $this->uploadValidator->validateReplacement($request, ['image' => self::MIME_IMAGES, 'logo' => self::MIME_IMAGES]);
        if ([] !== $errors) {
            throw ManageApiException::invalid($errors);
        }

        $changes = [];
        $image = $request->files->get('image');
        if ($image instanceof UploadedFile) {
            $extension->setImageFile($image);
            $changes['image'] = $image->getClientOriginalName();
        }
        $logo = $request->files->get('logo');
        if ($logo instanceof UploadedFile) {
            $extension->setLogoFile($logo);
            $changes['logo'] = $logo->getClientOriginalName();
        }

        $this->entityManager->flush();
        $this->audit->record('extension:' . $extension->getSlug(), $changes);
        $this->caches->afterWrite();
    }

    public function addBanner(Extension $extension, Request $request): ExtensionBanner
    {
        $input = ApiInput::fromRequest($request)->allowOnly(['position']);
        $errors = $this->uploadValidator->validateReplacement($request, ['image' => self::MIME_IMAGES], required: ['image']);
        $position = $input->has('position') ? $input->int('position', max: 10_000) : null;
        $this->validator->assertInput($input);
        if ([] !== $errors) {
            throw ManageApiException::invalid($errors);
        }

        $banner = new ExtensionBanner()->setExtension($extension)->setPosition($position ?? $this->nextPosition($extension));
        $image = $request->files->get('image');
        \assert($image instanceof UploadedFile);
        $banner->setImageFile($image);
        $extension->addBanner($banner);

        $this->entityManager->persist($banner);
        $this->entityManager->flush();
        $this->audit->record('extension:' . $extension->getSlug(), ['bannerAdded' => ['id' => $banner->getId(), 'position' => $banner->getPosition()]]);
        $this->caches->afterWrite();

        return $banner;
    }

    private function applyScalars(Extension $extension, ?string $name, ?string $description, ?ExtensionStatusEnum $status, ?bool $upcoming, bool $rewardSent, ?int $reward): void
    {
        if (null !== $name) {
            $extension->setName($name);
        }
        if (null !== $description) {
            $extension->setDescription($description);
        }
        if ($status instanceof ExtensionStatusEnum) {
            $extension->setStatus($status);
        }
        if (null !== $upcoming) {
            $extension->setUpcoming($upcoming);
        }
        if ($rewardSent) {
            $extension->setCompletionRewardCoins($reward);
        }
    }

    private function flush(Extension $extension): void
    {
        $this->entityManager->wrapInTransaction(function () use ($extension): void {
            $this->entityManager->flush();
            // at most one upcoming extension: the freshly flagged one wins
            if ($extension->isUpcoming()) {
                $this->extensionRepository->clearUpcomingExcept($extension);
            }
        });
    }

    private function nextPosition(Extension $extension): int
    {
        $positions = $extension->getBanners()->map(static fn (ExtensionBanner $banner): int => $banner->getPosition())->toArray();

        return [] === $positions ? 0 : max($positions) + 1;
    }

    /**
     * @return list<string>
     */
    public function warnings(Extension $extension): array
    {
        $warnings = [];
        if (0 === $extension->getCards()->count()) {
            $warnings[] = 'L\'extension n\'a aucune carte.';
        }
        $withoutImage = $extension->getCards()->filter(static fn (Card $card): bool => null === $card->getImageName())->count();
        if ($withoutImage > 0) {
            $warnings[] = \sprintf('%d carte(s) sans image.', $withoutImage);
        }
        if (0 === $extension->getBoosters()->count()) {
            $warnings[] = 'L\'extension n\'a aucun booster : personne ne pourra en tirer les cartes.';
        } elseif (!$extension->getBoosters()->exists(static fn (int $key, Booster $booster): bool => $booster->isClaimable() || $booster->isPurchasable())) {
            $warnings[] = 'Aucun booster de l\'extension n\'est réclamable ni achetable.';
        }
        if ($extension->isUpcoming() && ExtensionStatusEnum::PUBLISHED === $extension->getStatus()) {
            $warnings[] = 'Le teaser « prochain univers » ne s\'affiche que pour une extension en brouillon.';
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Extension $extension): array
    {
        return [
            'name' => $extension->getName(),
            'description' => $extension->getDescription(),
            'status' => $extension->getStatus()->name,
            'upcoming' => $extension->isUpcoming(),
            'completionRewardCoins' => $extension->getCompletionRewardCoins(),
            'visualConfig' => $extension->getVisualConfig()->toArray(),
        ];
    }
}
