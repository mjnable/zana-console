<?php

declare(strict_types=1);

namespace Zana\Console;

use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ServiceProvider;

/**
 * Registers the console kernel. Commands are services tagged "console.commands".
 * The application name comes from app.name.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services->singleton(ConsoleKernel::class, static function (ServiceResolverInterface $root): ConsoleKernel {
            assert($root instanceof ContainerInterface);

            return new ConsoleKernel($root, $root->get(ConfigurationInterface::class)->string('app.name', 'Zana'));
        });
    }

    public function origin(): string
    {
        return 'package:mj-zana/console';
    }
}
