<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Deployment\CheckStatus;
use Zana\Core\Deployment\DeploymentChecks;

/**
 * Checks the server before going live (FE-18): the PHP version and the
 * extensions the application needs (app.extensions), a writable var/
 * directory, the length of security.secret, and every deployment check the
 * application runs at startup. Exits with 1 when something is fatal.
 */
#[AsCommand(name: 'doctor', description: 'Check that this server is ready to run the application')]
final class DoctorCommand extends Command
{
    public function handle(SymfonyStyle $io, ApplicationInterface $application): int
    {
        $config = $application->config();
        /** @var list<array{CheckStatus, string}> $results */
        $results = [];

        $results[] = version_compare(PHP_VERSION, '8.5.0', '>=')
            ? [CheckStatus::Ok, 'PHP ' . PHP_VERSION]
            : [CheckStatus::Fatal, 'PHP ' . PHP_VERSION . ' is too old: Zana 2 needs 8.5 or later.'];

        /** @var list<string> $extensions */
        $extensions = $config->array('app.extensions', []);
        foreach (array_unique(['json', 'mbstring', ...$extensions]) as $extension) {
            $results[] = extension_loaded($extension)
                ? [CheckStatus::Ok, "Extension {$extension}"]
                : [CheckStatus::Fatal, "The PHP extension {$extension} is missing."];
        }

        $var = $application->path('var');
        $results[] = is_dir($var) ? (is_writable($var) ? [CheckStatus::Ok, "{$var} is writable"] : [CheckStatus::Fatal, "{$var} is not writable."]) : [CheckStatus::Warning, "{$var} does not exist; the application creates it on first write."];

        if ($config->has('security.secret')) {
            $results[] = strlen($config->string('security.secret')) >= 32
                ? [CheckStatus::Ok, 'security.secret is long enough']
                : [CheckStatus::Fatal, 'security.secret must be at least 32 bytes.'];
        }

        foreach (DeploymentChecks::run($application, $application instanceof Application ? $application->containerCache() : null) as [$check, $result]) {
            foreach ($result->status === CheckStatus::Ok ? ['Deployment checks: ' . substr((string) strrchr('\\' . $check, '\\'), 1)] : explode("\n", $result->message) as $message) {
                $results[] = [$result->status, $message];
            }
        }

        $fatal = false;
        foreach ($results as [$status, $message]) {
            match ($status) {
                CheckStatus::Ok => $io->writeln("  <info>✔</info> {$message}"),
                CheckStatus::Warning => $io->writeln("  <comment>!</comment> {$message}"),
                CheckStatus::Fatal => $io->writeln("  <error>✘</error> {$message}"),
            };
            $fatal = $fatal || $status === CheckStatus::Fatal;
        }

        $io->newLine();
        $fatal ? $io->error('This server is not ready.') : $io->success('This server is ready.');

        return $fatal ? self::FAILURE : self::SUCCESS;
    }
}
