<?php

declare(strict_types=1);

namespace Zana\Console\Release;

/**
 * Applies the exclusion rules to the project on a live server (FE-66). Each
 * route keeps its own way of deploying:
 *
 * - in a Git checkout, sparse-checkout keeps the files out, because deleting
 *   them would show as a change and later pulls could fail;
 * - anywhere else, the files are deleted.
 */
final readonly class LiveCleaner
{
    public function __construct(private string $project, private Process $process = new Process(120))
    {
    }

    public function usesGit(): bool
    {
        return file_exists("{$this->project}/.git");
    }

    /**
     * @return array{paths: list<string>, unsupported: list<string>} See ExclusionRules::live()
     */
    public function rules(bool $includePublic = false): array
    {
        return ExclusionRules::live($this->attributes(), $includePublic);
    }

    /**
     * @param list<string> $paths
     * @return list<string> Those present in the project
     */
    public function present(array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path): bool => file_exists("{$this->project}/{$path}") || is_link("{$this->project}/{$path}")));
    }

    /**
     * @param list<string> $paths
     * @return list<string> The paths still present afterwards
     *
     * @throws ReleaseException When Git refuses, or the checkout has sparse-checkout patterns of its own
     */
    public function clean(array $paths): array
    {
        if ($this->usesGit()) {
            $this->checkOutWithout($paths);
        } else {
            foreach ($this->present($paths) as $path) {
                ReleaseBuilder::remove("{$this->project}/{$path}");
            }
        }

        return $this->present($paths);
    }

    /**
     * @param list<string> $paths
     */
    private function checkOutWithout(array $paths): void
    {
        $existing = "{$this->project}/.git/info/sparse-checkout";
        $patterns = is_file($existing) ? (file($existing, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        if ($patterns !== [] && $patterns[0] !== '/*') {
            throw new ReleaseException("This checkout has sparse-checkout patterns of its own, in {$existing}. Nothing was changed.");
        }

        $this->process->run(['git', 'sparse-checkout', 'set', '--no-cone', '/*', ...array_map(static fn (string $path): string => "!/{$path}", $paths)], $this->project);
    }

    private function attributes(): ?string
    {
        if ($this->usesGit()) {
            try {
                return $this->process->run(['git', 'show', 'HEAD:.gitattributes'], $this->project);
            } catch (ReleaseException) {
                return null;   // no such file in the commit
            }
        }

        $content = is_file("{$this->project}/.gitattributes") ? file_get_contents("{$this->project}/.gitattributes") : false;

        return $content === false ? null : $content;
    }
}
