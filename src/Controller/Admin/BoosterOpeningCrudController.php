<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BoosterOpening;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @extends AbstractReadOnlyCrudController<BoosterOpening>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class BoosterOpeningCrudController extends AbstractReadOnlyCrudController
{
    public function __construct(
        private readonly UploaderHelper $uploaderHelper,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BoosterOpening::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ouverture')
            ->setEntityLabelInPlural('Ouvertures de boosters')
            ->setDefaultSort(['openedAt' => 'DESC'])
            ->setSearchFields(['discordUser.username', 'discordUser.discordId', 'booster.name'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Journal d\'audit des ouvertures. La seed rejoue le tirage à l\'identique.')
        ;
    }

    /**
     * The "Cartes tirées" column reads the whole draw of every row: without this
     * fetch join the listing would fire one query per opening, plus one per card.
     */
    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // aliases prefixed on purpose: EasyAdmin names its own search/sort joins
        // after the property, so "booster" / "discordUser" would collide
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->addSelect('openingCard', 'drawnCard', 'openingBooster', 'openingUser')
            ->leftJoin('entity.boosterOpeningCards', 'openingCard')
            ->leftJoin('openingCard.card', 'drawnCard')
            ->leftJoin('entity.booster', 'openingBooster')
            ->leftJoin('entity.discordUser', 'openingUser')
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('openedAt')->setLabel('Ouvert le');
        yield TextField::new('discordUser.username')->setLabel('Joueur');
        yield AssociationField::new('booster')->setLabel('Booster');
        // virtual field: BoosterOpeningCard has a composite primary key, which
        // rules out an AssociationField — the rows are rendered by our template
        yield Field::new('drawnCards')
            ->setLabel('Cartes tirées')
            ->setSortable(false)
            ->setTemplatePath('admin/field/opening_cards.html.twig')
            ->formatValue(fn ($value, $entity): array => $this->drawnCardsData($entity, Crud::PAGE_INDEX === $pageName))
        ;
        yield IntegerField::new('seed')
            ->setLabel('Seed')
            ->setNumberFormat('%d')
            ->setHelp('Graine du tirage : rejoue exactement le même booster')
        ;
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('discordUser.discordId')->setLabel('Discord ID')->onlyOnDetail();
    }

    /**
     * @return array{compact: bool, total: int, holo: int, unique: int, bestRarity: string|null, cards: list<array{name: string, rarity: string, quantity: int, holoQuantity: int, unique: bool, thumbnail: string|null, url: string}>}
     */
    private function drawnCardsData(mixed $entity, bool $compact): array
    {
        if (!$entity instanceof BoosterOpening) {
            throw new UnexpectedTypeException($entity, BoosterOpening::class);
        }

        $rows = [];
        $uniqueCount = 0;

        foreach ($entity->getDrawnCards() as $drawnCard) {
            $card = $drawnCard->getCard();
            $uniqueCount += $card->isUnique() ? 1 : 0;

            if ($compact) {
                continue;
            }

            $rows[] = [
                'name' => $card->getName(),
                'rarity' => $card->getRarity()->value,
                'quantity' => $drawnCard->getQuantity(),
                'holoQuantity' => $drawnCard->getHoloQuantity(),
                'unique' => $card->isUnique(),
                'thumbnail' => $this->uploaderHelper->asset($card, 'imageFile'),
                'url' => $this->adminUrlGenerator
                    ->setController(CardCrudController::class)
                    ->setAction(Action::DETAIL)
                    ->setEntityId($card->getId())
                    ->generateUrl(),
            ];
        }

        return [
            'compact' => $compact,
            'total' => $entity->getDrawnCardCount(),
            'holo' => $entity->getHoloCount(),
            'unique' => $uniqueCount,
            'bestRarity' => $entity->getBestRarity()?->value,
            'cards' => $rows,
        ];
    }
}
