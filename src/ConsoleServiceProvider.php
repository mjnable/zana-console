<?php

declare(strict_types=1);

namespace Zana\Console;

use Zana\Configuration\ConfigurationInterface;
use Zana\Console\Command\AboutCommand;
use Zana\Console\Command\CacheClearCommand;
use Zana\Console\Command\CacheWarmCommand;
use Zana\Console\Command\CheckCommand;
use Zana\Console\Command\DoctorCommand;
use Zana\Console\Command\InspectCommand;
use Zana\Console\Command\ReleaseBuildCommand;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ServiceProvider;

/**
 * Registers the console kernel and the built-in commands: cache:clear,
 * cache:warm, about, doctor, release:build, inspect and check. Commands are services tagged
 * "console.commands". The application name comes from app.name.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    private const array COMMANDS = [CacheClearCommand::class, CacheWarmCommand::class, AboutCommand::class, DoctorCommand::class, ReleaseBuildCommand::class, InspectCommand::class, CheckCommand::class];

    public function register(ServiceCollectionInterface $services): void
    {
        $services->singleton(ConsoleKernel::class, static function (ServiceResolverInterface $root): ConsoleKernel {
            assert($root instanceof ContainerInterface);

            return new ConsoleKernel($root, $root->get(ConfigurationInterface::class)->string('app.name', 'Zana'));
        })->uses(ConfigurationInterface::class);

        foreach (self::COMMANDS as $command) {
            $services->singleton($command)->tag(ConsoleKernel::TAG, $command);
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/console';
    }
}
