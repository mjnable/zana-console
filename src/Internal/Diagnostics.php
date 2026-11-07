<?php

declare(strict_types=1);

namespace Zana\Console\Internal;

use Zana\Console\Release\ExclusionRules;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Deployment\CheckStatus;
use Zana\Core\Deployment\DeploymentChecks;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * The checks doctor and check share.
 *
 * @internal
 */
final class Diagnostics
{
    /**
     * Every deployment check the application runs at startup.
     */
    public static function deploymentChecks(ApplicationInterface $application, Report $report): void
    {
        $caches = $application instanceof Application
            ? [$application->containerCache(), $application->environment()->isProduction() ? $application->configurationCache() : null]
            : [null, null];
        foreach (DeploymentChecks::run($application, ...$caches) as [$check, $result]) {
            $name = substr((string) strrchr('\\' . $check, '\\'), 1);
            if ($result->status === CheckStatus::Ok) {
                $report->pass("Deployment checks: {$name}");
                continue;
            }
            foreach (explode("\n", $result->message) as $message) {
                $report->add(new Finding('deployment.' . $name, $result->status === CheckStatus::Fatal ? Severity::Error : Severity::Warning, $message));
            }
        }
    }

    /**
     * In production, name the files the release rules keep off a live server,
     * however they arrived (FE-66). This reads the disk only: doctor does not
     * run git.
     */
    public static function excludedFiles(ApplicationInterface $application, Report $report): void
    {
        if (!$application->environment()->isProduction()) {
            return;
        }

        $attributes = is_file($application->path('.gitattributes')) ? file_get_contents($application->path('.gitattributes')) : false;
        $paths = ExclusionRules::live($attributes === false ? null : $attributes)['paths'];
        $present = array_values(array_filter($paths, static fn (string $path): bool => file_exists($application->path($path))));
        if ($present === []) {
            $report->pass('Only what runs is on this server');

            return;
        }
        $report->add(new Finding(
            'release.files',
            Severity::Warning,
            'These files do not belong on a live server: ' . implode(', ', $present) . '.',
            hint: 'See what would go: php bin/console release:clean --dry-run',
        ));
    }

    /**
     * Create every singleton once, from the root container, as the first
     * request would: a factory that resolves a scoped service, which the
     * build cannot see, fails here rather than in traffic (FE-40).
     */
    public static function singletons(ApplicationInterface $application, Report $report): void
    {
        $container = $application->container();
        $created = 0;
        $failed = false;
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
                $failed = true;
                $label = $node->service . ($node->key === null ? '' : " [key: {$node->key}]");
                $report->add(new Finding(
                    'container.singleton',
                    Severity::Error,
                    "The singleton {$label} cannot be created: " . strtok($e->getMessage(), "\n"),
                    hint: $node->origin === null ? null : "It is registered by {$node->origin}.",
                ));
            }
        }

        if (!$failed) {
            $report->pass("All {$created} singletons can be created");
        }
    }
}
