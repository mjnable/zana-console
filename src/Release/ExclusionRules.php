<?php

declare(strict_types=1);

namespace Zana\Console\Release;

/**
 * What does not belong on a live server (FE-65, FE-66). This is the one list:
 * release:build leaves these paths out of its archive, release:clean applies
 * them on the server, and doctor warns about them, so the routes to a live
 * server cannot drift apart.
 */
final class ExclusionRules
{
    /** Paths from the project's root that never ship. */
    public const array PATHS = [
        'tests', '.github', 'phpunit.xml.dist', 'phpunit.xml', '.gitattributes', '.gitignore', '.env', '.env.testing',
        'AGENTS.md', 'CLAUDE.md', 'README.md', '.env.example',
    ];

    /**
     * Never touched on a live server, whatever the list or .gitattributes say.
     * .env is left out of archives so that the local one does not ship; on the
     * server it is the configuration.
     */
    private const array PROTECTED = ['.env', '.git', 'var', 'vendor'];

    private const string PUBLIC = 'public';

    /**
     * @param string|null $attributes The content of .gitattributes: its literal export-ignore paths count
     * @param bool $includePublic Add public/, for release:clean --no-public
     *
     * @return array{paths: list<string>, unsupported: list<string>} The paths for a live server, and the export-ignore patterns that cannot be applied there
     */
    public static function live(?string $attributes = null, bool $includePublic = false): array
    {
        ['paths' => $ignored, 'unsupported' => $unsupported] = self::exportIgnored($attributes ?? '');
        $paths = array_filter([...self::PATHS, ...$ignored], static fn (string $path): bool => !self::isProtected($path));
        if ($includePublic) {
            $paths[] = self::PUBLIC;
        }

        return ['paths' => array_values(array_unique($paths)), 'unsupported' => $unsupported];
    }

    /**
     * @return array{paths: list<string>, unsupported: list<string>}
     */
    private static function exportIgnored(string $attributes): array
    {
        $paths = [];
        $unsupported = [];
        foreach (preg_split('/\R/', $attributes) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if ($fields === [] || $fields[0] === '' || $fields[0][0] === '#' || !in_array('export-ignore', array_slice($fields, 1), true)) {
                continue;
            }
            $path = trim($fields[0], '/');
            if (self::isLiteral($path)) {
                $paths[] = $path;
            } else {
                $unsupported[] = $fields[0];
            }
        }

        return ['paths' => $paths, 'unsupported' => $unsupported];
    }

    /**
     * Whether a path from .gitattributes, which is the application's input, is
     * a plain path inside the project.
     */
    private static function isLiteral(string $path): bool
    {
        if ($path === '' || preg_match('/[*?\[\]\\\\!"]/', $path) === 1 || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isProtected(string $path): bool
    {
        $root = strtolower(explode('/', $path)[0]);

        return in_array($root, self::PROTECTED, true) || strtolower($path) === self::PUBLIC;
    }
}
