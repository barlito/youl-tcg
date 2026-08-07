<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Hex colour input backing the visual-config cascade.
 *
 * Deliberately a TEXT input and not <input type="color">: a native colour
 * widget has no empty state, so it always submits a value (#000000 when
 * untouched) and cannot express "not set / inherit". A colour swatch is added
 * next to it client-side (App\Admin\VisualConfigFields::COLOR_PICKER_HTML) and
 * only writes into the field when the admin actually picks a colour.
 *
 * An empty submission means "inherit"; anything else must be a #rgb / #rrggbb
 * literal and is rejected with a form error rather than silently dropped.
 *
 * @extends AbstractType<string|null>
 */
final class HexColorType extends AbstractType
{
    /**
     * Also used as the HTML5 `pattern` attribute (no delimiters, no anchors).
     */
    public const string PATTERN = '#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})';

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $value = $event->getData();

            if (!\is_string($value) || '' === trim($value)) {
                return;
            }

            if (1 === preg_match('/^' . self::PATTERN . '$/', trim($value))) {
                return;
            }

            $event->getForm()->addError(new FormError('Couleur invalide : attendu un code hexadécimal comme #a435f0, ou vide.'));
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'trim' => true,
        ]);

        // merged instead of defaulted: EasyAdmin sets `attr` on its own fields
        $resolver->setNormalizer('attr', static fn (Options $options, mixed $attr): array => array_merge([
            'pattern' => self::PATTERN,
            'placeholder' => '#a435f0',
            'maxlength' => 7,
            'data-hex-color' => '',
        ], \is_array($attr) ? $attr : []));
    }

    #[\Override]
    public function getParent(): string
    {
        return TextType::class;
    }
}
