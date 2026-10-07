<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Dto\Duel\GameDataEntry;
use App\Dto\Duel\GameDataImportReport;
use App\Entity\Card;
use App\Exception\Duel\GameDataException;
use App\Repository\CardRepository;
use App\Service\Card\CardTags;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Syncs tags and the terrain flag from ytcg-game/data (cards/*.json playable, locations/*.json terrains), nothing else.
 */
final readonly class GameDataImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CardRepository $cardRepository,
    ) {
    }

    /**
     * @throws GameDataException
     */
    public function import(string $directory, bool $dryRun): GameDataImportReport
    {
        $changes = [];
        $unknown = [];
        $unchanged = 0;

        foreach ($this->read($directory) as $entry) {
            $card = $this->cardRepository->find($entry->id);
            if (!$card instanceof Card) {
                $unknown[] = $entry;
                continue;
            }

            $tagsBefore = $card->getTags();
            $tagsAfter = $entry->tags ?? $tagsBefore;
            if ($tagsBefore === $tagsAfter && $card->isTerrain() === $entry->terrain) {
                ++$unchanged;
                continue;
            }

            $changes[] = [
                'id' => $entry->id,
                'name' => $card->getName(),
                'tagsBefore' => $tagsBefore,
                'tagsAfter' => $tagsAfter,
                'terrainBefore' => $card->isTerrain(),
                'terrainAfter' => $entry->terrain,
            ];

            if (!$dryRun) {
                $card->setTags($tagsAfter)->setTerrain($entry->terrain);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new GameDataImportReport($changes, $unchanged, $unknown);
    }

    /**
     * @throws GameDataException
     *
     * @return array<string, GameDataEntry> by card id
     */
    public function read(string $directory): array
    {
        $directory = rtrim($directory, '/');
        $cardFiles = glob($directory . '/cards/*.json') ?: [];
        $locationFiles = glob($directory . '/locations/*.json') ?: [];
        if ([] === $cardFiles && [] === $locationFiles) {
            throw new GameDataException(\sprintf('No cards/*.json nor locations/*.json under "%s".', $directory));
        }

        $entries = [];
        foreach ([[$cardFiles, 'cards', false], [$locationFiles, 'locations', true]] as [$files, $key, $terrain]) {
            foreach ($files as $file) {
                foreach ($this->decode($file, $key) as $item) {
                    $entry = $this->entry($item, $terrain, basename(\dirname($file)) . '/' . basename($file));
                    if (isset($entries[$entry->id])) {
                        throw new GameDataException(\sprintf('Card %s is listed twice (%s and %s): a card is either playable or a terrain.', $entry->id, $entries[$entry->id]->source, $entry->source));
                    }
                    $entries[$entry->id] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<mixed>
     */
    private function decode(string $file, string $key): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GameDataException(\sprintf('%s: invalid JSON (%s).', $file, $exception->getMessage()), 0, $exception);
        }

        $items = \is_array($data) ? ($data[$key] ?? null) : null;
        if (!\is_array($items) || !array_is_list($items)) {
            throw new GameDataException(\sprintf('%s: a "%s" list is expected.', $file, $key));
        }

        return $items;
    }

    private function entry(mixed $item, bool $terrain, string $source): GameDataEntry
    {
        $id = \is_array($item) ? ($item['id'] ?? null) : null;
        if (!\is_array($item) || !\is_string($id) || !Uuid::isValid($id)) {
            throw new GameDataException(\sprintf('%s: an entry has no valid uuid "id".', $source));
        }
        $id = strtolower($id);
        $name = \is_string($item['name'] ?? null) ? $item['name'] : '?';

        $tags = null;
        if (\array_key_exists('tags', $item)) {
            if (!\is_array($item['tags'])) {
                throw new GameDataException(\sprintf('%s: "tags" of %s must be a list.', $source, $id));
            }
            $tags = array_values(array_filter(
                CardTags::normalize($item['tags']),
                static fn (string $tag): bool => CardTags::IMPLICIT_FAMILY !== CardTags::family($tag),
            ));
            foreach ($tags as $tag) {
                if (!CardTags::isValid($tag)) {
                    throw new GameDataException(\sprintf('%s: invalid tag "%s" on %s (expected family:value).', $source, $tag, $id));
                }
            }
        }

        return new GameDataEntry($id, $name, $terrain, $tags, $source);
    }
}
