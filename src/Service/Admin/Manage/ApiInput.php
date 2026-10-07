<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Exception\Admin\ManageApiException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Typed reader of a management payload (JSON body or form fields): collects one
 * message per bad field instead of failing on the first, and refuses unknown keys.
 */
final class ApiInput
{
    private const array TRUE_VALUES = [true, 1, '1', 'true'];

    private const array FALSE_VALUES = [false, 0, '0', 'false'];

    /**
     * @var array<string, list<string>>
     */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromRequest(Request $request): self
    {
        if ('json' === $request->getContentTypeFormat() || (null === $request->getContentTypeFormat() && '' !== trim($request->getContent()))) {
            try {
                $decoded = $request->toArray();
            } catch (\Throwable) {
                throw ManageApiException::invalid(['body' => ['JSON invalide : un objet est attendu.']]);
            }

            return new self($decoded);
        }

        return new self($request->request->all());
    }

    /**
     * @param list<string> $allowed
     */
    public function allowOnly(array $allowed): self
    {
        foreach (array_diff(array_keys($this->data), $allowed) as $unknown) {
            $this->error((string) $unknown, 'Champ inconnu.');
        }

        return $this;
    }

    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->data);
    }

    public function isEmpty(): bool
    {
        return [] === $this->data;
    }

    public function error(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function string(string $field, bool $nullable = false, bool $allowBlank = false): ?string
    {
        $value = $this->data[$field] ?? null;
        if (null === $value && $nullable) {
            return null;
        }
        if (!\is_string($value)) {
            $this->error($field, $nullable ? 'Texte ou null attendu.' : 'Texte attendu.');

            return null;
        }
        $value = trim($value);
        if ('' === $value && !$allowBlank) {
            if ($nullable) {
                return null;
            }
            $this->error($field, 'Ce champ ne peut pas être vide.');

            return null;
        }

        return $value;
    }

    public function bool(string $field): ?bool
    {
        $value = $this->data[$field] ?? null;
        if (\in_array($value, self::TRUE_VALUES, true)) {
            return true;
        }
        if (\in_array($value, self::FALSE_VALUES, true)) {
            return false;
        }
        $this->error($field, 'Booléen attendu (true, false, 1, 0).');

        return null;
    }

    public function int(string $field, bool $nullable = false, int $max = 2_147_483_647): ?int
    {
        $value = $this->data[$field] ?? null;
        if ((null === $value || '' === $value) && $nullable) {
            return null;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d{1,18}$/', $value)) {
            $value = (int) $value;
        }
        if (!\is_int($value)) {
            $this->error($field, $nullable ? 'Entier ou null attendu.' : 'Entier attendu.');

            return null;
        }
        if ($value > $max) {
            $this->error($field, \sprintf('Doit être inférieur ou égal à %d.', $max));

            return null;
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public function enum(string $field, string $enum): ?\BackedEnum
    {
        $value = $this->data[$field] ?? null;
        if (\is_string($value)) {
            $needle = strtolower(trim($value));
            foreach ($enum::cases() as $case) {
                if (strtolower($case->name) === $needle || strtolower((string) $case->value) === $needle) {
                    return $case;
                }
            }
        }
        $names = implode(', ', array_map(static fn (\BackedEnum $case): string => strtolower($case->name), $enum::cases()));
        $this->error($field, \sprintf('Valeur invalide (attendu : %s).', $names));

        return null;
    }

    /**
     * A JSON structure: already decoded in a JSON body, a JSON string in a multipart field.
     *
     * @return array<array-key, mixed>|null
     */
    public function structure(string $field): ?array
    {
        $value = $this->data[$field] ?? null;
        if (\is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!\is_array($value)) {
            $this->error($field, 'Structure JSON attendue.');

            return null;
        }

        return $value;
    }
}
