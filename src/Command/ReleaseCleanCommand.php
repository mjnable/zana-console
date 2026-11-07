<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Console\Release\LiveCleaner;
use Zana\Console\Release\ReleaseException;
use Zana\Core\ApplicationInterface;

/**
 * php bin/console release:clean [--dry-run] [--force] [--no-public]
 *
 * Applies the rules of release:build on a live server, for projects that did
 * not arrive as its archive (FE-66). It changes nothing unless the
 * environment is production; --dry-run works anywhere. See LiveCleaner.
 */
#[AsCommand(name: 'release:clean', description: 'Apply the release exclusion rules to the project on a live server')]
final class ReleaseCleanCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would go, and change nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete without asking, outside a Git checkout')
            ->addOption('no-public', null, InputOption::VALUE_NONE, 'Remove public/ too, when the document root is elsewhere (split layout)');
    }

    public function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ApplicationInterface $application): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        if (!$dryRun && !$application->environment()->isProduction()) {
            $io->error('release:clean runs on a live server only (APP_ENV=production). Nothing was changed.');

            return self::FAILURE;
        }

        $cleaner = new LiveCleaner($application->path());
        try {
            ['paths' => $paths, 'unsupported' => $unsupported] = $cleaner->rules((bool) $input->getOption('no-public'));
            $present = $cleaner->present($paths);

            if ($unsupported !== []) {
                $io->warning(['These export-ignore patterns are not applied here, because only plain paths are:', ...$unsupported]);
            }
            if ($present !== []) {
                $io->writeln($dryRun ? 'Would remove:' : 'To remove:');
                $io->listing($present);
            }
            if ($dryRun) {
                $io->success($present === [] ? 'Nothing to remove.' : 'Dry run: nothing was changed.');

                return self::SUCCESS;
            }

            if (!$cleaner->usesGit() && $present !== [] && !$input->getOption('force') && !$io->confirm('These files are deleted for good. Continue?', false)) {
                $io->warning('Nothing was deleted. Pass --force to run without this question.');

                return self::FAILURE;
            }

            $remaining = $cleaner->clean($paths);
        } catch (ReleaseException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($remaining !== []) {
            $io->warning(['Still here, because Git keeps modified and untracked files. Review them:', ...$remaining]);

            return self::SUCCESS;
        }
        $io->success($cleaner->usesGit() ? 'The checkout follows the exclusion rules, and git pull keeps working.' : 'The project follows the exclusion rules.');

        return self::SUCCESS;
    }
}
