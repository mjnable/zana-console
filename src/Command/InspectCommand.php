<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Composer\InstalledVersions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Console\Internal\Report;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\InspectionSection;
use Zana\Core\Module;

/**
 * php bin/console inspect [section] [--format=json]
 *
 * The application as Zana sees it (FE-45, ADR-023): its environment,
 * modules, services, commands, configuration keys and packages, plus the
 * sections packages contribute (routes, migrations, ...). Configuration is
 * shown as keys and value types, never values. With --format=json the output
 * is {"schema": "zana.inspect/1", "sections": {...}}.
 */
#[AsCommand(name: 'inspect', description: 'Show the application: modules, services, routes, commands, configuration keys')]
final class InspectCommand extends Command
{
    public const string SCHEMA = 'zana.inspect/1';

    protected function configure(): void
    {
        $this
            ->addArgument('section', InputArgument::OPTIONAL, 'One section, e.g. routes')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    public function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ApplicationInterface $application): int
    {
        $format = Report::format($input->getOption('format'));
        $sections = $this->sections($application);

        $only = $input->getArgument('section');
        if (is_string($only)) {
            if (!array_key_exists($only, $sections)) {
                $io->error("There is no \"{$only}\" section. The sections are: " . implode(', ', array_keys($sections)) . '.');

                return self::FAILURE;
            }
            $sections = [$only => $sections[$only]];
        }

        $data = [];
        foreach ($sections as $name => $section) {
            $data[$name] = $section();
        }

        if ($format === 'json') {
            $output->writeln((string) json_encode(['schema' => self::SCHEMA, 'sections' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        foreach ($data as $name => $rows) {
            $io->section($name);
            self::show($io, $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Section name => a closure computing its data, so one section can be shown alone cheaply.
     *
     * @return array<string, \Closure(): array<array-key, mixed>>
     */
    private function sections(ApplicationInterface $application): array
    {
        $sections = [
            'environment' => static fn (): array => [
                'name' => $application->environment()->name,
                'debug' => $application->environment()->debug,
                'php' => PHP_VERSION,
                'base_path' => $application->basePath(),
            ],
            'modules' => static fn (): array => array_map(static fn (Module $module): array => [
                'name' => $module->name(),
                'class' => $module::class,
                'depends_on' => array_map(static fn (string $class): string => $class, $module->dependsOn()),
                'path' => $module->path(),
            ], $application->modules()),
            'services' => static fn (): array => array_values(array_filter(array_map(static fn ($node): ?array => $node->strategy === 'alias' ? null : [
                'service' => $node->service,
                'key' => $node->key,
                'lifetime' => $node->lifetime,
                'strategy' => $node->strategy,
                'implementation' => $node->implementation,
                'origin' => $node->origin,
                'tags' => $node->tags,
            ], $application->container()->graph()->nodes()))),
            'commands' => fn (): array => array_values(array_map(static fn ($command): array => [
                'name' => (string) $command->getName(),
                'description' => $command->getDescription(),
            ], array_filter($this->getApplication()?->all() ?? [], static fn ($command): bool => !$command->isHidden()))),
            'config' => static fn (): array => self::keys($application->config()->all()),
            'packages' => static fn (): array => self::packages(),
        ];

        foreach ($application->container()->tagged(InspectionSection::TAG) as $section) {
            if (!$section instanceof InspectionSection) {
                throw new \LogicException('Services tagged ' . InspectionSection::TAG . ' must implement ' . InspectionSection::class . '; ' . get_debug_type($section) . ' does not.');
            }
            $sections[$section->name()] = static fn (): array => $section->inspect($application);
        }

        return $sections;
    }

    /**
     * Configuration keys with the type of their value; never the value.
     *
     * @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private static function keys(array $values, string $prefix = ''): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $keys += self::keys($value, "{$path}.");
            } else {
                $keys[$path] = get_debug_type($value);
            }
        }
        ksort($keys);

        return $keys;
    }

    /**
     * @return array<string, string> Installed Composer packages and their versions
     */
    private static function packages(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }
        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $version = InstalledVersions::getPrettyVersion($name);
            if ($version !== null && $name !== InstalledVersions::getRootPackage()['name']) {
                $packages[$name] = $version;
            }
        }
        ksort($packages);

        return $packages;
    }

    /**
     * @param array<array-key, mixed> $rows
     */
    private static function show(SymfonyStyle $io, array $rows): void
    {
        if ($rows === []) {
            $io->writeln('  (none)');

            return;
        }
        if (array_is_list($rows) && is_array($rows[0])) {
            $io->table(
                array_map('strval', array_keys($rows[0])),
                array_map(static fn (mixed $row): array => array_map(self::cell(...), is_array($row) ? array_values($row) : [$row]), $rows),
            );

            return;
        }
        foreach ($rows as $key => $value) {
            $io->writeln(sprintf('  %-40s %s', $key, self::cell($value)));
        }
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }
}
