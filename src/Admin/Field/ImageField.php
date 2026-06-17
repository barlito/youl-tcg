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
    public static function new(string $propertyName, $label = null, bool $allowDelete = true): self
    {
        return new self()
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(VichImageType::class)
            // allow_delete shows the "delete" checkbox so an admin can clear an
            // uploaded image (foil / mask / artwork) from the edit form
            ->setFormTypeOption('allow_delete', $allowDelete)
        ;
    }
}
