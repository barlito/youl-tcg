<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Booster;
use App\Entity\BoosterClaim;
use App\Entity\BoosterOpening;
use App\Entity\RecycleOperation;
use App\Entity\UserBooster;
use App\Enum\Entity\CardRarityEnum;
use App\Form\BoosterSlotType;
use App\Service\Booster\BoosterRarityAvailability;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @extends AbstractGuardedCrudController<Booster>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class BoosterCrudController extends AbstractGuardedCrudController
{
    public function __construct(
        private readonly UploaderHelper $uploaderHelper,
        private readonly AssetMapperInterface $assetMapper,
        private readonly BoosterRarityAvailability $rarityAvailability,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Booster::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Booster')
            ->setEntityLabelInPlural('Boosters')
            ->setDefaultSort(['extension.name' => 'ASC'])
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('extension')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $imageCss = $this->assetMapper->getAsset('styles/admin/image.css')
            ?? throw new \LogicException('Asset "styles/admin/image.css" not found in the asset map.');

        yield ImageField::new('imageName')
            ->setLabel('Image')
            ->hideOnForm()
            ->addCssFiles($imageCss->publicPath)
            ->formatValue(function ($value, $entity): ?string {
                if (!$entity instanceof Booster) {
                    throw new UnexpectedTypeException($entity, Booster::class);
                }

                return $this->uploaderHelper->asset($entity, 'imageFile');
            })
        ;
        yield Field::new('id')->onlyOnDetail();
        // name is nullable: the listing shows the resolved display name so a
        // booster without its own name is not rendered as an empty cell
        yield Field::new('displayName')
            ->setLabel('Booster')
            ->hideOnForm()
        ;
        yield Field::new('name')
            ->setLabel('Nom')
            ->setHelp('Nom d\'affichage optionnel (par exemple d\'après ses taux : « Pack Full Rare »). Vide : le nom de l\'univers est utilisé.')
            ->onlyOnForms()
        ;
        yield AssociationField::new('extension')->setLabel('Univers');
        yield BooleanField::new('claimable')
            ->setLabel('Récupérable')
            ->setHelp('Décoché : distribution par événement ou par code uniquement — le pack n\'est plus récupérable gratuitement sur le hub, mais reste ouvrable par ceux qui le possèdent.')
            ->renderAsSwitch(false)
        ;
        yield IntegerField::new('cardCount')
            ->setLabel('Cartes')
            ->hideOnForm()
        ;
        yield CollectionField::new('rarityRates')
            ->setLabel('Slots (1 slot = 1 carte)')
            ->setEntryType(BoosterSlotType::class)
            ->setEntryIsComplex()
            ->renderExpanded()
            ->setEntryToStringMethod($this->summariseSlot(...))
            ->setFormTypeOption('delete_empty', false)
            ->setHelp($this->slotsHelp())
            ->onlyOnForms()
        ;
        yield Field::new('dropRates')
            ->setLabel('Taux')
            ->setSortable(false)
            ->hideOnForm()
            ->setTemplatePath('admin/field/booster_drop_rates.html.twig')
            ->formatValue(fn ($value, $entity): array => $this->dropRatesData($entity, Crud::PAGE_INDEX === $pageName))
        ;
        yield VichImageField::new('imageFile')->setLabel('Image du pack')->onlyOnForms();
    }

    /**
     * Accordion header of a slot: the weights the admin typed, already turned
     * into the percentages the player will see.
     */
    private function summariseSlot(mixed $slot): string
    {
        if (!\is_array($slot) || !\is_array($slot['rarities'] ?? null) || [] === $slot['rarities']) {
            return 'Nouveau slot';
        }

        /** @var array<string, int> $weights */
        $weights = $slot['rarities'];
        $parts = [];

        foreach (Booster::toPercentages($weights) as $rarity => $percentage) {
            $parts[] = \sprintf('%s %s %%', CardRarityEnum::tryFrom($rarity)?->label() ?? $rarity, $this->formatPercentage($percentage));
        }

        $holoChance = $slot['holoChance'] ?? 0;
        $summary = implode(' · ', $parts);

        return \is_int($holoChance) && $holoChance > 0 ? $summary . ' — holo ' . $holoChance . ' %' : $summary;
    }

    /**
     * Static help of the slots collection, plus the unavailable-rarity warning
     * when the edited booster weights a rarity its extension cannot deliver.
     */
    private function slotsHelp(): string
    {
        $help = 'Un slot = une carte tirée. Les poids sont relatifs (60/30/10 = 6/3/1) et la chance holo est propre au slot.';
        $booster = $this->getContext()?->getEntity()->getInstance();

        if (!$booster instanceof Booster) {
            return $help;
        }

        $unavailable = $this->rarityAvailability->findUnavailableRarities($booster);

        if ([] === $unavailable) {
            return $help;
        }

        return $help . \sprintf(
            '<span class="text-danger d-block mt-1">⚠ %s pondérée(s) mais sans carte tirable dans cette extension : le tirage retombera silencieusement sur la rareté voisine.</span>',
            htmlspecialchars(implode(', ', array_map(static fn (CardRarityEnum $rarity): string => $rarity->label(), $unavailable)), \ENT_QUOTES),
        );
    }

    /**
     * @return array{compact: bool, unavailable: list<string>, slots: list<array{rates: array<string, float>, weights: array<string, int>, holoChance: int}>}
     */
    private function dropRatesData(mixed $entity, bool $compact): array
    {
        if (!$entity instanceof Booster) {
            throw new UnexpectedTypeException($entity, Booster::class);
        }

        $rarityRates = $entity->getRarityRates();
        $slots = [];

        foreach ($entity->getDropRates() as $index => $slot) {
            $slots[] = [
                'rates' => $slot['rates'],
                'weights' => $rarityRates[$index]['rarities'] ?? [],
                'holoChance' => $slot['holoChance'],
            ];
        }

        return [
            'compact' => $compact,
            'unavailable' => array_map(
                static fn (CardRarityEnum $rarity): string => $rarity->value,
                $this->rarityAvailability->findUnavailableRarities($entity),
            ),
            'slots' => $slots,
        ];
    }

    private function formatPercentage(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 1, ',', ''), '0'), ',');
    }

    #[\Override]
    protected function deletionBlockers(object $entity): array
    {
        return $this->describeBlockers([
            '%d joueur(s) le possèdent encore' => $this->entityManager->getRepository(UserBooster::class)->count(['booster' => $entity]),
            '%d ouverture(s) le référencent' => $this->entityManager->getRepository(BoosterOpening::class)->count(['booster' => $entity]),
            '%d récupération(s) le référencent' => $this->entityManager->getRepository(BoosterClaim::class)->count(['booster' => $entity]),
            '%d recyclage(s) le référencent' => $this->entityManager->getRepository(RecycleOperation::class)->count(['booster' => $entity]),
        ]);
    }
}
