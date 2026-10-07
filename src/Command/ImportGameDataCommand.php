<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\Duel\GameDataEntry;
use App\Exception\Duel\GameDataException;
use App\Service\Duel\GameDataImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:duel:import-game-data', description: 'Set card tags and the terrain flag from the duel game data directory (ytcg-game/data), safe to replay')]
final class ImportGameDataCommand extends Command
{
    public function __construct(private readonly GameDataImporter $importer)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'The game data directory, holding cards/*.json and locations/*.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes, write nothing')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = $this->importer->import((string) $input->getArgument('path'), $dryRun);
        } catch (GameDataException $exception) {
            $io->error($exception->getMessage() . ' Nothing was written.');

            return Command::FAILURE;
        }

        if ([] !== $report->changes) {
            $io->table(
                ['Card', 'Id', 'Tags', 'Terrain'],
                array_map(static fn (array $change): array => [
                    $change['name'],
                    $change['id'],
                    $change['tagsBefore'] === $change['tagsAfter'] ? implode(', ', $change['tagsAfter']) : \sprintf('[%s] → [%s]', implode(', ', $change['tagsBefore']), implode(', ', $change['tagsAfter'])),
                    $change['terrainBefore'] === $change['terrainAfter'] ? ($change['terrainAfter'] ? 'yes' : 'no') : \sprintf('%s → %s', $change['terrainBefore'] ? 'yes' : 'no', $change['terrainAfter'] ? 'yes' : 'no'),
                ], $report->changes),
            );
        }

        if ([] !== $report->unknown) {
            $io->warning(\sprintf('%d uuid(s) unknown to this database, skipped:', \count($report->unknown)));
            $io->listing(array_map(static fn (GameDataEntry $entry): string => \sprintf('%s %s (%s)', $entry->id, $entry->name, $entry->source), $report->unknown));
        }

        $summary = \sprintf('%d card(s) %s, %d already up to date, %d unknown.', \count($report->changes), $dryRun ? 'would change' : 'updated', $report->unchanged, \count($report->unknown));
        $dryRun ? $io->note('Dry run: ' . $summary) : $io->success($summary);

        return Command::SUCCESS;
    }
}
