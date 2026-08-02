<?php

declare(strict_types=1);

namespace Zana\Console\Contracts;

/**
 * Contract for console commands.
 */
interface CommandInterface
{
    /**
     * Get the command name (e.g. "migrate").
     */
    public function getName(): string;

    /**
     * Get a short description shown in command listings.
     */
    public function getDescription(): string;

    /**
     * Execute the command.
     *
     * @param array<int, string> $args Command arguments (excluding the command name)
     * @return int The process exit code (0 = success)
     */
    public function handle(array $args = []): int;
}

