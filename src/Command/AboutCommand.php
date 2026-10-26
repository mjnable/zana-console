<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Composer\InstalledVersions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;

/**
 * The application at a glance: environment, PHP, modules, caches and the
 * installed Zana packages.
 */
#[AsCommand(name: 'about', description: 'Show the application, its environment and its Zana packages')]
final class AboutCommand extends Command
{
    public function handle(SymfonyStyle $io, ApplicationInterface $application): int
    {
        $environment = $application->environment();
        $config = $application->config();
        $cache = $application instanceof Application ? $application->containerCache() : null;
        $configCache = $application instanceof Application ? $application->configurationCache() : null;

        $io->title($config->string('app.name', 'Zana application'));
        $io->definitionList(
            ['Environment' => $environment->name],
            ['Debug' => $environment->debug ? 'on' : 'off'],
            ['URL' => $config->string('app.url', '(app.url not set)')],
            ['PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')'],
            ['Base path' => $application->basePath()],
            ['Container cache' => $cache === null ? 'none' : ($cache . (is_file($cache) ? '' : ' (not compiled)'))],
            ['Configuration cache' => $configCache === null ? 'none' : ($configCache . (is_file($configCache) ? '' : ' (not written)') . ($environment->isProduction() ? '' : ' (used in production only)'))],
            ['Modules' => implode(', ', array_map(static fn ($module): string => $module->name(), $application->modules())) ?: 'none'],
        );

        if (class_exists(InstalledVersions::class)) {
            $rows = [];
            foreach (InstalledVersions::getInstalledPackages() as $package) {
                if (str_starts_with($package, 'mj-zana/')) {
                    $rows[] = [$package, (string) InstalledVersions::getPrettyVersion($package)];
                }
            }
            sort($rows);
            $io->table(['Package', 'Version'], $rows);
        }

        return self::SUCCESS;
    }
}
