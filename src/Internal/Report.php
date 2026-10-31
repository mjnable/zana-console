<?php

declare(strict_types=1);

namespace Zana\Console\Internal;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * The results of doctor and check (ADR-023): what passed, and the findings.
 * Shown as a list for people, or as JSON (schema "zana.check/1") for agents
 * and CI:
 *
 *     {"schema": "zana.check/1", "status": "error", "passed": ["…"], "findings": [{"id": "…", "severity": "error", …}]}
 *
 * @internal
 */
final class Report
{
    public const string SCHEMA = 'zana.check/1';

    /** @var list<string> */
    private array $passed = [];

    /** @var list<Finding> */
    private array $findings = [];

    public function pass(string $message): void
    {
        $this->passed[] = $message;
    }

    public function add(Finding ...$findings): void
    {
        array_push($this->findings, ...array_values($findings));
    }

    public function status(): Severity|null
    {
        foreach ([Severity::Error, Severity::Warning] as $severity) {
            foreach ($this->findings as $finding) {
                if ($finding->severity === $severity) {
                    return $severity;
                }
            }
        }

        return null;
    }

    /**
     * Write the report; returns the exit code: 1 when a finding is an error.
     */
    public function render(OutputInterface $output, SymfonyStyle $io, string $format, string $ok, string $failed): int
    {
        $status = $this->status();
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => self::SCHEMA,
                'status' => $status === null ? 'ok' : $status->value,
                'passed' => $this->passed,
                'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            foreach ($this->passed as $message) {
                $io->writeln("  <info>✔</info> {$message}");
            }
            foreach ($this->findings as $finding) {
                $mark = match ($finding->severity) {
                    Severity::Error => '<error>✘</error>',
                    Severity::Warning => '<comment>!</comment>',
                    Severity::Info => '<info>i</info>',
                };
                $where = $finding->file === null ? '' : ' (' . $finding->file . ($finding->line === null ? '' : ":{$finding->line}") . ')';
                $io->writeln("  {$mark} {$finding->message}{$where}");
                if ($finding->hint !== null) {
                    $io->writeln("      → {$finding->hint}");
                }
            }
            $io->newLine();
            $status === Severity::Error ? $io->error($failed) : $io->success($ok);
        }

        return $status === Severity::Error ? 1 : 0;
    }

    /**
     * The --format option's value, or an exception for an unknown one.
     */
    public static function format(mixed $value): string
    {
        return in_array($value, ['text', 'json'], true) ? (string) $value : throw new \InvalidArgumentException('--format must be "text" or "json".');
    }
}
