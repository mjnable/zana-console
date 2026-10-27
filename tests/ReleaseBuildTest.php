<?php

declare(strict_types=1);

namespace Zana\Console\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Zana\Console\Release\ComposerLocator;
use Zana\Console\Release\Process;
use Zana\Console\Release\ReleaseBuilder;
use Zana\Console\Release\ReleaseException;

/**
 * release:build (ADR-021): the process runner never hangs on a full pipe,
 * stops after its timeout, Composer is found on Windows, and the archives
 * split the project from its public folder.
 */
final class ReleaseBuildTest extends TestCase
{
    private string $project = '';

    #[After]
    public function cleanUp(): void
    {
        if ($this->project !== '') {
            exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->project)) : 'rm -rf ' . escapeshellarg($this->project));
        }
    }

    public function testACommandWritingALotToStderrFinishes(): void
    {
        $seen = '';
        $started = microtime(true);

        $output = (new Process(30, static function (string $chunk) use (&$seen): void {
            $seen .= $chunk;
        }))->run([PHP_BINARY, __DIR__ . '/release/noisy.php', '100'], __DIR__);

        $this->assertLessThan(10, microtime(true) - $started, 'it used to block on Windows after about 4 KB');
        $this->assertGreaterThan(100_000, strlen($output));
        $this->assertStringEndsWith('done', $output);
        $this->assertSame($output, $seen, 'the output is shown as it comes');
    }

    public function testACommandThatDoesNotFinishIsStopped(): void
    {
        $started = microtime(true);
        try {
            (new Process(1))->run([PHP_BINARY, __DIR__ . '/release/slow.php', '30'], __DIR__);
            $this->fail('Expected a timeout.');
        } catch (ReleaseException $e) {
            $this->assertStringContainsString('did not finish within 1 seconds', $e->getMessage());
            $this->assertStringContainsString('started', $e->getMessage(), 'the output so far is shown');
        }
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function testAFailingCommandShowsTheEndOfItsOutput(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageMatches('/failed \(exit code 3\):\nx+\n/');

        (new Process(30))->run([PHP_BINARY, __DIR__ . '/release/noisy.php', '50', '3'], __DIR__);
    }

    public function testACommandThatCannotStartSaysHowToFixIt(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('zana-no-such-command cannot be started. Pass its path with --composer, or set COMPOSER_BINARY.');

        (new Process(30))->run(['zana-no-such-command', '--version'], __DIR__);
    }

    public function testComposerIsFoundAsTheWindowsInstallerLeavesIt(): void
    {
        $this->project = $this->temporaryDirectory();
        $bin = "{$this->project}/ComposerSetup/bin";
        mkdir($bin, 0777, true);
        touch("{$bin}/composer.bat");
        $path = "C:/Windows/system32;{$bin};C:/other";

        $this->assertSame(['cmd', '/c', "{$bin}/composer.bat"], (new ComposerLocator($path, windows: true))->locate(), 'without the phar, through cmd');

        touch("{$bin}/composer.phar");
        $this->assertSame([PHP_BINARY, "{$bin}/composer.phar"], (new ComposerLocator($path, windows: true))->locate(), 'the phar, with this PHP');

        $this->assertSame(['cmd', '/c', 'composer'], (new ComposerLocator('', windows: true))->locate());
        $this->assertSame(['composer'], (new ComposerLocator('', windows: false))->locate());
        $this->assertSame([PHP_BINARY, 'D:/tools/composer.phar'], (new ComposerLocator('', windows: true))->locate('D:/tools/composer.phar'));
        $this->assertSame(['cmd', '/c', 'D:/tools/composer.bat'], (new ComposerLocator('', windows: true))->locate('D:/tools/composer.bat'));
    }

    #[RequiresPhpExtension('zip')]
    public function testTheArchivesSplitTheProjectFromItsPublicFolder(): void
    {
        $this->project = $this->temporaryDirectory();
        $files = [
            'composer.json' => '{"name": "acme/site", "require": {}}',
            'public/index.php' => "<?php\nconst PROJECT_FOLDER = 'website';\n",
            'public/assets/site.css' => 'body {}',
            'src/Page.php' => '<?php',
            'tests/PageTest.php' => '<?php',
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "/vendor/\n/var/\n.env\n",
        ];
        foreach ($files as $name => $content) {
            @mkdir(dirname("{$this->project}/{$name}"), 0775, true);
            file_put_contents("{$this->project}/{$name}", $content);
        }
        file_put_contents("{$this->project}/.env", 'APP_SECURITY__SECRET=never-shipped');
        file_put_contents("{$this->project}/uncommitted.txt", 'not in the commit');
        $git = 'git -C ' . escapeshellarg($this->project);
        exec("{$git} init -q 2>&1 && {$git} add composer.json public src tests phpunit.xml.dist .gitignore 2>&1 && {$git} -c user.name=Test -c user.email=test@example.com commit -q -m init 2>&1", $out, $status);
        if ($status !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $composer = ComposerLocator::fromEnvironment()->locate();
        $archives = (new ReleaseBuilder($this->project, $composer, new Process(300)))->build("{$this->project}/build", 'acme-site');

        $code = self::entries($archives['code']);
        $this->assertContains('acme-site/src/Page.php', $code);
        $this->assertContains('acme-site/vendor/autoload.php', $code, 'production dependencies are installed');
        $this->assertContains('acme-site/var/', $code);
        $this->assertNotContains('acme-site/tests/PageTest.php', $code);
        $this->assertNotContains('acme-site/.env', $code);
        $this->assertNotContains('acme-site/uncommitted.txt', $code);

        $public = self::entries($archives['public']);
        sort($public);
        $this->assertSame(['assets/site.css', 'index.php'], $public);
        $zip = new \ZipArchive();
        $zip->open($archives['public']);
        $this->assertStringContainsString("const PROJECT_FOLDER = 'acme-site';", (string) $zip->getFromName('index.php'));
        $zip->close();
    }

    public function testFolderNamesCannotEscape(): void
    {
        $this->expectException(ReleaseException::class);

        (new ReleaseBuilder(__DIR__, ['composer']))->build(sys_get_temp_dir(), '../elsewhere');
    }

    private function temporaryDirectory(): string
    {
        $directory = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-release-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);

        return $directory;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $archive): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }
}
