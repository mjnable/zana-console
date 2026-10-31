<?php

declare(strict_types=1);

namespace Zana\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Internal\Report;
use Zana\Console\Internal\ScopedApplication;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Core\ApplicationBuilder;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * Runs console commands, each in its own container scope with a correlation
 * id (scope attribute "correlation_id", and zana/logging's LogContext when
 * installed).
 *
 * Commands come from the "console.commands" tag (resolved from the root
 * container) and from add().
 */
final class ConsoleKernel
{
    public const TAG = 'console.commands';

    /** Environment variable whose valid value is reused as the correlation id. */
    public const CORRELATION_ENV = 'ZANA_CORRELATION_ID';

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
     * Build the application, then run the console with $argv (ADR-023). When
     * the build fails and the command is "check", its errors are reported as
     * findings, in the requested format, instead of a PHP error: one finding
     * per container error (container.B1 … container.B14), with the
     * registration's origin. Other commands fail as before.
     *
     *     exit(ConsoleKernel::runBuilder(Bootstrap::builder($base)->withContainerCache(...)));
     */
    public static function runBuilder(ApplicationBuilder $builder, ?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input ??= new ArgvInput();
        try {
            $application = $builder->build();
        } catch (\Throwable $e) {
            if ($input->getFirstArgument() !== 'check') {
                throw $e;
            }
            $output ??= new ConsoleOutput();
            $report = new Report();
            if ($e instanceof ContainerBuildException) {
                foreach ($e->errors() as $error) {
                    $report->add(new Finding(
                        'container.' . $error->code,
                        Severity::Error,
                        $error->message,
                        hint: $error->origin === null ? null : "Registered by {$error->origin}.",
                    ));
                }
            } else {
                $report->add(new Finding('application.build', Severity::Error, $e->getMessage(), $e->getFile(), $e->getLine()));
            }
            $format = $input->getParameterOption('--format', 'text');

            return $report->render($output, new SymfonyStyle($input, $output), $format === 'json' ? 'json' : 'text', '', 'The application cannot be built.');
        }

        return self::runApplication($application, $input, $output);
    }

    /**
     * Run the console with $argv, then terminate the application.
     */
    public static function runApplication(ApplicationInterface $application, ?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        try {
            $kernel = $application->container()->get(self::class);
            assert($kernel instanceof self);

            return $kernel->run($input ?? new ArgvInput(), $output);
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
