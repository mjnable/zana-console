<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationBuilder;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\InspectionSection;
use Zana\Core\Inspection\Severity;

/**
 * FE-45 and FE-46 (ADR-023): inspect shows the application, check verifies
 * it, both readable as JSON by agents.
 */
final class InspectAndCheckTest extends TestCase
{
    private string $base = '';

    #[Before]
    public function createApplicationFolder(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-inspect-' . bin2hex(random_bytes(4));
        mkdir("{$this->base}/var", 0777, true);
    }

    #[After]
    public function removeApplicationFolder(): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->base)) : 'rm -rf ' . escapeshellarg($this->base));
    }

    public function testInspectShowsTheApplicationWithoutSecrets(): void
    {
        [$code, $json] = $this->json(['command' => 'inspect', '--format' => 'json'], ['security.secret' => 'very-secret-value-0123456789abcdef', 'shop.items' => 24]);

        $this->assertSame(0, $code);
        $this->assertSame('zana.inspect/1', $json['schema']);
        $this->assertSame(['environment', 'modules', 'services', 'commands', 'config', 'packages', 'stock'], array_keys($json['sections']));
        $this->assertSame('testing', $json['sections']['environment']['name']);
        $this->assertSame(['security.secret' => 'string', 'shop.items' => 'int'], array_intersect_key($json['sections']['config'], ['security.secret' => 1, 'shop.items' => 1]));
        $this->assertContains('check', array_column($json['sections']['commands'], 'name'));
        $this->assertContains(Warehouse::class, array_column($json['sections']['services'], 'service'));
        $this->assertSame(['items' => 3], $json['sections']['stock'], 'sections contributed through the tag');
        $this->assertStringNotContainsString('very-secret-value', (string) json_encode($json), 'configuration values never appear');
    }

    public function testInspectShowsOneSection(): void
    {
        [$code, $json] = $this->json(['command' => 'inspect', 'section' => 'stock', '--format' => 'json']);
        $this->assertSame(0, $code);
        $this->assertSame(['stock'], array_keys($json['sections']));

        [$code, $output] = $this->console(['command' => 'inspect', 'section' => 'nothing']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no "nothing" section. The sections are: environment, modules, services', $output);
    }

    public function testCheckPassesACorrectApplication(): void
    {
        [$code, $json] = $this->json(['command' => 'check', '--format' => 'json']);

        $this->assertSame(0, $code, (string) json_encode($json));
        $this->assertSame('zana.check/1', $json['schema']);
        $this->assertSame('ok', $json['status']);
        $this->assertContains('The container builds', $json['passed']);
        $this->assertContains('StockCheck', $json['passed'], 'application checks that find nothing pass');
    }

    public function testCheckReportsArchitectureViolationsWithLocationsAndHints(): void
    {
        mkdir("{$this->base}/modules/Shop/Domain", 0777, true);
        mkdir("{$this->base}/modules/Shop/Infrastructure", 0777, true);
        file_put_contents("{$this->base}/modules/Shop/Domain/Order.php", "<?php\n\nnamespace Modules\\Shop\\Domain;\n\nuse Modules\\Shop\\Infrastructure\\SqlOrders;\n\nfinal class Order\n{\n    public function save(SqlOrders \$orders): void {}\n}\n");
        file_put_contents("{$this->base}/modules/Shop/Infrastructure/SqlOrders.php", "<?php\n\nnamespace Modules\\Shop\\Infrastructure;\n\nfinal class SqlOrders {}\n");

        [$code, $json] = $this->json(['command' => 'check', '--format' => 'json']);

        $this->assertSame(1, $code);
        $this->assertSame('error', $json['status']);
        $finding = array_values(array_filter($json['findings'], static fn (array $f): bool => $f['id'] === 'architecture.dependency'))[0] ?? null;
        $this->assertNotNull($finding, (string) json_encode($json));
        $this->assertStringEndsWith('modules/Shop/Domain/Order.php', str_replace('\\', '/', (string) $finding['file']));
        $this->assertIsInt($finding['line']);
        $this->assertStringContainsString('Modules\\Shop\\Infrastructure\\SqlOrders', $finding['message']);
        $this->assertStringStartsWith('Domain code depends only on itself and Zana\\Ddd', (string) $finding['hint']);
    }

    public function testApplicationChecksAddTheirFindings(): void
    {
        [$code, $json] = $this->json(['command' => 'check', '--format' => 'json'], ['stock.low' => true]);

        $this->assertSame(0, $code, 'a warning does not fail the check');
        $this->assertSame('warning', $json['status']);
        $this->assertSame([['id' => 'stock.low', 'severity' => 'warning', 'message' => 'Stock is low.', 'file' => null, 'line' => null, 'hint' => 'Order more.']], $json['findings']);
    }

    public function testCheckReportsAContainerThatCannotBeBuilt(): void
    {
        $builder = $this->builder()->withServices(static fn (ServiceCollectionInterface $s) => $s->singleton(NeedsPrinter::class));
        $output = new BufferedOutput();

        $code = ConsoleKernel::runBuilder($builder, new ArrayInput(['command' => 'check', '--format' => 'json']), $output);

        $json = json_decode($output->fetch(), true);
        $this->assertSame(1, $code);
        $this->assertSame('container.B3', $json['findings'][0]['id']);
        $this->assertStringContainsString(Printer::class, $json['findings'][0]['message']);
        $this->assertSame('Registered by app.', $json['findings'][0]['hint']);

        $this->expectException(\Zana\Container\Exception\ContainerBuildException::class);
        ConsoleKernel::runBuilder($builder, new ArrayInput(['command' => 'about']), new BufferedOutput());
    }

    public function testDoctorSpeaksJsonToo(): void
    {
        [, $json] = $this->json(['command' => 'doctor', '--format' => 'json'], ['security.secret' => 'short']);

        $this->assertSame('zana.check/1', $json['schema']);
        $this->assertSame('error', $json['status']);
        $this->assertContains('security.secret', array_column($json['findings'], 'id'));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return array{int, array<string, mixed>}
     */
    private function json(array $input, array $config = []): array
    {
        [$code, $output] = $this->console($input, $config);
        $json = json_decode($output, true);
        $this->assertIsArray($json, $output);

        return [$code, $json];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return array{int, string}
     */
    private function console(array $input, array $config = []): array
    {
        $output = new BufferedOutput();
        $code = ConsoleKernel::runBuilder($this->builder($config), new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function builder(array $config = []): ApplicationBuilder
    {
        return Application::configure($this->base)
            ->withEnvironment('testing')
            ->withConfiguration($config)
            ->withProviders(ConsoleServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->singleton(Warehouse::class)
                ->singleton(StockSection::class)
                ->tag(InspectionSection::TAG, StockSection::class)
                ->singleton(StockCheck::class)
                ->tag(ApplicationCheck::TAG, StockCheck::class));
    }
}

final class Warehouse
{
}

final class Printer
{
}

final class NeedsPrinter
{
    public function __construct(public readonly Printer $printer)
    {
    }
}

final class StockSection implements InspectionSection
{
    public function name(): string
    {
        return 'stock';
    }

    public function inspect(ApplicationInterface $application): array
    {
        return ['items' => 3];
    }
}

final class StockCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        return $application->config()->bool('stock.low', false) ? [new Finding('stock.low', Severity::Warning, 'Stock is low.', hint: 'Order more.')] : [];
    }
}
