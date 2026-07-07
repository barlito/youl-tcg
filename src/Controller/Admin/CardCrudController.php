<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Card;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\FoilTextureEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @extends AbstractCrudController<Card>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class CardCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UploaderHelper $uploaderHelper,
        private readonly AssetMapperInterface $assetMapper,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, FoilTextureEnum> label => enum case
     */
    private function foilTextureChoices(): array
    {
        $choices = [];
        foreach (FoilTextureEnum::cases() as $texture) {
            $choices[$texture->label()] = $texture;
        }

        return $choices;
    }

    public static function getEntityFqcn(): string
    {
        return Card::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('extension')
            ->add('rarity')
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
        $imageCss = $this->assetMapper->getAsset('styles/admin/image.css')
            ?? throw new \LogicException('Asset "styles/admin/image.css" not found in the asset map.');

        yield ImageField::new('imageName')
            ->hideOnForm()
            ->addCssFiles($imageCss->publicPath)
            ->formatValue(function ($value, $entity): ?string {
                if (!$entity instanceof Card) {
                    throw new UnexpectedTypeException($entity, Card::class);
                }

                return $this->uploaderHelper->asset($entity, 'imageFile');
            })
        ;
        yield Field::new('id')->onlyOnDetail();
        if (Crud::PAGE_EDIT === $pageName) {
            $card = $this->getContext()?->getEntity()->getInstance();
            if ($card instanceof Card) {
                // EA help strings render as raw HTML: good enough to host the iframe
                yield FormField::addFieldset('Preview (état sauvegardé)')->setHelp(\sprintf(
                    '<iframe src="%s" style="width: 320px; height: 460px; border: 0; border-radius: 12px;" title="Preview" loading="lazy"></iframe>',
                    $this->urlGenerator->generate('admin_card_preview', ['id' => (string) $card->getId()]),
                ));
            }
        }
        yield FormField::addFieldset('Carte');
        yield Field::new('name');
        yield Field::new('description');
        yield ChoiceField::new('status')
            ->setChoices(CardStatusEnum::cases())
        ;
        yield ChoiceField::new('rarity')
            ->setChoices(CardRarityEnum::cases())
        ;
        yield AssociationField::new('extension');
        yield BooleanField::new('unique')
            ->setLabel('Unique Flag')
            ->renderAsSwitch(false)
        ;
        yield BooleanField::new('alwaysHolo')
            ->setLabel('Always holo')
            ->setHelp('Always drawn holo, whatever the slot holo chance')
            ->renderAsSwitch(false)
        ;

        yield FormField::addFieldset('Images');
        yield VichImageField::new('imageFile')
            ->setLabel('Artwork')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageFoilFile', allowDelete: true)
            ->setLabel('Foil (upload)')
            ->setHelp('Wins over the library foil below')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageMaskFile', allowDelete: true)
            ->setLabel('Holo mask')
            ->onlyOnForms()
        ;

        // The simple knobs first; every select/picker is declared AFTER the JSON
        // editor in the yield order below so its value is merged on top of the
        // freshly decoded JSON instead of being overwritten by it.
        yield FormField::addFieldset('Visuel')
            ->setHelp('Cascade : la carte surcharge sa valeur, sinon celle de l\'extension s\'applique')
        ;
        yield CodeEditorField::new('visualConfigOverrideJson')
            ->setLabel('Avancé (JSON)')
            ->setLanguage('js')
            ->onlyOnForms()
            ->setHelp('Keys: glow, borderColor, cssClass, holoEffect, foilTexture. The selects below win over their JSON key.')
        ;
        yield ChoiceField::new('holoEffect')
            ->setLabel('Holo preset')
            ->setChoices($this->effectChoices())
            ->onlyOnForms()
        ;
        yield ChoiceField::new('foilTexture')
            ->setLabel('Foil texture (library)')
            ->setHelp('Bundled foil used when no foil file is uploaded')
            ->setChoices($this->foilTextureChoices())
            ->onlyOnForms()
        ;
        yield ColorField::new('glowColor')
            ->setLabel('Glow')
            ->setHelp('Halo colour of the card (empty = rarity colour)')
            ->onlyOnForms()
        ;
        yield Field::new('visualConfigOverrideJson')->setLabel('Visual overrides')->onlyOnDetail();
    }

    /**
     * @return array<string, CardEffectEnum> label => enum case
     */
    private function effectChoices(): array
    {
        $choices = [];
        foreach (CardEffectEnum::cases() as $effect) {
            $choices[$effect->label()] = $effect;
        }

        return $choices;
    }
}
