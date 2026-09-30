<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MarketPurchase;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Service\Coin\CoinAmount;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * @extends AbstractReadOnlyCrudController<MarketPurchase>
 */
class MarketPurchaseCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return MarketPurchase::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Vente du marché')
            ->setEntityLabelInPlural('Ventes du marché')
            ->setDefaultSort(['requestedAt' => 'DESC'])
            ->setSearchFields(['buyer.username', 'buyer.discordId', 'seller.username', 'seller.discordId', 'listing.card.name', 'paymentTransactionId', 'payoutTransactionId'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Achats entre joueurs en Youl Coin, commission de la banque incluse. Tout statut autre que « Terminée », « Échouée » et « Remboursée » attend une réponse du coin : la commande app:coin:reconcile-market le résout.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('buyer', 'Acheteur'))
            ->add(EntityFilter::new('seller', 'Vendeur'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->statusChoices()))
            ->add(DateTimeFilter::new('requestedAt', 'Demandée le'))
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('requestedAt')->setLabel('Demandée le');
        yield TextField::new('buyer.username')->setLabel('Acheteur');
        yield TextField::new('seller.username')->setLabel('Vendeur');
        yield TextField::new('listing.card.name')->setLabel('Carte');
        yield IntegerField::new('price')->setLabel('Prix (coins)');
        yield TextField::new('feeMinor')
            ->setLabel('Commission (YLC)')
            ->formatValue(static fn (mixed $value): string => \is_string($value) ? CoinAmount::fromMinor($value)->format() : '')
        ;
        yield ChoiceField::new('status')
            ->setLabel('Statut')
            ->setChoices($this->statusChoices())
            ->renderAsBadges([
                MarketPurchaseStatusEnum::PAYMENT_PENDING->value => 'warning',
                MarketPurchaseStatusEnum::CARD_TRANSFERRED->value => 'warning',
                MarketPurchaseStatusEnum::COMPLETED->value => 'success',
                MarketPurchaseStatusEnum::FAILED->value => 'danger',
                MarketPurchaseStatusEnum::REFUND_PENDING->value => 'warning',
                MarketPurchaseStatusEnum::REFUNDED->value => 'secondary',
            ])
        ;
        yield TextField::new('paymentTransactionId')->setLabel('Transaction de paiement');
        yield TextField::new('payoutTransactionId')->setLabel('Transaction de versement');
        yield TextField::new('refundTransactionId')->setLabel('Transaction de remboursement')->onlyOnDetail();
        yield DateTimeField::new('resolvedAt')->setLabel('Résolue le')->onlyOnDetail();
        yield TextField::new('failureReason')->setLabel('Motif')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('buyer.discordId')->setLabel('Discord ID de l\'acheteur')->onlyOnDetail();
        yield TextField::new('seller.discordId')->setLabel('Discord ID du vendeur')->onlyOnDetail();
    }

    /**
     * @return array<string, string> label => backing value
     */
    private function statusChoices(): array
    {
        $choices = [];
        foreach (MarketPurchaseStatusEnum::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }
}
