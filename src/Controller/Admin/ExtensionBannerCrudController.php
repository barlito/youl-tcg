<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\ExtensionBanner;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * @extends AbstractCrudController<ExtensionBanner>
 */
class ExtensionBannerCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ExtensionBanner::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Bannière')
            ->setEntityLabelInPlural('Bannières d\'univers')
            ->setDefaultSort(['extension.name' => 'ASC', 'position' => 'ASC'])
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('extension');
        yield VichImageField::new('imageFile')
            ->setLabel('Bannière')
            ->setHelp('Visuel large (format paysage) affiché en héro de la page univers')
            ->onlyOnForms()
        ;
        yield ImageField::new('imageName')
            ->setLabel('Bannière')
            ->setBasePath('/images/banners')
            ->onlyOnIndex()
        ;
        yield IntegerField::new('position')
            ->setHelp('Ordre dans le carrousel (plus petit = premier)')
        ;
    }
}
