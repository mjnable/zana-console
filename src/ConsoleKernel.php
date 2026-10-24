<?php

declare(strict_types=1);

namespace Zana\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zana\Console\Internal\ScopedApplication;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Core\ApplicationInterface;

/**
 * Runs console commands, each in its own container scope.
 *
 * Commands come from the "console.commands" tag (resolved from the root
 * container) and from add().
 */
final class ConsoleKernel
{
    public const TAG = 'console.commands';

    /** @var list<SymfonyCommand> */
    private array $extra = [];

    private ?Application $application = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $name = 'Zana',
        private readonly string $version = '2.0',
    ) {
    }

    /**
     * Run the console with $argv, then terminate the application.
     */
    public static function runApplication(ApplicationInterface $application): int
    {
        try {
            $kernel = $application->container()->get(self::class);
            assert($kernel instanceof self);

            return $kernel->run(new ArgvInput());
        } finally {
            $application->terminate();
        }
    }

    public function add(SymfonyCommand ...$commands): self
    {
        array_push($this->extra, ...array_values($commands));
        $this->application?->addCommands($commands);

        return $this;
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        return $this->application()->run($input, $output);
    }

    /**
     * The underlying Symfony application, e.g. to inspect or test commands.
     */
    public function application(): Application
    {
        if ($this->application !== null) {
            return $this->application;
        }

        $application = new ScopedApplication($this->container, $this->name, $this->version);
        $application->setAutoExit(false);

        foreach ($this->container->tagged(self::TAG) as $command) {
            if (!$command instanceof SymfonyCommand) {
                throw new \LogicException('Services tagged ' . self::TAG . ' must be Symfony commands; got ' . get_debug_type($command) . '.');
            }
            $application->addCommand($command);
        }
        foreach ($this->extra as $command) {
            $application->addCommand($command);
        }

        return $this->application = $application;
    }
}
