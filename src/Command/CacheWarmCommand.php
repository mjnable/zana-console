<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Cache\CacheWarmerInterface;

/**
 * Compiles the container cache and runs the cache warmers, so the first
 * visitors after a deployment do not pay for it.
 */
#[AsCommand(name: 'cache:warm', description: 'Compile the container and warm the other caches')]
final class CacheWarmCommand extends Command
{
    public function handle(SymfonyStyle $io, ApplicationInterface $application): int
    {
        $warmed = [];

        $file = $application instanceof Application ? $application->compileContainerCache() : null;
        if ($file !== null) {
            $warmed[] = "compiled container ({$file})";
        }

        foreach ($application->container()->tagged(CacheWarmerInterface::WARMER_TAG) as $warmer) {
            if ($warmer instanceof CacheWarmerInterface) {
                array_push($warmed, ...$warmer->warm($application));
            }
        }

        $warmed === [] ? $io->success('Nothing to warm: the application has no container cache or cache warmers.') : $io->success(['Warmed:', ...$warmed]);

        return self::SUCCESS;
    }
}
