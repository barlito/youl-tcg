<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dto\VisualConfig;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\CardFrameEnum;
use App\Enum\Card\CardNameFontEnum;
use App\Enum\Card\FoilTextureEnum;
use App\Form\HexColorType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use Symfony\Component\Form\Extension\Core\Type\RangeType;

/**
 * The visual-config widgets shared by the Extension and Card CRUDs: one widget
 * per VisualConfig key, no JSON anywhere. Both CRUDs yield the very same list
 * so a card can always override whatever its universe configures — the only
 * difference is the wording of the empty state.
 *
 * Empty state = "not set": the value falls through the cascade (card override
 * -> extension config -> system default). Every widget can express it natively
 * except the foil zoom, whose range input has no empty state and therefore
 * relies on the FOIL_SIZE_AUTO sentinel (leftmost position, rendered as
 * "Auto" by FoilSizeSliderScript).
 */
final class VisualConfigFields
{
    /**
     * Companion of HexColorType: turns each hex text input into a text field +
     * native colour swatch + "hériter" reset. The swatch only writes on pick,
     * so an untouched field stays empty (unlike a bare <input type="color">).
     */
    public const string COLOR_PICKER_HTML = <<<'HTML'
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('input[data-hex-color]').forEach((input) => {
                const notify = () => input.dispatchEvent(new Event('input', { bubbles: true }));

                const swatch = document.createElement('input');
                swatch.type = 'color';
                swatch.tabIndex = -1;
                swatch.setAttribute('aria-label', 'Choisir une couleur');
                swatch.style.cssText = 'margin-left: 8px; vertical-align: middle; width: 44px; height: 34px; padding: 2px; cursor: pointer;';
                swatch.value = /^#[0-9a-fA-F]{6}$/.test(input.value) ? input.value : '#a435f0';

                const reset = document.createElement('button');
                reset.type = 'button';
                reset.className = 'btn btn-sm btn-link';
                reset.textContent = 'hériter';
                reset.title = 'Vider le champ : la valeur du niveau supérieur s\'applique';

                input.style.display = 'inline-block';
                input.style.width = '10em';
                input.after(reset);
                input.after(swatch);

                swatch.addEventListener('input', () => { input.value = swatch.value; notify(); });
                reset.addEventListener('click', () => { input.value = ''; notify(); });
                input.addEventListener('input', () => {
                    if (/^#[0-9a-fA-F]{6}$/.test(input.value)) { swatch.value = input.value; }
                });
            });
        });
        </script>
        HTML;

    /**
     * @param bool $isOverride true for the Card CRUD (an empty widget inherits
     *                         from the universe), false for the Extension CRUD
     *                         (an empty widget means no set-wide default)
     *
     * @return iterable<FieldInterface>
     */
    public static function fields(bool $isOverride): iterable
    {
        $empty = $isOverride ? '— hériter de l\'univers —' : '— aucun (rendu par défaut) —';
        $emptyHelp = $isOverride
            ? 'Vide : la carte reprend le réglage de son univers.'
            : 'Vide : aucun réglage de set, les cartes gardent le rendu par défaut.';

        yield ChoiceField::new('holoEffect')
            ->setLabel('Preset holo')
            ->setChoices(self::enumChoices(CardEffectEnum::cases()))
            ->setFormTypeOption('choice_value', self::enumValue())
            ->setFormTypeOption('placeholder', $empty)
            ->setRequired(false)
            ->setHelp('Recette holo utilisée quand la carte sort en holo. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield ChoiceField::new('foilTexture')
            ->setLabel('Texture foil (bibliothèque)')
            ->setChoices(self::enumChoices(FoilTextureEnum::cases()))
            ->setFormTypeOption('choice_value', self::enumValue())
            ->setFormTypeOption('placeholder', $empty)
            ->setRequired(false)
            ->setHelp('Foil intégré utilisé tant qu\'aucun fichier foil n\'est envoyé sur la carte. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield Field::new('foilSize')
            ->setLabel('Zoom du foil')
            ->setFormType(RangeType::class)
            ->setFormTypeOption('attr', [
                'min' => VisualConfig::FOIL_SIZE_AUTO,
                'max' => VisualConfig::FOIL_SIZE_MAX,
                'step' => 5,
                'data-foil-size' => '',
            ])
            ->setRequired(false)
            ->setHelp(\sprintf(
                'Un curseur n\'a pas d\'état vide : la position tout à gauche (« Auto ») vaut donc « non réglé » et %s. 100 %% = cover (pleine carte), entre les deux = motif tilé de N %% de la largeur.',
                $isOverride ? 'la carte reprend le zoom de son univers' : 'le preset holo décide seul du zoom',
            ))
            ->onlyOnForms()
        ;

        yield Field::new('glowColor')
            ->setLabel('Halo (glow)')
            ->setFormType(HexColorType::class)
            ->setRequired(false)
            ->setHelp('Couleur du halo autour de la carte, en hexadécimal. ' . ($isOverride
                ? 'Vide : halo de l\'univers, sinon couleur de rareté.'
                : 'Vide : couleur de rareté de chaque carte.'))
            ->onlyOnForms()
        ;

        yield Field::new('borderColor')
            ->setLabel('Couleur de bordure')
            ->setFormType(HexColorType::class)
            ->setRequired(false)
            ->setHelp('Accent coloré du liseré de la carte, en hexadécimal. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield ChoiceField::new('frame')
            ->setLabel('Cadre CSS')
            ->setChoices(self::enumChoices(CardFrameEnum::cases()))
            ->setFormTypeOption('choice_value', self::enumValue())
            ->setFormTypeOption('placeholder', $empty)
            ->setRequired(false)
            ->setHelp('Habillage dessiné en CSS par-dessus l\'artwork (nom, wordmark, filigrane). « Aucun » pour un artwork qui embarque déjà son cadre. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield ChoiceField::new('nameFont')
            ->setLabel('Police du nom')
            ->setChoices(self::enumChoices(CardNameFontEnum::cases()))
            ->setFormTypeOption('choice_value', self::enumValue())
            ->setFormTypeOption('placeholder', $empty)
            ->setRequired(false)
            ->setHelp('Police du nom écrit sur le cadre. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield Field::new('frameLineStart')
            ->setLabel('Liseré — couleur de départ')
            ->setFormType(HexColorType::class)
            ->setRequired(false)
            ->setHelp('Début du dégradé du liseré néon du cadre, en hexadécimal. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield Field::new('frameLineEnd')
            ->setLabel('Liseré — couleur d\'arrivée')
            ->setFormType(HexColorType::class)
            ->setRequired(false)
            ->setHelp('Fin du dégradé du liseré néon du cadre, en hexadécimal. ' . $emptyHelp)
            ->onlyOnForms()
        ;

        yield Field::new('cssClass')
            ->setLabel('Classe CSS additionnelle')
            ->setRequired(false)
            ->setFormTypeOption('attr', ['placeholder' => 'ex. promo-2026'])
            ->setHelp('Classe ajoutée à l\'élément .card pour un habillage sur mesure. ' . $emptyHelp)
            ->onlyOnForms()
        ;
    }

    /**
     * Stable HTML values for the enum selects: EasyAdmin would otherwise submit
     * the choice INDEX, which silently shifts whenever a case is reordered.
     */
    private static function enumValue(): \Closure
    {
        return static fn (mixed $case): ?string => $case instanceof \BackedEnum ? (string) $case->value : null;
    }

    /**
     * @param list<CardEffectEnum|CardFrameEnum|CardNameFontEnum|FoilTextureEnum> $cases
     *
     * @return array<string, CardEffectEnum|CardFrameEnum|CardNameFontEnum|FoilTextureEnum> label => enum case
     */
    private static function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
