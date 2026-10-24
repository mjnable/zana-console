<?php

declare(strict_types=1);

namespace Zana\Console\Internal;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zana\Console\Command;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;
use Zana\Core\Correlation;
use Zana\Core\ScopeKeys;

/**
 * Runs every command inside its own container scope, disposed afterwards,
 * with a correlation id: the ZANA_CORRELATION_ID environment variable when it
 * holds a valid id, otherwise a new one.
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
        $incoming = getenv(ConsoleKernel::CORRELATION_ENV);
        $correlationId = Correlation::from(is_string($incoming) ? $incoming : null);

        $scope = $this->container->createScope(
            ScopeMetadata::cli((string) $command->getName())->withAttribute(ScopeKeys::CORRELATION_ID, $correlationId)
        );
        $restoreLogContext = Correlation::applyToLogContext($scope, $correlationId);

        if ($command instanceof Command) {
            $command->setResolver($scope);
        }

        try {
            return parent::doRunCommand($command, $input, $output);
        } finally {
            if ($command instanceof Command) {
                $command->setResolver(null);
            }
            $restoreLogContext();
            $scope->dispose();
        }
    }
}
