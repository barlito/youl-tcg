<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Admin\FoilSizeSliderScript;
use App\Dto\VisualConfig;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\CardFrameEnum;
use App\Enum\Card\CardNameFontEnum;
use App\Enum\Card\FoilTextureEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Form\Extension\Core\Type\RangeType;

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
    public function configureAssets(Assets $assets): Assets
    {
        return parent::configureAssets($assets)->addHtmlContentToBody(FoilSizeSliderScript::HTML);
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
        yield ImageField::new('imageName')
            ->setLabel('Image')
            ->setBasePath('/uploads/extensions')
            ->onlyOnIndex()
        ;
        yield VichImageField::new('imageFile', allowDelete: true)
            ->setLabel('Image (tuile univers)')
            ->setHelp('Fond des tuiles de l\'univers (accueil, /univers, collection). Sans image : artwork de la carte publiée la plus rare, sinon gradient seul.')
            ->onlyOnForms()
        ;
        yield VichImageField::new('logoFile', allowDelete: true)
            ->setLabel('Logo (cadre des cartes)')
            ->setHelp('Wordmark de l\'univers (PNG/SVG transparent) affiché en haut à droite du cadre CSS des cartes. Sans logo : nom de l\'extension en texte stylé.')
            ->onlyOnForms()
        ;
        yield CodeEditorField::new('visualConfigJson')
            ->setLabel('Visual config')
            ->setLanguage('js')
            ->onlyOnForms()
            ->setHelp('Default visuals for the extension cards. Keys: glow, borderColor, cssClass, holoEffect (preset: shine|basic|cosmos|trainer), foilTexture, foilSize (10-100, 100 = cover), frame (youl|none), nameFont (space-grotesk|pirata-one), frameLineStart/frameLineEnd (liseré néon du cadre, couleurs CSS). Example: {"glow": "#a435f0", "frame": "youl", "frameLineStart": "#46e6e6", "frameLineEnd": "#ff3ea5"}')
        ;
        yield ChoiceField::new('frame')
            ->setLabel('Cadre CSS')
            ->setHelp('Cadre dessiné en CSS sur les cartes du set (défaut : Youl). « Aucun » pour un set dont les artworks embarquent déjà leur cadre — overridable par carte via le JSON (frame)')
            ->setChoices($this->frameChoices())
            ->onlyOnForms()
        ;
        yield ChoiceField::new('nameFont')
            ->setLabel('Police du nom')
            ->setHelp('Police du nom de carte sur le cadre CSS (vide = Space Grotesk) — overridable par carte via le JSON (nameFont)')
            ->setChoices($this->nameFontChoices())
            ->onlyOnForms()
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
        yield Field::new('foilSize')
            ->setLabel('Foil zoom')
            ->setFormType(RangeType::class)
            ->setFormTypeOption('attr', [
                'min' => VisualConfig::FOIL_SIZE_AUTO,
                'max' => VisualConfig::FOIL_SIZE_MAX,
                'step' => 5,
                'data-foil-size' => '',
            ])
            ->setHelp('Taille de foil par défaut des cartes du set : tout à gauche = Auto (réglage du preset), 100 % = cover, entre les deux = motif tilé (overridable par carte)')
            ->onlyOnForms()
        ;
        yield ColorField::new('glowColor')
            ->setLabel('Glow')
            ->setHelp('Default halo colour for the extension cards (empty = rarity colour)')
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

    /**
     * @return array<string, CardFrameEnum> label => enum case, for the ChoiceField
     */
    private function frameChoices(): array
    {
        $choices = [];
        foreach (CardFrameEnum::cases() as $frame) {
            $choices[$frame->label()] = $frame;
        }

        return $choices;
    }

    /**
     * @return array<string, CardNameFontEnum> label => enum case, for the ChoiceField
     */
    private function nameFontChoices(): array
    {
        $choices = [];
        foreach (CardNameFontEnum::cases() as $font) {
            $choices[$font->label()] = $font;
        }

        return $choices;
    }
}
