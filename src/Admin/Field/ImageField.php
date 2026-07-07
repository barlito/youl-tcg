<?php

declare(strict_types=1);

namespace App\Admin\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Contracts\Translation\TranslatableInterface;
use Vich\UploaderBundle\Form\Type\VichImageType;

final class ImageField implements FieldInterface
{
    use FieldTrait;

    /**
     * @param TranslatableInterface|string|false|null $label
     */
    public static function new(string $propertyName, $label = null, bool $allowDelete = false): self
    {
        return new self()
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(VichImageType::class)
            // allow_delete shows the "delete" checkbox so an admin can clear an
            // uploaded image from the edit form. Opt-in only: optional images
            // (foil / mask) enable it, the main artwork of a published card or
            // booster must never be clearable (imageName is nullable, nothing
            // would block a broken <img src=""> in every card render).
            ->setFormTypeOption('allow_delete', $allowDelete)
        ;
    }
}
