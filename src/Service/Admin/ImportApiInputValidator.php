<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\Entity\CardRarityEnum;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Field-level validation of the import API multipart payloads (uploads included).
 */
final readonly class ImportApiInputValidator
{
    private const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @return array<string, list<string>>
     */
    public function validateExtension(Request $request): array
    {
        return $this->run($request, [
            'name' => $this->text(true, 255),
            'description' => $this->text(true),
            'image' => $this->image(false, self::IMAGE_TYPES),
            'logo' => $this->image(false, self::IMAGE_TYPES),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function validateCard(Request $request): array
    {
        return $this->run($request, [
            'name' => $this->text(true, 255),
            'description' => $this->text(true),
            'rarity' => [
                new Assert\NotBlank(message: 'Ce champ est requis.'),
                new Assert\Choice(
                    choices: array_map(static fn (CardRarityEnum $rarity): string => $rarity->value, CardRarityEnum::cases()),
                    message: 'Rareté invalide (attendu : common, uncommon, rare, legendary).',
                ),
            ],
            'image' => $this->image(true, self::IMAGE_TYPES),
            'mask' => $this->image(false, ['image/png']),
            'foil' => $this->image(false, self::IMAGE_TYPES),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toFieldErrors(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = trim($violation->getPropertyPath(), '[]');
            $errors[$field][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * @param array<string, list<Constraint>> $fields
     *
     * @return array<string, list<string>>
     */
    private function run(Request $request, array $fields): array
    {
        $input = $request->request->all();
        $data = [];
        foreach (array_keys($fields) as $field) {
            $data[$field] = $request->files->has($field) ? $request->files->all()[$field] : ($input[$field] ?? null);
        }

        return $this->toFieldErrors($this->validator->validate($data, new Assert\Collection(fields: $fields)));
    }

    /**
     * @param int<1, max>|null $maxLength
     *
     * @return list<Constraint>
     */
    private function text(bool $required, ?int $maxLength = null): array
    {
        $constraints = [new Assert\Type('string', message: 'Texte attendu.')];
        if ($required) {
            $constraints[] = new Assert\NotBlank(message: 'Ce champ est requis.', normalizer: 'trim');
        }
        if (null !== $maxLength) {
            $constraints[] = new Assert\Length(max: $maxLength);
        }

        return [new Assert\Sequentially($constraints)];
    }

    /**
     * @param non-empty-list<non-empty-string> $mimeTypes
     *
     * @return list<Constraint>
     */
    private function image(bool $required, array $mimeTypes): array
    {
        $constraints = [];
        if ($required) {
            $constraints[] = new Assert\NotNull(message: 'Fichier requis.');
        }
        $constraints[] = new Assert\Sequentially([
            new Assert\Type(UploadedFile::class, message: 'Fichier attendu (multipart).'),
            new Assert\Image(
                maxSize: '8M',
                mimeTypes: $mimeTypes,
                mimeTypesMessage: 'Format accepté : ' . strtoupper(implode(', ', array_map(static fn (string $type): string => substr($type, 6), $mimeTypes))) . '.',
            ),
        ]);

        return $constraints;
    }
}
