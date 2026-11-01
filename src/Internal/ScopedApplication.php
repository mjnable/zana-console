<?php

declare(strict_types=1);

namespace Zana\Console\Internal;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;
use Zana\Core\Correlation;
use Zana\Core\ScopeKeys;
use Zana\Validation\ValidationException;

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

    /**
     * A ValidationException (mj-zana/validation, e.g. from ValidationBehavior) is shown as its field
     * errors, one per line, rather than as exception output (ADR-025).
     */
    public function renderThrowable(\Throwable $e, OutputInterface $output): void
    {
        if (!class_exists(ValidationException::class) || !$e instanceof ValidationException) {
            parent::renderThrowable($e, $output);

            return;
        }

        $lines = ['The input is not valid:'];
        foreach ($e->violations->toArray() as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = ($path === '' ? '' : "{$path}: ") . $message;
            }
        }
        $output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        (new SymfonyStyle(new ArrayInput([]), $output))->error($lines);
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
