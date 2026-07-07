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
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
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
     * Floating live preview on the edit page: a fixed iframe follows the visual
     * form values in real time (they are passed to the preview route as query
     * parameters, never persisted). Plain inline JS on purpose — the admin has
     * its own asset pipeline, nothing else to hook.
     */
    #[\Override]
    public function configureAssets(Assets $assets): Assets
    {
        return parent::configureAssets($assets)->addHtmlContentToBody(<<<'HTML'
            <div id="card-live-preview" hidden
                 style="position: fixed; top: 90px; right: 24px; z-index: 1030; width: 300px;
                        background: #12121a; border: 1px solid #2c2c3a; border-radius: 14px;
                        padding: 12px; box-shadow: 0 12px 40px rgba(0,0,0,.45);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="color: #cfcfe0; font-size: 12px; letter-spacing: .08em;">PREVIEW LIVE</strong>
                    <button type="button" id="card-live-preview-close"
                            style="border: 0; background: none; color: #8a8aa0; cursor: pointer; font-size: 14px;">✕</button>
                </div>
                <iframe id="card-live-preview-frame" title="Preview"
                        style="width: 276px; height: 400px; border: 0; border-radius: 10px; background: #0b0712;"></iframe>
            </div>
            <script>
            (() => {
                const params = new URLSearchParams(location.search);
                const entityId = params.get('entityId');
                const form = document.querySelector('form[name="Card"]');
                if (!entityId || !form || params.get('crudAction') !== 'edit') { return; }

                const panel = document.getElementById('card-live-preview');
                const frame = document.getElementById('card-live-preview-frame');
                panel.hidden = false;
                document.getElementById('card-live-preview-close').addEventListener('click', () => { panel.hidden = true; });

                const field = (name) => form.querySelector(`[name="Card[${name}]"]`);
                const jsonValue = () => {
                    // EA's CodeEditor (CodeMirror) only syncs its textarea on submit
                    const cm = form.querySelector('.CodeMirror');
                    if (cm && cm.CodeMirror) { return cm.CodeMirror.getValue(); }
                    return field('visualConfigOverrideJson')?.value ?? '';
                };

                // the colour input always carries a value (#000000 by default): only
                // send it once the admin actually touched it
                let glowTouched = false;
                field('glowColor')?.addEventListener('input', () => { glowTouched = true; });

                let last = '';
                const refresh = () => {
                    const query = new URLSearchParams({ live: '1' });
                    const json = jsonValue().trim();
                    if (json && json !== '[]' && json !== '{}') { query.set('json', json); }
                    for (const [key, input] of [['holoEffect', field('holoEffect')], ['foilTexture', field('foilTexture')]]) {
                        if (input && input.value) { query.set(key, input.value); }
                    }
                    const glow = field('glowColor');
                    if (glowTouched && glow && glow.value) { query.set('glow', glow.value); }
                    const src = `/admin/card-preview/${entityId}?${query}`;
                    if (src !== last) { last = src; frame.src = src; }
                };

                refresh();
                form.addEventListener('change', () => setTimeout(refresh, 50));
                form.addEventListener('input', () => { clearTimeout(window.__cardPvT); window.__cardPvT = setTimeout(refresh, 600); });
                setInterval(refresh, 1500); // catches CodeMirror edits that fire no form event
            })();
            </script>
            HTML);
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
