<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MarketListing;
use App\Enum\Market\MarketListingStatusEnum;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/** @extends AbstractReadOnlyCrudController<MarketListing> */
class MarketListingCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return MarketListing::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Annonce')
            ->setEntityLabelInPlural('Annonces')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['seller.username', 'seller.discordId', 'card.name'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Cartes mises en vente par les joueurs (3 annonces actives au plus par joueur). « Achat en cours » : un paiement est en train d\'être confirmé auprès du coin.')
        ;
    }

    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // aliases prefixed on purpose: EasyAdmin names its search/sort joins after the property
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->addSelect('listingSeller', 'listingCard')
            ->leftJoin('entity.seller', 'listingSeller')
            ->leftJoin('entity.card', 'listingCard')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('seller', 'Vendeur'))
            ->add(EntityFilter::new('card', 'Carte'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->statusChoices()))
            ->add(BooleanFilter::new('holo', 'Holo'))
            ->add(DateTimeFilter::new('createdAt', 'Créée le'))
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt')->setLabel('Créée le');
        yield TextField::new('seller.username')->setLabel('Vendeur');
        yield AssociationField::new('card')->setLabel('Carte');
        yield BooleanField::new('holo')->setLabel('Holo')->renderAsSwitch(false);
        yield IntegerField::new('price')->setLabel('Prix (coins)');
        yield ChoiceField::new('status')
            ->setLabel('Statut')
            ->setChoices($this->statusChoices())
            ->renderAsBadges([
                MarketListingStatusEnum::ACTIVE->value => 'success',
                MarketListingStatusEnum::RESERVED_FOR_PURCHASE->value => 'warning',
                MarketListingStatusEnum::SOLD->value => 'info',
                MarketListingStatusEnum::WITHDRAWN->value => 'secondary',
                MarketListingStatusEnum::INVALIDATED->value => 'danger',
            ])
        ;
        yield DateTimeField::new('closedAt')->setLabel('Clôturée le')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('seller.discordId')->setLabel('Discord ID du vendeur')->onlyOnDetail();
    }

    /** @return array<string, string> */
    private function statusChoices(): array
    {
        $choices = [];
        foreach (MarketListingStatusEnum::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }
}
