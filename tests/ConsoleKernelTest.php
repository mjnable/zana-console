<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\ServiceCollection;
use Zana\Core\Application;
use Zana\Core\Correlation;

final class ConsoleKernelTest extends TestCase
{
    private ConsoleKernel $kernel;

    private FakeLogContext $logContext;

    protected function setUp(): void
    {
        Journal::$entries = [];

        $container = Application::configure(sys_get_temp_dir() . '/zana-console-app')
            ->withProviders(ConsoleServiceProvider::class)
            ->withServices(static function (ServiceCollectionInterface $s): void {
                $s->scoped(UnitOfWork::class)
                    ->singleton(FakeLogContext::class)
                    ->alias(Correlation::LOG_CONTEXT, FakeLogContext::class);
                foreach ([TraceCommand::class, ReportCommand::class, QuietCommand::class, FailingCommand::class, BadReturnCommand::class, NoHandleCommand::class, PlainSymfonyCommand::class] as $command) {
                    $s->singleton($command)->tag(ConsoleKernel::TAG, $command);
                }
            })
            ->build()
            ->container();
        $this->kernel = $container->get(ConsoleKernel::class);
        $this->logContext = $container->get(FakeLogContext::class);
    }

    public function testHandleReceivesInputOutputStyleAndScopedServices(): void
    {
        [$code, $output] = $this->execute(['command' => 'bmi:report', 'patient' => '42']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('patient 42, unit ', $output);
        $this->assertStringContainsString('scope cli:bmi:report', $output);
        $this->assertStringContainsString('done', $output);
    }

    public function testEachRunHasACorrelationId(): void
    {
        [, $first] = $this->execute(['command' => 'bmi:trace']);
        [, $second] = $this->execute(['command' => 'bmi:trace']);

        $this->assertSame(1, preg_match('/^scope=([0-9a-f]{32}) log=([0-9a-f]{32})$/', trim($first), $a), $first);
        $this->assertSame($a[1] ?? null, $a[2] ?? null, 'the scope and the log context carry the same id');
        $this->assertStringNotContainsString($a[1] ?? '', $second, 'each run gets its own id');
        $this->assertSame([], $this->logContext->all(), 'the log context is restored after the run');

        putenv(ConsoleKernel::CORRELATION_ENV . '=cron-2026-10-25');
        try {
            $this->assertSame('scope=cron-2026-10-25 log=cron-2026-10-25', trim($this->execute(['command' => 'bmi:trace'])[1]));
            putenv(ConsoleKernel::CORRELATION_ENV . '=not valid!');
            $this->assertMatchesRegularExpression('/^scope=[0-9a-f]{32} /', trim($this->execute(['command' => 'bmi:trace'])[1]), 'invalid ids are replaced');
        } finally {
            putenv(ConsoleKernel::CORRELATION_ENV);
        }
    }

    public function testEachRunHasItsOwnScopeDisposedAfterwards(): void
    {
        [, $first] = $this->execute(['command' => 'bmi:report', 'patient' => '1']);
        [, $second] = $this->execute(['command' => 'bmi:report', 'patient' => '2']);

        $this->assertSame(1, preg_match('/unit (\d+)/', $first, $a));
        $this->assertSame(1, preg_match('/unit (\d+)/', $second, $b));
        $this->assertNotSame($a[1] ?? null, $b[1] ?? null);
        $this->assertSame(['disposed:' . ($a[1] ?? ''), 'disposed:' . ($b[1] ?? '')], Journal::$entries);
    }

    public function testExitCodesAndNullReturns(): void
    {
        $this->assertSame(1, $this->execute(['command' => 'bmi:report', 'patient' => 'missing'])[0]);
        $this->assertSame(0, $this->execute(['command' => 'bmi:quiet'])[0]);
        $this->assertContains('quiet', Journal::$entries);
    }

    public function testExceptionsAreRenderedWithoutExiting(): void
    {
        [$code, $output] = $this->execute(['command' => 'bmi:fail']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('database unreachable', $output);
    }

    public function testInvalidHandlersAreReported(): void
    {
        $this->assertStringContainsString('must return an int or null, got string', $this->execute(['command' => 'bmi:bad-return'])[1]);
        $this->assertStringContainsString('must define a handle() method', $this->execute(['command' => 'bmi:no-handle'])[1]);
    }

    public function testPlainSymfonyCommandsWork(): void
    {
        [$code, $output] = $this->execute(['command' => 'plain']);

        $this->assertSame([0, "plain symfony\n"], [$code, str_replace("\r\n", "\n", $output)]);
    }

    public function testCommandsCanBeAddedAndListed(): void
    {
        $container = (new ServiceCollection())->build();
        $kernel = new ConsoleKernel($container, 'BMI', '2.1');
        $kernel->add(new PlainSymfonyCommand());

        $this->assertTrue($kernel->application()->has('plain'));
        $this->assertSame('BMI', $kernel->application()->getName());
        $this->assertSame('2.1', $kernel->application()->getVersion());
    }

    public function testCommandsNeedAResolverOutsideTheKernel(): void
    {
        $command = new QuietCommand();
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        try {
            $command->run($input, $output);
            $this->fail('A command without resolver must fail.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('needs a resolver', $e->getMessage());
        }

        $command->setResolver((new ServiceCollection())->build());
        $this->assertSame(0, $command->run($input, $output));
    }

    public function testTaggedServicesMustBeCommands(): void
    {
        $container = (new ServiceCollection())->singleton('not-a-command', static fn (): \stdClass => new \stdClass())->tag(ConsoleKernel::TAG, 'not-a-command')->build();

        $this->expectException(\LogicException::class);
        (new ConsoleKernel($container))->application();
    }

    /**
     * @param array<string, string> $arguments
     * @return array{int, string}
     */
    private function execute(array $arguments): array
    {
        $output = new BufferedOutput();
        $code = $this->kernel->run(new ArrayInput($arguments), $output);

        return [$code, $output->fetch()];
    }
}
