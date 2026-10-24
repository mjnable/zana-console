<?php

declare(strict_types=1);

namespace Zana\Console\Internal;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zana\Console\Command;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;

/**
 * Runs every command inside its own container scope, disposed afterwards.
 *
 * @internal
 */
final class ScopedApplication extends Application
{
    public function __construct(private readonly ContainerInterface $container, string $name, string $version)
    {
        parent::__construct($name, $version);
    }

    protected function doRunCommand(SymfonyCommand $command, InputInterface $input, OutputInterface $output): int
    {
        $scope = $this->container->createScope(ScopeMetadata::cli((string) $command->getName()));

        if ($command instanceof Command) {
            $command->setResolver($scope);
        }

        try {
            return parent::doRunCommand($command, $input, $output);
        } finally {
            if ($command instanceof Command) {
                $command->setResolver(null);
            }
            $scope->dispose();
        }
    }
}
