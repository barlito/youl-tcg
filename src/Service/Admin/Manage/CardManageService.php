<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Exception\Admin\ManageApiException;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UserCardRepository;
use App\Service\Admin\ImportApiInputValidator;
use App\Service\Card\CardDepublicationGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final readonly class CardManageService
{
    private const array PATCH_FIELDS = ['name', 'description', 'rarity', 'status', 'unique', 'alwaysHolo', 'visualConfigOverride', 'extension'];

    private const array MIME_IMAGES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CardRepository $cardRepository,
        private ExtensionRepository $extensionRepository,
        private UserCardRepository $userCardRepository,
        private CardDepublicationGuard $depublicationGuard,
        private ManageApiValidator $validator,
        private ImportApiInputValidator $uploadValidator,
        private ManageApiAudit $audit,
        private AdminApiCacheInvalidator $caches,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    public function update(Card $card, ApiInput $input): void
    {
        $input->allowOnly(self::PATCH_FIELDS);
        if ($input->isEmpty()) {
            $input->error('body', 'Aucun champ à modifier.');
        }

        $name = $input->has('name') ? $input->string('name') : null;
        $description = $input->has('description') ? $input->string('description') : null;
        $rarity = $input->has('rarity') ? $input->enum('rarity', CardRarityEnum::class) : null;
        $status = $input->has('status') ? $input->enum('status', CardStatusEnum::class) : null;
        $unique = $input->has('unique') ? $input->bool('unique') : null;
        $alwaysHolo = $input->has('alwaysHolo') ? $input->bool('alwaysHolo') : null;
        $target = $this->targetExtension($input);
        $visualConfig = $card->getVisualConfigOverride();
        if ($input->has('visualConfigOverride')) {
            $visualConfig = VisualConfigPatch::apply($visualConfig, null === $input->raw('visualConfigOverride') ? null : $input->structure('visualConfigOverride'), $input, 'visualConfigOverride');
        }
        $this->validator->assertInput($input);

        $before = $this->snapshot($card);
        $this->assertNoConflict($card, $name, $status, $unique, $target);

        if (null !== $name) {
            $card->setName($name);
        }
        if (null !== $description) {
            $card->setDescription($description);
        }
        if ($rarity instanceof CardRarityEnum) {
            $card->setRarity($rarity);
        }
        if ($status instanceof CardStatusEnum) {
            $card->setStatus($status);
        }
        if (null !== $unique) {
            $card->setUnique($unique);
        }
        if (null !== $alwaysHolo) {
            $card->setAlwaysHolo($alwaysHolo);
        }
        if ($target instanceof Extension) {
            $card->setExtension($target);
        }
        if ($input->has('visualConfigOverride')) {
            $card->setVisualConfigOverride($visualConfig);
        }

        $this->validator->assertEntity($card);
        $this->entityManager->wrapInTransaction(fn () => $this->entityManager->flush());

        $this->audit->record('card:' . $card->getId(), ManageApiAudit::diff($before, $this->snapshot($card)));
        $this->caches->afterWrite();
    }

    public function replaceFiles(Card $card, Request $request): void
    {
        $errors = $this->uploadValidator->validateReplacement($request, ['image' => self::MIME_IMAGES, 'mask' => ['image/png'], 'foil' => self::MIME_IMAGES]);
        if ([] !== $errors) {
            throw ManageApiException::invalid($errors);
        }

        $changes = [];
        $image = $request->files->get('image');
        if ($image instanceof UploadedFile) {
            $card->setImageFile($image);
            $changes['image'] = $image->getClientOriginalName();
        }
        $mask = $request->files->get('mask');
        if ($mask instanceof UploadedFile) {
            $card->setImageMaskFile($mask);
            $changes['mask'] = $mask->getClientOriginalName();
        }
        $foil = $request->files->get('foil');
        if ($foil instanceof UploadedFile) {
            $card->setImageFoilFile($foil);
            $changes['foil'] = $foil->getClientOriginalName();
        }

        $this->entityManager->flush();
        $this->audit->record('card:' . $card->getId(), $changes);
        $this->caches->afterWrite();
    }

    private function targetExtension(ApiInput $input): ?Extension
    {
        $slug = $input->has('extension') ? $input->string('extension') : null;
        if (null === $slug) {
            return null;
        }

        $extension = $this->extensionRepository->findOneBy(['slug' => $slug]);
        if (!$extension instanceof Extension) {
            $input->error('extension', 'Extension inconnue (slug attendu).');
        }

        return $extension;
    }

    private function assertNoConflict(Card $card, ?string $name, ?CardStatusEnum $status, ?bool $unique, ?Extension $target): void
    {
        if (CardStatusEnum::DRAFT === $status && CardStatusEnum::PUBLISHED === $card->getStatus()) {
            $reason = $this->depublicationGuard->blockReason($card);
            if (null !== $reason) {
                throw ManageApiException::conflict($reason, ['status' => [$reason]]);
            }
        }

        $lock = $card->uniqueFlagLockReason();
        if (false === $unique && null !== $lock) {
            throw ManageApiException::conflict($lock, ['unique' => [$lock]]);
        }

        $moves = $target instanceof Extension && $target !== $card->getExtension();
        if ($moves && $this->isOwned($card)) {
            $reason = 'Cette carte est déjà possédée par des joueurs : elle ne peut plus changer d\'extension.';
            throw ManageApiException::conflict($reason, ['extension' => [$reason]]);
        }

        $finalExtension = $target ?? $card->getExtension();
        $finalName = $name ?? $card->getName();
        if ($finalExtension instanceof Extension && ($moves || (null !== $name && 0 !== strcasecmp($name, $card->getName())))) {
            $existing = $this->cardRepository->findOneByExtensionAndNameIgnoringCase($finalExtension, $finalName);
            if ($existing instanceof Card && $existing !== $card) {
                throw ManageApiException::conflict('Une carte de cette extension porte déjà ce nom.', ['name' => ['Nom déjà utilisé dans l\'extension.']], ['card' => ['id' => $existing->getId(), 'name' => $existing->getName()]]);
            }
        }
    }

    private function isOwned(Card $card): bool
    {
        return $card->isClaimed() || $this->userCardRepository->countHolders($card) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Card $card): array
    {
        return [
            'name' => $card->getName(),
            'description' => $card->getDescription(),
            'rarity' => $card->getRarity()->value,
            'status' => $card->getStatus()->name,
            'unique' => $card->isUnique(),
            'alwaysHolo' => $card->isAlwaysHolo(),
            'extension' => $card->getExtension()?->getSlug(),
            'visualConfigOverride' => $card->getVisualConfigOverride()->toArray(),
        ];
    }
}
