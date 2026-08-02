<?php

declare(strict_types=1);

namespace Zana\Console\Kernel;

use Zana\Console\Contracts\CommandInterface;
use Zana\Console\Contracts\ConsoleKernelInterface;

/**
 * Registers console commands and dispatches the incoming CLI request.
 *
 * Usage:
 *     $kernel = new ConsoleKernel([$command1, $command2]);
 *     exit($kernel->handle());
 */
class ConsoleKernel implements ConsoleKernelInterface
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    /**
     * @param array<int, CommandInterface> $commands
     */
    public function __construct(array $commands = [])
    {
        foreach ($commands as $command) {
            $this->register($command);
        }
    }

    public function register(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    public function getCommands(): array
    {
        return $this->commands;
    }

    public function handle(?array $argv = null): int
    {
        $argv ??= $_SERVER['argv'] ?? [];

        // Strip the script name from the front of argv.
        array_shift($argv);

        if (empty($argv)) {
            return $this->listCommands();
        }

        $name = (string) array_shift($argv);

        if ($name === 'list' || $name === 'help') {
            return $this->listCommands();
        }

        if (!isset($this->commands[$name])) {
            echo "Command \"{$name}\" not found." . PHP_EOL . PHP_EOL;
            $this->listCommands();
            return 1;
        }

        return $this->commands[$name]->handle($argv);
    }

    /**
     * Print the list of available commands.
     */
    private function listCommands(): int
    {
        echo 'Zana Console' . PHP_EOL . PHP_EOL;
        echo 'Available commands:' . PHP_EOL;

        foreach ($this->commands as $name => $command) {
            echo sprintf(
                "  %-20s %s" . PHP_EOL,
                $name,
                $command->getDescription()
            );
        }

        return 0;
    }
}

