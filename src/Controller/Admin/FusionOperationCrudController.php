<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FusionOperation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractReadOnlyCrudController<FusionOperation>
 */
class FusionOperationCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return FusionOperation::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Fusion')
            ->setEntityLabelInPlural('Fusions de doublons')
            ->setDefaultSort(['fusedAt' => 'DESC'])
            ->setSearchFields(['discordUser.username', 'discordUser.discordId', 'card.name'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Journal des fusions : des copies normales d\'une carte consommées contre des copies holo de la même carte.')
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('fusedAt')->setLabel('Fusionné le');
        yield TextField::new('discordUser.username')->setLabel('Joueur');
        yield AssociationField::new('card')->setLabel('Carte');
        yield IntegerField::new('fusionCount')->setLabel('Fusions');
        yield IntegerField::new('copiesConsumed')->setLabel('Copies consommées');
        yield IntegerField::new('holosCreated')->setLabel('Holos créés');
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('discordUser.discordId')->setLabel('Discord ID')->onlyOnDetail();
    }
}
