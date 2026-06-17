<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;

/**
 * @extends AbstractCrudController<Extension>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ExtensionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Extension::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield Field::new('id')->onlyOnDetail();
        yield Field::new('name');
        yield Field::new('description');
        yield ChoiceField::new('status')
            ->setChoices(ExtensionStatusEnum::cases())
        ;
        yield VichImageField::new('imageFoilFile')
            ->setLabel('Default foil texture')
            ->setHelp('Holo foil fallback for cards of this extension that have none')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageMaskFile')
            ->setLabel('Default holo mask')
            ->onlyOnForms()
        ;
        yield CodeEditorField::new('visualConfigJson')
            ->setLabel('Visual config')
            ->setLanguage('js')
            ->onlyOnForms()
            ->setHelp('Default visuals for the extension cards. Keys: glow, borderColor, cssClass, holoIntensity (0-1), holoSaturation (0-3), holoGlitter (0-2). Example: {"glow": "#a435f0", "borderColor": "#ff3db0", "holoIntensity": 0.6}')
        ;
        yield Field::new('visualConfigJson')->setLabel('Visual config')->onlyOnDetail();
    }
}
