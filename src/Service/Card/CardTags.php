<?php

declare(strict_types=1);

namespace App\Service\Card;

/**
 * Card tags (`family:value`) shared by the duel game and the collection filters.
 */
final class CardTags
{
    public const string PATTERN = '/^[a-z]+:[a-z0-9-]+$/';

    // universe:<slug> is implied by the card's extension, never stored
    public const string IMPLICIT_FAMILY = 'universe';

    public const int MAX_TAGS = 12;

    private const array FAMILY_LABELS = [
        'character' => 'Personnages',
        'family' => 'Familles',
        'trait' => 'Traits',
    ];

    /**
     * Trimmed, lowercased, deduplicated and sorted; validity is the validator's job.
     *
     * @param iterable<mixed> $tags
     *
     * @return list<string>
     */
    public static function normalize(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                continue;
            }
            $tag = strtolower(trim($tag));
            if ('' !== $tag) {
                $normalized[$tag] = true;
            }
        }
        $normalized = array_map(strval(...), array_keys($normalized));
        sort($normalized);

        return $normalized;
    }

    public static function isValid(string $tag): bool
    {
        return 1 === preg_match(self::PATTERN, $tag) && self::IMPLICIT_FAMILY !== self::family($tag);
    }

    public static function family(string $tag): string
    {
        return explode(':', $tag, 2)[0];
    }

    public static function familyLabel(string $family): string
    {
        return self::FAMILY_LABELS[$family] ?? ucfirst($family);
    }

    public static function valueLabel(string $tag): string
    {
        $value = explode(':', $tag, 2)[1] ?? $tag;

        return ucfirst(str_replace('-', ' ', $value));
    }

    /**
     * Known families first (character, family, trait), then the others alphabetically.
     */
    public static function compareFamilies(string $a, string $b): int
    {
        $order = array_keys(self::FAMILY_LABELS);
        $rankA = array_search($a, $order, true);
        $rankB = array_search($b, $order, true);

        return [false === $rankA ? \PHP_INT_MAX : $rankA, $a] <=> [false === $rankB ? \PHP_INT_MAX : $rankB, $b];
    }
}
