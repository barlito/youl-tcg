<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\FoilTextureEnum;
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
        yield VichImageField::new('imageFoilFile', allowDelete: true)
            ->setLabel('Default foil texture')
            ->setHelp('Holo foil fallback for cards of this extension that have none')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageMaskFile', allowDelete: true)
            ->setLabel('Default holo mask')
            ->onlyOnForms()
        ;
        yield CodeEditorField::new('visualConfigJson')
            ->setLabel('Visual config')
            ->setLanguage('js')
            ->onlyOnForms()
            ->setHelp('Default visuals for the extension cards. Keys: glow, borderColor, cssClass, holoEffect (preset: shine|basic|cosmos|trainer). Example: {"glow": "#a435f0", "borderColor": "#ff3db0", "holoEffect": "basic"}')
        ;
        // Declared AFTER the JSON editor so the chosen preset is merged on top of
        // the freshly decoded JSON instead of being overwritten by it.
        yield ChoiceField::new('holoEffect')
            ->setLabel('Holo preset')
            ->setHelp('Holo effect applied on top of the rarity recipe (overrides the holoEffect JSON key)')
            ->setChoices($this->effectChoices())
            ->onlyOnForms()
        ;
        yield ChoiceField::new('foilTexture')
            ->setLabel('Foil texture (library)')
            ->setHelp('Bundled foil applied when neither the card nor the extension uploaded one (overrides the foilTexture JSON key)')
            ->setChoices($this->foilTextureChoices())
            ->onlyOnForms()
        ;
        yield Field::new('visualConfigJson')->setLabel('Visual config')->onlyOnDetail();
    }

    /**
     * @return array<string, CardEffectEnum> label => enum case, for the ChoiceField
     */
    private function effectChoices(): array
    {
        $choices = [];
        foreach (CardEffectEnum::cases() as $effect) {
            $choices[$effect->label()] = $effect;
        }

        return $choices;
    }

    /**
     * @return array<string, FoilTextureEnum> label => enum case, for the ChoiceField
     */
    private function foilTextureChoices(): array
    {
        $choices = [];
        foreach (FoilTextureEnum::cases() as $texture) {
            $choices[$texture->label()] = $texture;
        }

        return $choices;
    }
}
