<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Container\Contracts\Disposable;
use Zana\Container\ScopeMetadata;

final class Journal
{
    /** @var list<string> */
    public static array $entries = [];
}

final class UnitOfWork implements Disposable
{
    private static int $created = 0;

    public readonly int $number;

    public function __construct(public readonly ScopeMetadata $scope)
    {
        $this->number = ++self::$created;
    }

    public function dispose(): void
    {
        Journal::$entries[] = "disposed:{$this->number}";
    }
}

#[AsCommand(name: 'bmi:report', description: 'Print a BMI report')]
final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('patient', InputArgument::REQUIRED);
    }

    public function handle(InputInterface $input, SymfonyStyle $io, OutputInterface $output, UnitOfWork $work): int
    {
        $io->writeln(sprintf('patient %s, unit %d, scope %s:%s', $input->getArgument('patient'), $work->number, $work->scope->type, $work->scope->name));
        $output->writeln('done');

        return $input->getArgument('patient') === 'missing' ? self::FAILURE : self::SUCCESS;
    }
}

/**
 * Stands in for zana/logging's LogContext, registered under its service id.
 */
final class FakeLogContext
{
    /** @var array<string, string|int|float|bool|null> */
    private array $values = [];

    public function set(string $key, string|int|float|bool|null $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public function all(): array
    {
        return $this->values;
    }
}

#[AsCommand(name: 'bmi:trace')]
final class TraceCommand extends Command
{
    public function handle(ScopeMetadata $scope, FakeLogContext $logs, OutputInterface $output): void
    {
        $output->writeln(sprintf('scope=%s log=%s', (string) $scope->attribute('correlation_id'), (string) ($logs->all()['correlation_id'] ?? '-')));
    }
}

#[AsCommand(name: 'bmi:quiet')]
final class QuietCommand extends Command
{
    public function handle(): void
    {
        Journal::$entries[] = 'quiet';
    }
}

#[AsCommand(name: 'bmi:fail')]
final class FailingCommand extends Command
{
    public function handle(): int
    {
        throw new \RuntimeException('database unreachable');
    }
}

#[AsCommand(name: 'bmi:bad-return')]
final class BadReturnCommand extends Command
{
    public function handle(): string
    {
        return 'oops';
    }
}

#[AsCommand(name: 'bmi:no-handle')]
final class NoHandleCommand extends Command
{
}

#[AsCommand(name: 'plain')]
final class PlainSymfonyCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('plain symfony');

        return self::SUCCESS;
    }
}
