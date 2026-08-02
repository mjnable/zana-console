<?php

declare(strict_types=1);

namespace Zana\Console\Commands;

use Zana\Console\Contracts\CommandInterface;

/**
 * Base class for console commands.
 *
 * Subclasses set the $name and $description properties and implement
 * handle(). The base class provides simple output helpers.
 */
abstract class Command implements CommandInterface
{
    protected string $name = '';

    protected string $description = '';

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Write a line to standard output.
     */
    protected function write(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Write an informational line to standard output.
     */
    protected function info(string $message): void
    {
        $this->write('[INFO] ' . $message);
    }

    /**
     * Write an error line to standard output.
     */
    protected function error(string $message): void
    {
        echo '[ERROR] ' . $message . PHP_EOL;
    }
}

