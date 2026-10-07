<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Dto\VisualConfig;
use App\Form\HexColorType;

/**
 * Merges a visual-config payload into the stored one, refusing what the model would silently drop.
 */
final class VisualConfigPatch
{
    private const array COLOR_KEYS = ['glow', 'borderColor', 'frameLineStart', 'frameLineEnd'];

    /**
     * A null value clears its key, an absent key is kept; a null payload clears everything.
     *
     * @param array<array-key, mixed>|null $changes
     */
    public static function apply(VisualConfig $current, ?array $changes, ApiInput $input, string $field): VisualConfig
    {
        if (null === $changes) {
            return new VisualConfig();
        }

        $known = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), new \ReflectionClass(VisualConfig::class)->getConstructor()?->getParameters() ?? []);
        $clean = [];
        foreach ($changes as $key => $value) {
            if (!\in_array($key, $known, true)) {
                $input->error($field, \sprintf('Clé inconnue « %s » (attendu : %s).', $key, implode(', ', $known)));

                continue;
            }
            if (null !== $value && !\is_string($value) && !\is_int($value)) {
                $input->error($field, \sprintf('« %s » : texte, entier ou null attendu.', $key));

                continue;
            }
            $clean[$key] = \is_string($value) ? trim($value) : $value;
        }

        $merged = $current->merge($clean);
        $result = $merged->toArray();
        foreach ($clean as $key => $value) {
            if (null === $value || '' === $value) {
                continue;
            }
            $accepted = ($result[$key] ?? null) === $value
                && (!\in_array($key, self::COLOR_KEYS, true) || 1 === preg_match('/^' . HexColorType::PATTERN . '$/', (string) $value));
            if (!$accepted) {
                $input->error($field, \sprintf('Valeur invalide pour « %s ».', $key));
            }
        }

        return $merged;
    }
}
