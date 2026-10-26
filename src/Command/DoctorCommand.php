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
 * directory, the length of security.secret, every deployment check the
 * application runs at startup, and that every singleton can be created: a
 * factory that resolves a scoped service, which the build cannot see, fails
 * here rather than in traffic (FE-40). Exits with 1 when something is fatal.
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

        $caches = $application instanceof Application ? [$application->containerCache(), $application->environment()->isProduction() ? $application->configurationCache() : null] : [null, null];
        foreach (DeploymentChecks::run($application, ...$caches) as [$check, $result]) {
            foreach ($result->status === CheckStatus::Ok ? ['Deployment checks: ' . substr((string) strrchr('\\' . $check, '\\'), 1)] : explode("\n", $result->message) as $message) {
                $results[] = [$result->status, $message];
            }
        }

        array_push($results, ...self::singletons($application));

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

    /**
     * Create every singleton once, from the root container, as the first request would.
     *
     * @return list<array{CheckStatus, string}>
     */
    private static function singletons(ApplicationInterface $application): array
    {
        $container = $application->container();
        $results = [];
        $created = 0;
        $seen = [];
        foreach ($container->graph()->nodes() as $node) {
            $id = $node->service . "\0" . $node->key;
            if ($node->lifetime !== 'singleton' || $node->strategy === 'instance' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            try {
                $node->key === null ? $container->get($node->service) : $container->getKeyed($node->service, $node->key);
                $created++;
            } catch (\Throwable $e) {
                $label = $node->service . ($node->key === null ? '' : " [key: {$node->key}]");
                $results[] = [CheckStatus::Fatal, "The singleton {$label} cannot be created: " . strtok($e->getMessage(), "\n")];
            }
        }

        return $results === [] ? [[CheckStatus::Ok, "All {$created} singletons can be created"]] : $results;
    }
}
