<?php

declare(strict_types=1);

namespace Zana\Console\Release;

/**
 * Builds the two archives to upload to shared hosting, where the document
 * root is fixed (public_html) and Composer cannot run:
 *
 * - <folder>.zip: the project as committed, with its production
 *   dependencies installed, to unpack beside public_html;
 * - public_html.zip: the contents of public/, to unpack into public_html.
 *   Its index.php finds the project in ../<folder> (the PROJECT_FOLDER
 *   constant of the website skeleton's front controller).
 *
 * The archives come from a commit (git archive), so uncommitted files,
 * .env and var/ never ship, and export-ignore in the project's
 * .gitattributes leaves a path out of both. The code archive holds what
 * runs: tests, development tooling, the testing configuration, and the
 * documents of the project and of its dependencies are left out.
 *
 * Three things that do not run stay on purpose: the dependencies' licence
 * files, which must travel with them; composer.json and composer.lock, for
 * running Composer on the upload; and the root .htaccess, which keeps .env
 * private if the project lands inside the document root by mistake.
 */
final readonly class ReleaseBuilder
{
    /** Paths from the project's root, files or folders, that never ship. */
    private const array EXCLUDED = [
        'tests', 'config/testing', '.env', '.env.testing', '.env.example',
        '.github', '.gitattributes', '.gitignore', '.editorconfig',
        'phpunit.xml', 'phpunit.xml.dist', '.phpunit.cache', '.phpunit.result.cache', 'phpstan.neon', 'phpstan.neon.dist',
        'AGENTS.md', 'CLAUDE.md', 'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'UPGRADE.md',
        'LICENSE', 'LICENSE.md', 'LICENSE.txt',
    ];

    /** Input of make:plan, at the project's root. */
    private const string PLAN = '#^[^/]+\.plan\.json$#';

    /** What a dependency carries at its root for its readers, not for PHP. Licence files are not in it. */
    private const string DEPENDENCY_DOCUMENTS = '#^vendor/[^/]+/[^/]+/(?:(?:readme|changelog|contributing|security|upgrade|deprecations)[^/]*\.md|docs|\.github|\.gitignore|\.editorconfig|\.psalm)(?:/|$)#i';

    /**
     * @param list<string> $composer The command that runs Composer (see ComposerLocator)
     */
    public function __construct(private string $project, private array $composer, private Process $process = new Process())
    {
    }

    /**
     * @param bool $includePublic Keep public/ in the code archive too. Only the split layout can do
     *                           without it: elsewhere the code archive is the only upload.
     *
     * @return array{code: string, public: string} The two archives
     *
     * @throws ReleaseException When git or Composer fails, or the project has no public/index.php
     */
    public function build(string $output, string $folder = 'website', string $ref = 'HEAD', bool $includePublic = true): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ReleaseException('release:build needs the zip extension (ext-zip).');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $folder) !== 1) {
            throw new ReleaseException("\"{$folder}\" is not a folder name.");
        }

        $work = sys_get_temp_dir() . '/zana-release-' . bin2hex(random_bytes(6));
        $source = "{$work}/source";
        try {
            mkdir($source, 0775, true);
            $this->process->run(['git', '-C', $this->project, 'archive', '--format=zip', '-o', "{$work}/source.zip", $ref], $this->project);
            $zip = new \ZipArchive();
            if ($zip->open("{$work}/source.zip") !== true || !$zip->extractTo($source)) {
                throw new ReleaseException('The archive of the commit cannot be unpacked.');
            }
            $zip->close();

            $index = "{$source}/public/index.php";
            if (!is_file($index)) {
                throw new ReleaseException('The project has no public/index.php.');
            }
            $code = (string) file_get_contents($index);
            $code = preg_replace("/const PROJECT_FOLDER = '[^']*';/", "const PROJECT_FOLDER = '{$folder}';", $code, 1, $count);
            if ($count !== 1) {
                throw new ReleaseException('public/index.php has no PROJECT_FOLDER constant.');
            }
            file_put_contents($index, $code);

            $this->process->run([...$this->composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--prefer-dist'], $source);

            if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
                throw new ReleaseException("The folder {$output} cannot be created.");
            }
            $archives = ['code' => "{$output}/{$folder}.zip", 'public' => "{$output}/public_html.zip"];
            self::zip($archives['code'], $source, "{$folder}/", static fn (string $path): bool => self::leftOut($path, $includePublic), ["{$folder}/var/"]);
            self::zip($archives['public'], "{$source}/public", '', static fn (): bool => false, []);

            return $archives;
        } finally {
            self::remove($work);
        }
    }

    private static function leftOut(string $path, bool $includePublic): bool
    {
        foreach ($includePublic ? self::EXCLUDED : [...self::EXCLUDED, 'public'] as $excluded) {
            if ($path === $excluded || str_starts_with($path, "{$excluded}/")) {
                return true;
            }
        }

        return preg_match(self::PLAN, $path) === 1 || preg_match(self::DEPENDENCY_DOCUMENTS, $path) === 1;
    }

    /**
     * @param \Closure(string): bool $leftOut Whether a path, relative to $directory, stays out
     * @param list<string> $emptyDirectories
     */
    private static function zip(string $file, string $directory, string $prefix, \Closure $leftOut, array $emptyDirectories): void
    {
        if (is_file($file)) {
            unlink($file);
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE) !== true) {
            throw new ReleaseException("{$file} cannot be written.");
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            assert($path instanceof \SplFileInfo);
            if (!$path->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($path->getPathname(), strlen($directory) + 1));
            if ($leftOut($relative)) {
                continue;
            }
            $zip->addFile($path->getPathname(), $prefix . $relative);
        }
        foreach ($emptyDirectories as $name) {
            $zip->addEmptyDir($name);
        }
        $zip->close();
    }

    private static function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
