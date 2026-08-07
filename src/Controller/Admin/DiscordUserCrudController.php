<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DiscordUser;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractReadOnlyCrudController<DiscordUser>
 */
class DiscordUserCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return DiscordUser::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Joueur')
            ->setEntityLabelInPlural('Joueurs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['username', 'discordId'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Comptes créés automatiquement à la connexion Discord. Lecture seule : rien ne se modifie ici.')
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('username')->setLabel('Pseudo');
        yield TextField::new('discordId')->setLabel('Discord ID');
        // ".count" on purpose: UserCard/UserBooster have composite primary keys,
        // which EasyAdmin refuses to build an AssociationField on. The property
        // path calls Collection::count() and stays a plain read-only column.
        yield IntegerField::new('distinctCardCount')
            ->setLabel('Cartes distinctes')
            ->setHelp('Entrées du catalogue débloquées par le joueur.')
        ;
        yield IntegerField::new('cardCopyCount')
            ->setLabel('Exemplaires')
            ->setHelp('Total des exemplaires possédés, holos compris.')
        ;
        yield IntegerField::new('boosterCopyCount')
            ->setLabel('Boosters non ouverts')
        ;
        yield DateTimeField::new('createdAt')->setLabel('Première connexion');
        yield ArrayField::new('roles')->setLabel('Rôles')->onlyOnDetail();
        yield DateTimeField::new('updatedAt')->setLabel('Dernière mise à jour')->onlyOnDetail();
    }
}
