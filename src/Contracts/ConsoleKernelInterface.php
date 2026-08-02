<?php

declare(strict_types=1);

namespace Zana\Console\Contracts;

/**
 * Contract for the console kernel that registers and dispatches commands.
 */
interface ConsoleKernelInterface
{
    /**
     * Register a command with the kernel.
     */
    public function register(CommandInterface $command): void;

    /**
     * Get all registered commands keyed by name.
     *
     * @return array<string, CommandInterface>
     */
    public function getCommands(): array;

    /**
     * Handle the incoming console request.
     *
     * @param array<int, string>|null $argv Raw CLI arguments (excluding script name).
     *                                     Defaults to $_SERVER['argv'].
     * @return int The process exit code
     */
    public function handle(?array $argv = null): int;
}

