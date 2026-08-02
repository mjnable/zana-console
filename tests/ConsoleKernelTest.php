<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Console\Commands\Command;
use Zana\Console\Kernel\ConsoleKernel;

class StubCommand extends Command
{
    protected string $name = 'stub:hello';

    protected string $description = 'A stub command for testing';

    public function handle(array $args = []): int
    {
        $this->write('Hello, World!');
        return 0;
    }
}

class ConsoleKernelTest extends TestCase
{
    public function testRegistersCommands(): void
    {
        $kernel = new ConsoleKernel([new StubCommand()]);

        $commands = $kernel->getCommands();

        $this->assertArrayHasKey('stub:hello', $commands);
        $this->assertInstanceOf(StubCommand::class, $commands['stub:hello']);
    }

    public function testHandleWithNoArgsListsCommands(): void
    {
        $kernel = new ConsoleKernel([new StubCommand()]);

        ob_start();
        $exitCode = $kernel->handle(['console']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('stub:hello', $output);
        $this->assertStringContainsString('A stub command for testing', $output);
    }

    public function testHandleUnknownCommandReturnsOne(): void
    {
        $kernel = new ConsoleKernel([new StubCommand()]);

        ob_start();
        $exitCode = $kernel->handle(['console', 'unknown:command']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not found', $output);
    }

    public function testHandleDispatchesToCommand(): void
    {
        $kernel = new ConsoleKernel([new StubCommand()]);

        ob_start();
        $exitCode = $kernel->handle(['console', 'stub:hello']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Hello, World!', $output);
    }

    public function testListKeywordListsCommands(): void
    {
        $kernel = new ConsoleKernel([new StubCommand()]);

        ob_start();
        $exitCode = $kernel->handle(['console', 'list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Available commands:', $output);
    }
}

