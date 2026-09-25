<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Announcement;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

/**
 * Send log of announcements and booster code notifications. Sends happen
 * from the "Annonces" and code screens, never from here.
 *
 * @extends AbstractReadOnlyCrudController<Announcement>
 */
// distinct from the /admin/announcements send screen
#[AdminRoute(path: '/send-log', name: 'send_log')]
class AnnouncementCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return Announcement::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Envoi')
            ->setEntityLabelInPlural('Historique des envois')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['title', 'message', 'context'])
            ->setTimezone('Europe/Paris')
            ->setPaginatorPageSize(30)
            ->setHelp(Crud::PAGE_INDEX, 'Annonces et notifications de codes envoyées depuis l\'admin. Un envoi à tous = une seule notification, visible par chaque joueur.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('type')
            ->add('author')
            ->add(DateTimeFilter::new('createdAt', 'Date'))
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt')->setLabel('Date');
        yield TextField::new('typeLabel')->setLabel('Type')->setSortable(false);
        yield TextField::new('title')->setLabel('Titre');
        yield AssociationField::new('author')->setLabel('Auteur');
        yield TextField::new('targetLabel')->setLabel('Cible')->setSortable(false)
            ->setMaxLength(Crud::PAGE_INDEX === $pageName ? 80 : 5000)
        ;
        yield IntegerField::new('sentCount')->setLabel('Notifications')
            ->setHelp('Entrées créées : 1 pour un envoi à tous, une par joueur sinon.')
        ;
        yield TextareaField::new('message')->setLabel('Message')->onlyOnDetail();
        yield TextField::new('link')->setLabel('Lien')->onlyOnDetail();
        yield TextField::new('context')->setLabel('Contexte')->onlyOnDetail();
    }
}
