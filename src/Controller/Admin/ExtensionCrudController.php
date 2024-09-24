<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField;
use App\Entity\Extension;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExtensionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Extension::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->renderContentMaximized()
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function configureFields(string $pageName): iterable
    {
        yield Field::new('id')->onlyOnDetail();
        yield Field::new('name');
        yield Field::new('description');
        //        yield BooleanField::new('isUnique');
        //        yield AssociationField::new('extension');
        //        yield ImageField::new('imageFile');
    }
}
