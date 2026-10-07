<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Entity\Booster;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Exception\Admin\ManageApiException;
use App\Repository\ExtensionRepository;
use App\Service\Admin\ImportApiInputValidator;
use App\Service\Booster\BoosterRarityAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final readonly class BoosterManageService
{
    private const array FIELDS = ['name', 'extension', 'claimable', 'purchasable', 'purchasePrice', 'rarityRates'];

    private const array MIME_IMAGES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionRepository $extensionRepository,
        private BoosterRarityAvailability $rarityAvailability,
        private ManageApiValidator $validator,
        private ImportApiInputValidator $uploadValidator,
        private ManageApiAudit $audit,
        private AdminApiCacheInvalidator $caches,
    ) {
    }

    public function create(Request $request): Booster
    {
        $input = ApiInput::fromRequest($request);
        $input->allowOnly(self::FIELDS);
        if (!$input->has('extension')) {
            $input->error('extension', 'Ce champ est requis.');
        }
        if (!$input->has('rarityRates')) {
            $input->error('rarityRates', 'Ce champ est requis.');
        }
        $uploadErrors = $request->files->count() > 0 ? $this->uploadValidator->validateReplacement($request, ['image' => self::MIME_IMAGES]) : [];

        $booster = new Booster();
        $this->apply($booster, $input, $uploadErrors);

        $image = $request->files->get('image');
        if ($image instanceof UploadedFile) {
            $booster->setImageFile($image);
        }
        $booster->getExtension()->addBooster($booster);
        $this->entityManager->persist($booster);
        $this->entityManager->wrapInTransaction(fn () => $this->entityManager->flush());

        $this->audit->record('booster:' . $booster->getId(), ['created' => $this->snapshot($booster) + ['image' => $image instanceof UploadedFile ? $image->getClientOriginalName() : null]]);
        $this->caches->afterWrite();

        return $booster;
    }

    public function update(Booster $booster, ApiInput $input): void
    {
        $input->allowOnly(self::FIELDS);
        if ($input->isEmpty()) {
            $input->error('body', 'Aucun champ à modifier.');
        }

        $before = $this->snapshot($booster);
        $this->apply($booster, $input, []);
        $this->entityManager->wrapInTransaction(fn () => $this->entityManager->flush());

        $this->audit->record('booster:' . $booster->getId(), ManageApiAudit::diff($before, $this->snapshot($booster)));
        $this->caches->afterWrite();
    }

    public function replaceImage(Booster $booster, Request $request): void
    {
        $errors = $this->uploadValidator->validateReplacement($request, ['image' => self::MIME_IMAGES], required: ['image']);
        if ([] !== $errors) {
            throw ManageApiException::invalid($errors);
        }

        $image = $request->files->get('image');
        \assert($image instanceof UploadedFile);
        $booster->setImageFile($image);
        $this->entityManager->flush();

        $this->audit->record('booster:' . $booster->getId(), ['image' => $image->getClientOriginalName()]);
        $this->caches->afterWrite();
    }

    /**
     * @return list<string>
     */
    public function warnings(Booster $booster): array
    {
        $unavailable = $this->rarityAvailability->findUnavailableRarities($booster);

        return [] === $unavailable ? [] : [\sprintf(
            'Rareté(s) pondérée(s) sans carte tirable dans l\'extension (le tirage retombera sur la rareté voisine) : %s.',
            implode(', ', array_map(static fn (CardRarityEnum $rarity): string => $rarity->value, $unavailable)),
        )];
    }

    /**
     * @param array<string, list<string>> $extraErrors
     */
    private function apply(Booster $booster, ApiInput $input, array $extraErrors): void
    {
        $name = $input->has('name') ? $input->string('name', nullable: true) : null;
        $slug = $input->has('extension') ? $input->string('extension') : null;
        $claimable = $input->has('claimable') ? $input->bool('claimable') : null;
        $purchasable = $input->has('purchasable') ? $input->bool('purchasable') : null;
        $price = $input->has('purchasePrice') ? $input->int('purchasePrice', true) : null;
        $rates = $input->has('rarityRates') ? $this->normaliseRates($input) : null;

        $extension = null;
        if (null !== $slug) {
            $extension = $this->extensionRepository->findOneBy(['slug' => $slug]);
            if (!$extension instanceof Extension) {
                $input->error('extension', 'Extension inconnue (slug attendu).');
            }
        }
        foreach ($extraErrors as $field => $messages) {
            foreach ($messages as $message) {
                $input->error($field, $message);
            }
        }
        $this->validator->assertInput($input);

        if ($input->has('name')) {
            $booster->setName($name);
        }
        if ($extension instanceof Extension) {
            $booster->setExtension($extension);
        }
        if (null !== $claimable) {
            $booster->setClaimable($claimable);
        }
        if (null !== $purchasable) {
            $booster->setPurchasable($purchasable);
        }
        if ($input->has('purchasePrice')) {
            $booster->setPurchasePrice($price);
        }
        if (null !== $rates) {
            $booster->setRarityRates($rates);
        }

        $this->validator->assertEntity($booster);
    }

    /**
     * @return list<array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}>|null
     */
    private function normaliseRates(ApiInput $input): ?array
    {
        $slots = $input->structure('rarityRates');
        if (null === $slots) {
            return null;
        }
        if (!array_is_list($slots)) {
            $input->error('rarityRates', 'Liste de slots attendue : [{"rarities": {...}, "holoChance": 0}, ...].');

            return null;
        }

        $normalised = [];
        foreach ($slots as $slot) {
            // unknown keys are dropped, invalid shapes are left to ValidRarityRates
            $normalised[] = \is_array($slot) ? array_intersect_key($slot, array_flip(['rarities', 'holoChance', 'uniqueChance'])) : $slot;
        }

        /** @var list<array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}> $normalised */
        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Booster $booster): array
    {
        return [
            'name' => $booster->getName(),
            'extension' => $booster->hasExtension() ? $booster->getExtension()->getSlug() : null,
            'claimable' => $booster->isClaimable(),
            'purchasable' => $booster->isPurchasable(),
            'purchasePrice' => $booster->getPurchasePrice(),
            'rarityRates' => $booster->getRarityRates(),
        ];
    }
}
