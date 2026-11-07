<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Console\Release\ExclusionRules;
use Zana\Console\Release\ReleaseBuilder;
use Zana\Core\Application;

/**
 * release:clean (FE-66): the rules of release:build on a live server, by
 * sparse-checkout in a Git checkout and by deletion elsewhere, and nowhere
 * but in production. The Git tests use real repositories, because what
 * matters is that git pull keeps working.
 */
final class ReleaseCleanTest extends TestCase
{
    private const array EXCLUDED = ['README.md', 'AGENTS.md', '.env.example', 'tests/PageTest.php'];

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-clean-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    #[After]
    public function cleanUp(): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->root)) : 'rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheLiveRulesAreTheArchiveRulesWithoutTheConfiguration(): void
    {
        $paths = ExclusionRules::live()['paths'];

        $this->assertNotContains('.env', $paths);
        $this->assertSame(array_values(array_diff(ExclusionRules::PATHS, ['.env'])), $paths);
    }

    public function testExportIgnoreCountsForPlainPathsOnly(): void
    {
        $attributes = <<<'TXT'
            # project notes
            /docs export-ignore
            /src/Old/ export-ignore
            /README.md export-ignore
            /notes.txt text eol=lf
            *.log export-ignore
            TXT;

        ['paths' => $paths, 'unsupported' => $unsupported] = ExclusionRules::live($attributes);

        $this->assertContains('docs', $paths);
        $this->assertContains('src/Old', $paths);
        $this->assertNotContains('notes.txt', $paths);
        $this->assertSame(1, array_count_values($paths)['README.md'], 'listed once');
        $this->assertSame(['*.log'], $unsupported);
    }

    public function testGitAttributesCannotReachOutsideTheProjectOrAProtectedPath(): void
    {
        $hostile = ['/.. ', '/../other ', '/src/../.. ', '/.env ', '/.ENV ', '/.git ', '/.git/config ', '/var ', '/var/log ', '/vendor ', '/public ', '/ ', 'C:/Windows ', '/a\\b '];

        ['paths' => $paths] = ExclusionRules::live(implode("\n", array_map(static fn (string $line): string => "{$line}export-ignore", $hostile)));

        $this->assertSame(array_values(array_diff(ExclusionRules::PATHS, ['.env'])), $paths);
    }

    public function testOutsideProductionNothingChanges(): void
    {
        $this->folder();

        [$code, $output] = $this->run_('development', ['--force' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('live server only', $output);
        $this->assertAllStillThere();
    }

    public function testOutsideProductionACheckoutIsNotTouchedEither(): void
    {
        $this->checkout();

        [$code] = $this->run_('testing');

        $this->assertSame(1, $code);
        $this->assertAllStillThere();
        $this->assertFileDoesNotExist("{$this->project()}/.git/info/sparse-checkout", 'sparse-checkout was not turned on');
    }

    public function testADryRunListsAndChangesNothingAnywhere(): void
    {
        $this->folder();

        foreach (['development', 'production'] as $environment) {
            [$code, $output] = $this->run_($environment, ['--dry-run' => true]);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('README.md', $output);
            $this->assertAllStillThere();
        }
    }

    public function testAnUploadedFolderIsCleanedAndKeepsItsConfiguration(): void
    {
        $this->folder();

        [$code] = $this->run_('production', ['--force' => true]);

        $this->assertSame(0, $code);
        foreach (self::EXCLUDED as $file) {
            $this->assertFileDoesNotExist("{$this->project()}/{$file}");
        }
        $this->assertDirectoryDoesNotExist("{$this->project()}/tests");
        $this->assertFileExists("{$this->project()}/.env", 'the configuration of the server');
        $this->assertFileExists("{$this->project()}/src/Page.php");
        $this->assertFileExists("{$this->project()}/src/README.md", 'paths count from the root');
        $this->assertFileExists("{$this->project()}/public/index.php");
    }

    public function testNothingIsDeletedWithoutAnAnswer(): void
    {
        $this->folder();

        [$code, $output] = $this->run_('production', ['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Nothing was deleted', $output);
        $this->assertAllStillThere();
    }

    public function testAYesDeletes(): void
    {
        $this->folder();

        [$code] = $this->run_('production', [], "yes\n");

        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist("{$this->project()}/README.md");
    }

    public function testThePublicFolderGoesOnlyWhenAskedFor(): void
    {
        $this->folder();

        $this->run_('production', ['--force' => true]);
        $this->assertFileExists("{$this->project()}/public/index.php");

        $this->run_('production', ['--force' => true, '--no-public' => true]);
        $this->assertDirectoryDoesNotExist("{$this->project()}/public");
    }

    public function testACheckoutLosesTheFilesAndKeepsPulling(): void
    {
        $this->checkout();

        [$code, $output] = $this->run_('production');

        $this->assertSame(0, $code, $output);
        foreach ([...self::EXCLUDED, 'docs/notes.md'] as $file) {
            $this->assertFileDoesNotExist("{$this->project()}/{$file}");
        }
        $this->assertFileExists("{$this->project()}/.env");
        $this->assertSame('', $this->git('status --short'), 'Git sees no change');

        $this->commitOnDevelopment(['README.md' => 'changed', 'tests/NewTest.php' => '<?php', 'src/Page.php' => '<?php // new', 'src/Other.php' => '<?php']);
        $this->git('pull -q origin main 2>&1');

        $this->assertSame('<?php // new', file_get_contents("{$this->project()}/src/Page.php"));
        $this->assertFileExists("{$this->project()}/src/Other.php");
        $this->assertFileDoesNotExist("{$this->project()}/README.md");
        $this->assertFileDoesNotExist("{$this->project()}/tests/NewTest.php");
        $this->assertSame('', $this->git('status --short'));
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->checkout();

        $this->run_('production');
        [$code] = $this->run_('production');

        $this->assertSame(0, $code);
        $this->assertSame('', $this->git('status --short'));
        $this->assertFileDoesNotExist("{$this->project()}/docs/notes.md", 'export-ignore still applies, read from the commit');
    }

    public function testAFileChangedOnTheServerIsNamedAndKept(): void
    {
        $this->checkout();
        file_put_contents("{$this->project()}/README.md", 'edited on the server');

        [$code, $output] = $this->run_('production');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Still here', $output);
        $this->assertStringContainsString('README.md', $output);
        $this->assertSame('edited on the server', file_get_contents("{$this->project()}/README.md"));
    }

    public function testACheckoutWithItsOwnSparsePatternsIsLeftAlone(): void
    {
        $this->checkout();
        $this->git('sparse-checkout set --no-cone src 2>&1');

        [$code, $output] = $this->run_('production');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('patterns of its own', $output);
        $this->assertSame('src', trim($this->git('sparse-checkout list')));
    }

    public function testDoctorWarnsInProductionAndPassesOnceClean(): void
    {
        $this->folder();

        [$code, $output] = $this->run_('production', [], null, 'doctor');
        $this->assertSame(0, $code, 'a warning does not fail doctor');
        $this->assertStringContainsString('do not belong on a live server', str_replace("\n", ' ', (string) preg_replace('/\s+/', ' ', $output)));
        $this->assertStringContainsString('release:clean --dry-run', $output);

        $this->run_('production', ['--force' => true]);

        [, $output] = $this->run_('production', [], null, 'doctor');
        $this->assertStringContainsString('Only what runs is on this server', $output);
    }

    public function testDoctorSaysNothingAboutThisOutsideProduction(): void
    {
        $this->folder();

        [, $output] = $this->run_('development', [], null, 'doctor');

        $this->assertStringNotContainsString('live server', $output);
        $this->assertStringNotContainsString('Only what runs', $output);
    }

    public function testALinkIsRemovedAndNotFollowed(): void
    {
        mkdir("{$this->root}/kept");
        file_put_contents("{$this->root}/kept/file.txt", 'kept');
        if (!@symlink("{$this->root}/kept", "{$this->root}/link")) {
            $this->markTestSkipped('This system cannot create symbolic links.');
        }

        ReleaseBuilder::remove("{$this->root}/link");

        $this->assertFileExists("{$this->root}/kept/file.txt");
        $this->assertFalse(is_link("{$this->root}/link"));
    }

    private function project(): string
    {
        return "{$this->root}/live";
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        return [
            'README.md' => 'a', 'AGENTS.md' => 'a', '.env.example' => 'a', 'tests/PageTest.php' => '<?php', 'docs/notes.md' => 'a',
            'src/Page.php' => '<?php', 'src/README.md' => 'a', 'public/index.php' => '<?php',
            '.gitignore' => "/var/\n.env\n", '.gitattributes' => "/docs export-ignore\n",
        ];
    }

    /**
     * @param array<string, string> $files
     */
    private function write(string $directory, array $files): void
    {
        foreach ($files as $name => $content) {
            @mkdir(dirname("{$directory}/{$name}"), 0777, true);
            file_put_contents("{$directory}/{$name}", $content);
        }
    }

    /** A project uploaded by hand. */
    private function folder(): void
    {
        $this->write($this->project(), $this->files());
        $this->server();
    }

    /** A project cloned on the server. */
    private function checkout(): void
    {
        exec('git init -q --bare ' . escapeshellarg("{$this->root}/origin.git") . ' 2>&1', $out, $status);
        if ($status !== 0) {
            $this->markTestSkipped('git is not available.');
        }
        exec('git clone -q ' . escapeshellarg("{$this->root}/origin.git") . ' ' . escapeshellarg("{$this->root}/dev") . ' 2>&1');
        $this->commitOnDevelopment($this->files(), 'branch -M main && ');
        exec('git clone -q -b main ' . escapeshellarg("{$this->root}/origin.git") . ' ' . escapeshellarg($this->project()) . ' 2>&1', $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
        $this->server();
    }

    /**
     * @param array<string, string> $files
     */
    private function commitOnDevelopment(array $files, string $before = ''): void
    {
        $this->write("{$this->root}/dev", $files);
        $git = 'git -C ' . escapeshellarg("{$this->root}/dev") . ' ';
        $before = $before === '' ? '' : $git . $before;
        exec("{$git}add -A 2>&1 && {$git}-c user.name=Test -c user.email=test@example.com commit -q -m change 2>&1 && {$before}{$git}push -q origin main 2>&1", $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
    }

    /** What a server holds that no route brings. */
    private function server(): void
    {
        file_put_contents("{$this->project()}/.env", "APP_MARK=server\n");
        @mkdir("{$this->project()}/var");
    }

    private function git(string $arguments): string
    {
        return (string) shell_exec('git -C ' . escapeshellarg($this->project()) . " {$arguments}");
    }

    private function assertAllStillThere(): void
    {
        foreach (self::EXCLUDED as $file) {
            $this->assertFileExists("{$this->project()}/{$file}");
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, string}
     */
    private function run_(string $environment, array $options = [], ?string $answers = null, string $command = 'release:clean'): array
    {
        $kernel = Application::configure($this->project())
            ->withEnvironment($environment)
            ->withConfiguration(['app.name' => 'Acme'])
            ->withProviders(ConsoleServiceProvider::class)
            ->build()
            ->container()
            ->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);

        $input = new ArrayInput(['command' => $command, ...$options]);
        if ($answers !== null) {
            $stream = fopen('php://memory', 'r+');
            assert(is_resource($stream));
            fwrite($stream, $answers);
            rewind($stream);
            $input->setStream($stream);
        }
        $output = new BufferedOutput();
        $kernel->application()->setAutoExit(false);

        return [$kernel->run($input, $output), $output->fetch()];
    }
}
