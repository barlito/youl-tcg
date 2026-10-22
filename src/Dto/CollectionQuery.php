<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Collection\CollectionFinishEnum;
use App\Enum\Collection\CollectionPossessionEnum;
use App\Enum\Collection\CollectionSortEnum;
use App\Enum\Entity\CardRarityEnum;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The collection filters read from the url. Unknown values fall back to the
 * default instead of failing: a stale or hand-edited link still renders.
 */
final readonly class CollectionQuery
{
    public const int MAX_SEARCH_LENGTH = 60;

    public function __construct(
        public CollectionPossessionEnum $possession = CollectionPossessionEnum::ALL,
        public string $search = '',
        public ?CardRarityEnum $rarity = null,
        public ?CollectionFinishEnum $finish = null,
        public CollectionSortEnum $sort = CollectionSortEnum::UNIVERSE,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        return new self(
            CollectionPossessionEnum::tryFrom(self::scalar($query, 'filtre')) ?? CollectionPossessionEnum::ALL,
            mb_substr(trim(self::scalar($query, 'q')), 0, self::MAX_SEARCH_LENGTH),
            CardRarityEnum::tryFrom(self::scalar($query, 'rarete')),
            CollectionFinishEnum::tryFrom(self::scalar($query, 'finition')),
            CollectionSortEnum::tryFrom(self::scalar($query, 'tri')) ?? CollectionSortEnum::UNIVERSE,
        );
    }

    /**
     * Url parameters of this query, defaults omitted; $overrides replaces (or, with null, drops) some of them.
     *
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    public function params(array $overrides = []): array
    {
        $params = array_merge([
            'filtre' => CollectionPossessionEnum::ALL === $this->possession ? null : $this->possession->value,
            'q' => '' === $this->search ? null : $this->search,
            'rarete' => $this->rarity?->value,
            'finition' => $this->finish?->value,
            'tri' => CollectionSortEnum::UNIVERSE === $this->sort ? null : $this->sort->value,
        ], $overrides);

        return array_filter($params, static fn (?string $value): bool => null !== $value && '' !== $value);
    }

    /**
     * Filters hidden behind the mobile « Filtres · n » button (the search box stays visible).
     */
    public function panelFilterCount(): int
    {
        return (int) (CollectionPossessionEnum::ALL !== $this->possession)
            + (int) ($this->rarity instanceof CardRarityEnum)
            + (int) ($this->finish instanceof CollectionFinishEnum)
            + (int) (CollectionSortEnum::UNIVERSE !== $this->sort);
    }

    public function isFiltered(): bool
    {
        return '' !== $this->search || $this->panelFilterCount() > 0;
    }

    /**
     * @param InputBag<string> $query
     */
    private static function scalar(InputBag $query, string $key): string
    {
        $value = $query->all()[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
