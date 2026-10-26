<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Cache\CacheClearerInterface;
use Zana\Core\Cache\CacheWarmerInterface;

final class DeploymentCommandsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zana-console-deploy-' . bin2hex(random_bytes(4));
        mkdir("{$this->base}/var", 0777, true);
    }

    #[After]
    public function cleanUp(): void
    {
        @unlink("{$this->base}/var/container.php");
        @rmdir("{$this->base}/var");
        @rmdir($this->base);
    }

    public function testCacheWarmCompilesAndCacheClearDeletes(): void
    {
        $kernel = $this->kernel();
        $file = "{$this->base}/var/container.php";
        unlink($file);

        [$code, $output] = $this->execute($kernel, 'cache:warm');
        $this->assertSame(0, $code);
        $this->assertFileExists($file);
        $this->assertStringContainsString('compiled container', $output);
        $this->assertStringContainsString('templates warmed', $output);

        [$code, $output] = $this->execute($kernel, 'cache:clear');
        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist($file);
        $this->assertStringContainsString('templates cleared', $output);
    }

    public function testAboutShowsTheApplication(): void
    {
        [$code, $output] = $this->execute($this->kernel(), 'about');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Acme', $output);
        $this->assertStringContainsString('development', $output);
        $this->assertStringContainsString('mj-zana/console', $output);
    }

    public function testDoctorReportsProblems(): void
    {
        [$code, $output] = $this->execute($this->kernel(['security.secret' => 'short', 'app.extensions' => ['definitely_missing_ext']]), 'doctor');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('✔ PHP ' . PHP_VERSION, $output);
        $this->assertStringContainsString('✘ The PHP extension definitely_missing_ext is missing.', $output);
        $this->assertStringContainsString('✘ security.secret must be at least 32 bytes.', $output);
        $this->assertStringContainsString('This server is not ready.', $output);
    }

    public function testDoctorPassesOnAHealthyServer(): void
    {
        [$code, $output] = $this->execute($this->kernel(['security.secret' => str_repeat('s', 32)]), 'doctor');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('This server is ready.', $output);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = []): ConsoleKernel
    {
        $kernel = Application::configure($this->base)
            ->withEnvironment('development')
            ->withConfiguration(['app.name' => 'Acme', ...$config])
            ->withProviders(ConsoleServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->singleton(TemplateCache::class)
                ->tag(CacheClearerInterface::CLEARER_TAG, TemplateCache::class)
                ->tag(CacheWarmerInterface::WARMER_TAG, TemplateCache::class))
            ->withContainerCache("{$this->base}/var/container.php")
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        return $kernel;
    }

    /**
     * @return array{int, string}
     */
    private function execute(ConsoleKernel $kernel, string $command): array
    {
        $output = new BufferedOutput();
        $kernel->application()->setAutoExit(false);
        $code = $kernel->run(new ArrayInput(['command' => $command]), $output);

        return [$code, $output->fetch()];
    }
}

final class TemplateCache implements CacheClearerInterface, CacheWarmerInterface
{
    public function clear(ApplicationInterface $application): array
    {
        return ['templates cleared'];
    }

    public function warm(ApplicationInterface $application): array
    {
        return ['templates warmed'];
    }
}
