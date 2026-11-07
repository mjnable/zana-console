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
 * .gitattributes leaves a path out of both. Tests, development tooling and
 * the project's documents are left out.
 */
final readonly class ReleaseBuilder
{
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
            self::zip($archives['code'], $source, "{$folder}/", $includePublic ? ExclusionRules::PATHS : [...ExclusionRules::PATHS, 'public'], ["{$folder}/var/"]);
            self::zip($archives['public'], "{$source}/public", '', [], []);

            return $archives;
        } finally {
            self::remove($work);
        }
    }

    /**
     * @param list<string> $excluded Top-level names to leave out
     * @param list<string> $emptyDirectories
     */
    private static function zip(string $file, string $directory, string $prefix, array $excluded, array $emptyDirectories): void
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
            if (in_array(explode('/', $relative)[0], $excluded, true)) {
                continue;
            }
            $zip->addFile($path->getPathname(), $prefix . $relative);
        }
        foreach ($emptyDirectories as $name) {
            $zip->addEmptyDir($name);
        }
        $zip->close();
    }

    /**
     * Deletes a file or a folder, as far as it can: the caller checks what is
     * left. Links are removed, not followed.
     */
    public static function remove(string $path): void
    {
        if (self::isLink($path) || !is_dir($path)) {
            self::removeEntry($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove("{$path}/{$name}");
            }
        }
        @rmdir($path);
    }

    /**
     * Asked of every entry before going into it. On Windows, is_link() is false
     * for a junction, is_dir() can be true or false for the same one, and
     * readlink() answers for plain folders too, so a recursive iterator or
     * is_dir() alone can walk into a target and delete what is there. The real
     * path tells: an entry that is not where its parent says is a link. When in
     * doubt the answer is yes, which can only leave something behind.
     */
    private static function isLink(string $path): bool
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        $parent = realpath(dirname($path));
        if (is_link($path) || $real === false || $parent === false) {
            return true;
        }
        $expected = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($real, $expected) !== 0 : $real !== $expected;
    }

    /**
     * rmdir is what removes a junction, or a link to a folder, on Windows. It
     * never empties anything, so a target is safe.
     */
    private static function removeEntry(string $path): void
    {
        if (!@unlink($path)) {
            @rmdir($path);
        }
    }
}
