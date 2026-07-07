<?php

declare(strict_types=1);

namespace App\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Local replacement for Barlito\Utils\Traits\IdUuidTrait, whose `?string $id`
 * property clashes with the UuidType column: Doctrine keeps the ORIGINAL value
 * as a Uuid object while the property holds a string, so the changeset flags
 * `id` as modified on EVERY hydrated entity — any flush() then rewrites every
 * loaded row (`UPDATE ... SET id = <same>, updated_at = <now>`), trashing the
 * timestamps and multiplying queries.
 *
 * The property is typed Uuid here (no phantom changeset); getId() keeps
 * returning a ?string so every existing caller is unaffected.
 *
 * TODO: fix upstream in barlito/utils, then drop this local copy.
 */
trait IdUuidTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(name: 'id', type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[Groups(['default'])]
    private ?Uuid $id = null;

    public function getId(): ?string
    {
        return $this->id?->toRfc4122();
    }

    public function setId(string $id): self
    {
        $this->id = Uuid::fromString($id);

        return $this;
    }
}
