<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * FE-54: run from the real command line, the console records its script, so
 * hints say "php bin/zana migrate" in an application whose console is bin/zana.
 */
final class ConsoleScriptTest extends TestCase
{
    private string $base = '';

    #[Before]
    public function createApplication(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-console-script-' . bin2hex(random_bytes(4));
        mkdir("{$this->base}/bin", 0777, true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents("{$this->base}/bin/zana", <<<PHP
            <?php
            require {$autoload};

            use Symfony\Component\Console\Attribute\AsCommand;
            use Zana\Console\Command;
            use Zana\Console\ConsoleKernel;
            use Zana\Console\ConsoleServiceProvider;
            use Zana\Core\Application;
            use Zana\Core\ConsoleScript;

            #[AsCommand(name: 'hint')]
            final class HintCommand extends Command
            {
                public function handle(\Symfony\Component\Console\Output\OutputInterface \$output): void
                {
                    \$output->writeln('Run ' . ConsoleScript::command('migrate') . '.');
                }
            }

            exit(ConsoleKernel::runBuilder(Application::configure(dirname(__DIR__))
                ->withProviders(ConsoleServiceProvider::class)
                ->withServices(static fn (\$s) => \$s->singleton(HintCommand::class)->tag(ConsoleKernel::TAG, HintCommand::class))));
            PHP);
    }

    #[After]
    public function removeApplication(): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->base)) : 'rm -rf ' . escapeshellarg($this->base));
    }

    public function testHintsNameTheScriptThatRanTheConsole(): void
    {
        $cwd = getcwd();
        chdir($this->base);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off bin/zana hint 2>&1', $relative, $code);
            exec(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off ' . escapeshellarg("{$this->base}/bin/zana") . ' hint 2>&1', $absolute);
        } finally {
            chdir((string) $cwd);
        }

        $this->assertSame(0, $code, implode("\n", $relative));
        $this->assertSame(['Run `php bin/zana migrate`.'], $relative);
        $this->assertSame(['Run `php bin/zana migrate`.'], $absolute, 'an absolute path inside the application becomes relative');
    }
}
