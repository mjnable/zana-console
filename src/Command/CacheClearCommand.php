<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Cache\CacheClearerInterface;

/**
 * Deletes the compiled container and runs the cache clearers (compiled
 * templates, ...). Run it after each deployment.
 */
#[AsCommand(name: 'cache:clear', description: 'Delete the compiled container and the other caches')]
final class CacheClearCommand extends Command
{
    public function handle(SymfonyStyle $io, ApplicationInterface $application): int
    {
        $cleared = [];

        $file = $application instanceof Application ? $application->containerCache() : null;
        if ($file !== null && is_file($file)) {
            unlink($file);
            $cleared[] = "compiled container ({$file})";
        }

        foreach ($application->container()->tagged(CacheClearerInterface::CLEARER_TAG) as $clearer) {
            if ($clearer instanceof CacheClearerInterface) {
                array_push($cleared, ...$clearer->clear($application));
            }
        }

        $cleared === [] ? $io->success('Nothing to clear.') : $io->success(['Cleared:', ...$cleared]);

        return self::SUCCESS;
    }
}
