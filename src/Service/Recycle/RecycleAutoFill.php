<?php

declare(strict_types=1);

namespace App\Service\Recycle;

// « Compléter automatiquement »: cheapest copies reaching BOOSTER_COST as a MINIMAL selection (RecycleService re-validates)
final class RecycleAutoFill
{
    /**
     * @param int                                                                                          $points     points of the current selection
     * @param int|null                                                                                     $cheapest   cheapest copy of the current selection (null = empty)
     * @param array<string, array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int}> $candidates card id => free copies
     *                                                                                                                 (`total` = copies of the card that may still leave, kinds combined)
     *
     * @return array<string, array{normal: int, holo: int}>|null copies to add, [] when the selection already reaches the cost, null when no minimal completion exists
     */
    public static function complete(int $points, ?int $cheapest, array $candidates, int $cost = RecycleService::BOOSTER_COST): ?array
    {
        if ($points >= $cost) {
            return [];
        }

        $edges = self::edges($candidates);
        if ([] === $edges) {
            return null;
        }

        $values = array_keys($edges);
        sort($values);
        $supply = [];
        foreach ($edges as $value => $cards) {
            $supply[$value] = array_sum(array_map(static fn (string $cardId): int => min($cards[$cardId], $candidates[$cardId]['total']), array_keys($cards)));
        }

        $vectors = [];
        self::enumerate($values, 0, [], 0, $points, $cheapest, $cost, $supply, $vectors);
        usort($vectors, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        foreach ($vectors as $vector) {
            $assignment = self::assign($vector['counts'], $edges, $candidates);
            if (null !== $assignment) {
                return $assignment;
            }
        }

        return null;
    }

    /**
     * value => card id => copies worth that value, cards with the most spare copies first.
     *
     * @param array<string, array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int}> $candidates
     *
     * @return array<int, array<string, int>>
     */
    private static function edges(array $candidates): array
    {
        $order = array_keys($candidates);
        usort($order, static fn (string $a, string $b): int => $candidates[$b]['total'] <=> $candidates[$a]['total'] ?: strcmp($a, $b));

        $edges = [];
        foreach ($order as $cardId) {
            $candidate = $candidates[(string) $cardId];
            if ($candidate['total'] < 1) {
                continue;
            }

            if ($candidate['normal'] > 0 && $candidate['normalPoints'] > 0) {
                $edges[$candidate['normalPoints']][(string) $cardId] = $candidate['normal'];
            }

            if ($candidate['holo'] > 0 && $candidate['holoPoints'] > 0) {
                $edges[$candidate['holoPoints']][(string) $cardId] = $candidate['holo'];
            }
        }

        return $edges;
    }

    /**
     * @param list<int>                                              $values  ascending
     * @param array<int, int>                                        $counts  value => copies picked so far
     * @param array<int, int>                                        $supply  value => copies available at most
     * @param list<array{counts: array<int, int>, score: list<int>}> $vectors
     */
    private static function enumerate(array $values, int $index, array $counts, int $added, int $points, ?int $cheapest, int $cost, array $supply, array &$vectors): void
    {
        $total = $points + $added;

        if ($total >= $cost) {
            $addedValues = array_keys(array_filter($counts));
            $min = min($cheapest ?? PHP_INT_MAX, ...$addedValues);

            if (!RecycleService::isOvershooting($total, $min)) {
                $squares = 0;
                $copies = 0;
                foreach ($counts as $value => $count) {
                    $squares += $value * $value * $count;
                    $copies += $count;
                }

                $vectors[] = ['counts' => array_filter($counts), 'score' => [max(0, ...$addedValues), $total, $squares, $copies]];
            }

            // adding more would only overshoot: a minimal selection stops at the cost
            return;
        }

        if ($index >= \count($values)) {
            return;
        }

        $value = $values[$index];
        $room = intdiv($cost - 1 - $total, $value) + 1;

        for ($count = min($supply[$value], $room); $count >= 0; --$count) {
            $counts[$value] = $count;
            self::enumerate($values, $index + 1, $counts, $added + $count * $value, $points, $cheapest, $cost, $supply, $vectors);
        }
    }

    /**
     * $counts copies per value within each kind's cap AND the card's shared cap; null when impossible.
     *
     * @param array<int, int>                                                                              $counts
     * @param array<int, array<string, int>>                                                               $edges
     * @param array<string, array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int}> $candidates
     *
     * @return array<string, array{normal: int, holo: int}>|null
     */
    private static function assign(array $counts, array $edges, array $candidates): ?array
    {
        $flow = [];
        $used = [];

        foreach ($counts as $value => $count) {
            for ($unit = 0; $unit < $count; ++$unit) {
                $visited = [];
                if (!self::augment($value, $edges, $candidates, $flow, $used, $visited)) {
                    return null;
                }
            }
        }

        $additions = [];
        foreach ($flow as $value => $cards) {
            foreach ($cards as $cardId => $copies) {
                if ($copies < 1) {
                    continue;
                }

                $kind = $candidates[$cardId]['normalPoints'] === $value ? 'normal' : 'holo';
                $additions[$cardId] ??= ['normal' => 0, 'holo' => 0];
                $additions[$cardId][$kind] += $copies;
            }
        }

        return $additions;
    }

    /**
     * One augmenting path value → card (→ other value → card …) in the residual graph.
     *
     * @param array<int, array<string, int>>                                                               $edges
     * @param array<string, array{normalPoints: int, holoPoints: int, normal: int, holo: int, total: int}> $candidates
     * @param array<int, array<string, int>>                                                               $flow
     * @param array<string, int>                                                                           $used
     * @param array<string, true>                                                                          $visited
     */
    private static function augment(int $value, array $edges, array $candidates, array &$flow, array &$used, array &$visited): bool
    {
        foreach ($edges[$value] ?? [] as $cardId => $cap) {
            $cardId = (string) $cardId;
            if (isset($visited[$cardId]) || ($flow[$value][$cardId] ?? 0) >= $cap) {
                continue;
            }

            $visited[$cardId] = true;

            if (($used[$cardId] ?? 0) < $candidates[$cardId]['total']) {
                $flow[$value][$cardId] = ($flow[$value][$cardId] ?? 0) + 1;
                $used[$cardId] = ($used[$cardId] ?? 0) + 1;

                return true;
            }

            // the card is full: move one of its copies of another value elsewhere
            foreach ($flow as $other => $cards) {
                if ($other === $value || ($cards[$cardId] ?? 0) < 1) {
                    continue;
                }

                if (self::augment($other, $edges, $candidates, $flow, $used, $visited)) {
                    --$flow[$other][$cardId];
                    $flow[$value][$cardId] = ($flow[$value][$cardId] ?? 0) + 1;

                    return true;
                }
            }
        }

        return false;
    }
}
