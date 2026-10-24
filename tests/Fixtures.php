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
