<?php

declare(strict_types=1);

namespace App\Admin\Field;

use App\Form\CardTagsType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Own field class: EasyAdmin would guess an ArrayField (collection of text inputs) for the JSON column.
 */
final class CardTagsField implements FieldInterface
{
    use FieldTrait;

    /**
     * @param TranslatableInterface|string|false|null $label
     */
    public static function new(string $propertyName, $label = null): self
    {
        return new self()
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(CardTagsType::class)
            // EasyAdmin derives "required" from the non-nullable column: an empty list is valid
            ->setRequired(false)
            ->setTemplateName('crud/field/text')
            ->formatValue(static fn (mixed $value): string => \is_array($value) ? implode(', ', $value) : '')
        ;
    }
}
