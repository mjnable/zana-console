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
     * @return array{paths: list<string>, unsupported: list<string>, rootOnly: list<string>} The paths for a live server, the export-ignore patterns
     *                                                                                 that cannot be applied there, and those applied at the root only,
     *                                                                                 which Git matches in any folder
     */
    public static function live(?string $attributes = null, bool $includePublic = false): array
    {
        ['paths' => $ignored, 'unsupported' => $unsupported, 'rootOnly' => $rootOnly] = self::exportIgnored($attributes ?? '');
        $paths = array_filter([...self::PATHS, ...$ignored], static fn (string $path): bool => !self::isProtected($path));
        if ($includePublic) {
            $paths[] = self::PUBLIC;
        }

        return ['paths' => array_values(array_unique($paths)), 'unsupported' => $unsupported, 'rootOnly' => $rootOnly];
    }

    /**
     * @return array{paths: list<string>, unsupported: list<string>, rootOnly: list<string>}
     */
    private static function exportIgnored(string $attributes): array
    {
        $paths = [];
        $unsupported = [];
        $rootOnly = [];
        foreach (preg_split('/\R/', $attributes) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if ($fields === [] || $fields[0] === '' || $fields[0][0] === '#' || !in_array('export-ignore', array_slice($fields, 1), true)) {
                continue;
            }
            $path = trim((string) preg_replace('#/\*\*$#', '', $fields[0]), '/');   // /docs/** is /docs, for an export
            if (self::isLiteral($path)) {
                $paths[] = $path;
                if (!str_contains(rtrim($fields[0], '/'), '/')) {
                    $rootOnly[] = $fields[0];   // no slash: Git matches it at any depth
                }
            } else {
                $unsupported[] = $fields[0];
            }
        }

        return ['paths' => $paths, 'unsupported' => $unsupported, 'rootOnly' => $rootOnly];
    }

    /**
     * Whether a path from .gitattributes, which is the application's input, is
     * a plain path inside the project. This allows what is known to be plain
     * rather than refusing what is known to be dangerous, because the file
     * system has more spellings than anyone lists: on Windows, ".env.." and
     * "ENV~1" both open .env. A file with another name goes in the report as
     * not applied. LiveCleaner checks the disk as well, before it deletes.
     */
    private static function isLiteral(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            $plain = preg_match('/^[A-Za-z0-9._-]+$/D', $segment) === 1 && !str_ends_with($segment, '.');
            if (!$plain || preg_match('/^(con|prn|aux|nul|com\d|lpt\d)(\.|$)/i', $segment) === 1) {
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
