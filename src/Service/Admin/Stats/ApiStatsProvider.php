<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

use App\Enum\Admin\EconomyPeriodEnum;
use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\EconomyStatsProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Read-only game statistics for the admin API: one builder per section, the whole answer cached per (period, sections).
 */
final readonly class ApiStatsProvider
{
    public const int CACHE_TTL = 300;

    public const string VERSION_KEY = 'admin_api_stats_version';

    /**
     * @param iterable<StatsSectionInterface> $builders
     */
    public function __construct(
        #[AutowireIterator('app.admin_stats_section')]
        private iterable $builders,
        private EconomyStatsProvider $economy,
        private ClockInterface $clock,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param list<StatsSectionEnum> $sections empty = every section
     *
     * @return array<string, mixed>
     */
    public function get(EconomyPeriodEnum $period, array $sections = []): array
    {
        $wanted = [] === $sections ? StatsSectionEnum::cases() : array_values(array_filter(StatsSectionEnum::cases(), static fn (StatsSectionEnum $case): bool => \in_array($case, $sections, true)));
        // dropping the version token orphans every cached combination at once
        $version = $this->cache->get(self::VERSION_KEY, static fn (ItemInterface $item): string => bin2hex(random_bytes(4)));
        $key = \sprintf('admin_api_stats_%s_%d_%s', \is_string($version) ? $version : '0', $period->value, md5(implode(',', array_map(static fn (StatsSectionEnum $section): string => $section->value, $wanted))));

        $payload = $this->cache->get($key, function (ItemInterface $item) use ($period, $wanted): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->build($period, $wanted);
        });

        if (!\is_array($payload)) {
            throw new \LogicException('Unexpected admin stats cache payload.');
        }

        return $payload;
    }

    /**
     * @param list<StatsSectionEnum> $wanted
     *
     * @return array<string, mixed>
     */
    private function build(EconomyPeriodEnum $period, array $wanted): array
    {
        $start = $this->economy->today()->modify(\sprintf('-%d days', $period->value - 1));
        $context = new StatsContext($period, $this->clock->now(), $start, $this->economy->toUtc($start), $this->economy->listDays($start), $this->economy->today()->format('Y-m-d'));

        $builders = [];
        foreach ($this->builders as $builder) {
            $builders[$builder->section()->value] = $builder;
        }

        $result = [];

        foreach ($wanted as $section) {
            $builder = $builders[$section->value] ?? throw new \LogicException(\sprintf('No builder for the "%s" stats section.', $section->value));
            $result[$section->value] = $builder->build($context);
        }

        return $result;
    }
}
