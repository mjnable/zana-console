<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Console\Release\ComposerLocator;
use Zana\Console\Release\Process;
use Zana\Console\Release\ReleaseBuilder;
use Zana\Console\Release\ReleaseException;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\SecurityRules;
use Zana\Core\Inspection\Severity;

/**
 * php bin/console release:build [--folder=website] [--ref=HEAD] [--no-public] [-v]
 *
 * Writes build/<folder>.zip and build/public_html.zip from a commit, with the
 * production dependencies installed, for shared hosting without Composer
 * (ADR-021). See ReleaseBuilder.
 *
 * With security.strict (2.21, FE-68), it refuses to build while a security
 * rule of `check` reports an error.
 */
#[AsCommand(name: 'release:build', description: 'Build the upload archives for shared hosting from a commit')]
final class ReleaseBuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'The folder that holds the project on the server, beside public_html', 'website')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'The commit, branch or tag to build', 'HEAD')
            ->addOption('no-public', null, InputOption::VALUE_NONE, 'Leave public/ out of the code archive, when public_html.zip is the only copy that is served')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Where to write the archives', 'build')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'The Composer command, or the path of composer.phar (default: found on the PATH)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds before git or Composer is stopped', '600');
    }

    public function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ApplicationInterface $application): int
    {
        if (!self::passesSecurityRules($application, $io)) {
            return self::FAILURE;
        }

        $folder = (string) $input->getOption('folder');
        $target = (string) $input->getOption('output');
        $target = preg_match('#^([A-Za-z]:)?[/\\\\]#', $target) === 1 ? $target : $application->path($target);
        $composer = ComposerLocator::fromEnvironment()->locate(is_string($input->getOption('composer')) ? $input->getOption('composer') : null);
        $timeout = max(1, (int) $input->getOption('timeout'));

        $io->writeln('Composer: ' . implode(' ', $composer), OutputInterface::VERBOSITY_VERBOSE);
        $echo = $output->isVerbose() ? static function (string $chunk) use ($output): void {
            $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
        } : null;

        try {
            $archives = (new ReleaseBuilder($application->path(), $composer, new Process($timeout, $echo)))
                ->build($target, $folder, (string) $input->getOption('ref'), !$input->getOption('no-public'));
        } catch (ReleaseException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(['Built:', $archives['code'], $archives['public']]);
        $io->listing([
            "Unpack {$folder}.zip beside public_html, so that the project is in ~/{$folder}.",
            'Unpack public_html.zip into public_html.',
            "Create ~/{$folder}/.env with APP_ENV=production, APP_APP__URL and APP_SECURITY__SECRET.",
            "Delete ~/{$folder}/var/cache after each upload, so the container and configuration are read again.",
        ]);

        return self::SUCCESS;
    }

    /**
     * The gate (2.21, FE-68, ADR-033): the security rules of `check` run
     * first. With security.strict, an error among them stops the build;
     * without it, they are printed and the build goes on, as before 2.21.
     */
    private static function passesSecurityRules(ApplicationInterface $application, SymfonyStyle $io): bool
    {
        $strict = SecurityRules::strict($application);
        try {
            $checks = $application->container()->tagged(ApplicationCheck::TAG);
            $findings = [];
            foreach ($checks as $check) {
                if ($check instanceof ApplicationCheck) {
                    array_push($findings, ...array_filter($check->findings($application), static fn (Finding $f): bool => $f->security));
                }
            }
        } catch (\Throwable $e) {
            $message = 'The security rules could not run: ' . strtok($e->getMessage(), "\n");
            $strict ? $io->error([$message, 'Nothing was built. Run php bin/console check to see why.']) : $io->warning($message);

            return !$strict;
        }

        $errors = array_values(array_filter($findings, static fn (Finding $f): bool => $f->severity === Severity::Error));
        $lines = array_map(static fn (Finding $f): string => "[{$f->id}] {$f->message}" . ($f->file === null ? '' : " ({$f->file})"), $findings);
        if ($errors !== []) {
            $io->error(['Nothing was built: the security rules of check report ' . count($errors) . ' error(s) (security.strict).', ...$lines]);

            return false;
        }
        if ($lines !== []) {
            $io->warning(['The security rules of check report these; with security.strict they would stop the build:', ...$lines]);
        }

        return true;
    }
}
