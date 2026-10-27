<?php

declare(strict_types=1);

namespace Zana\Console\Release;

/**
 * The command that runs Composer (FE-41), in order:
 *
 * 1. the one given (--composer), or COMPOSER_BINARY;
 * 2. a composer.phar in a PATH folder that has composer or composer.bat,
 *    as the Windows installer leaves it, run with the current PHP;
 * 3. on Windows, composer.bat through cmd /c (proc_open cannot start a .bat
 *    file on its own); elsewhere, composer.
 *
 * A .phar is always run with PHP_BINARY, so the build uses the same PHP.
 */
final readonly class ComposerLocator
{
    /**
     * @param string $path The PATH value
     */
    public function __construct(private string $path, private bool $windows = PHP_OS_FAMILY === 'Windows')
    {
    }

    public static function fromEnvironment(): self
    {
        $path = getenv('PATH');

        return new self(is_string($path) ? $path : '');
    }

    /**
     * @return list<string>
     */
    public function locate(?string $given = null): array
    {
        $binary = getenv('COMPOSER_BINARY');
        $given ??= is_string($binary) && $binary !== '' ? $binary : null;
        if ($given !== null) {
            return $this->command($given);
        }

        $script = null;
        foreach (explode($this->windows ? ';' : ':', $this->path) as $directory) {
            $directory = rtrim(trim($directory, " \""), '/\\');
            if ($directory === '') {
                continue;
            }
            foreach ($this->windows ? ['composer.bat', 'composer'] : ['composer'] as $name) {
                if (is_file("{$directory}/{$name}")) {
                    if (is_file("{$directory}/composer.phar")) {
                        return [PHP_BINARY, "{$directory}/composer.phar"];
                    }
                    $script ??= "{$directory}/{$name}";
                }
            }
        }

        if ($this->windows) {
            return ['cmd', '/c', $script !== null && str_ends_with($script, '.bat') ? $script : 'composer'];
        }

        return [$script ?? 'composer'];
    }

    /**
     * @return list<string>
     */
    private function command(string $composer): array
    {
        return match (true) {
            str_ends_with(strtolower($composer), '.phar') => [PHP_BINARY, $composer],
            $this->windows && preg_match('/\.(bat|cmd)$/i', $composer) === 1 => ['cmd', '/c', $composer],
            default => [$composer],
        };
    }
}
