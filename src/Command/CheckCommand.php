<?php

declare(strict_types=1);

namespace Zana\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Console\Internal\Diagnostics;
use Zana\Console\Internal\Report;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Testing\Architecture\ModuleRules;

/**
 * php bin/console check [--format=json]
 *
 * Verifies the application (FE-46, ADR-023): the deployment checks, that
 * every singleton can be created, the modules' architecture rules (with
 * mj-zana/testing installed), and every ApplicationCheck the packages and
 * modules register, such as routes whose handler is not registered. Each
 * finding has an id, a location when there is one, and a hint. Exits with 1
 * when a finding is an error.
 *
 * A container that cannot be built is reported by ConsoleKernel::runBuilder()
 * in the same format. doctor checks the server; check checks the application.
 */
#[AsCommand(name: 'check', description: 'Check the application: wiring, architecture, routes, migrations')]
final class CheckCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    public function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ApplicationInterface $application): int
    {
        $format = Report::format($input->getOption('format'));
        $report = new Report();

        $report->pass('The container builds');
        Diagnostics::deploymentChecks($application, $report);
        Diagnostics::singletons($application, $report);
        self::architecture($application, $report);

        try {
            $checks = $application->container()->tagged(ApplicationCheck::TAG);
        } catch (\Throwable $e) {
            $report->add(new Finding('check.unavailable', Severity::Error, 'The application checks cannot be created: ' . strtok($e->getMessage(), "\n")));
            $checks = [];
        }

        foreach ($checks as $check) {
            if (!$check instanceof ApplicationCheck) {
                throw new \LogicException('Services tagged ' . ApplicationCheck::TAG . ' must implement ' . ApplicationCheck::class . '; ' . get_debug_type($check) . ' does not.');
            }
            $findings = $check->findings($application);
            $findings === [] ? $report->pass(self::name($check)) : $report->add(...$findings);
        }

        return $report->render($output, $io, $format, 'The application passes its checks.', 'The application has errors.');
    }

    private static function architecture(ApplicationInterface $application, Report $report): void
    {
        $modules = $application->path('modules');
        if (!class_exists(ModuleRules::class)) {
            $report->add(new Finding('architecture.unavailable', Severity::Info, 'The architecture rules were not checked: they come with mj-zana/testing.', hint: 'composer require --dev mj-zana/testing'));

            return;
        }
        if (!is_dir($modules)) {
            return;
        }

        // 2.30, ADR-034: the shared kernel that architecture.shared_kernel declares, read as the application's test reads it.
        try {
            // mj-zana/testing is optional, so an application may pair this console with a testing before 2.30.
            // @phpstan-ignore function.alreadyNarrowedType
            $rules = method_exists(ModuleRules::class, 'forApplication') ? ModuleRules::forApplication($application) : ModuleRules::for($modules);
        } catch (\InvalidArgumentException $e) {
            $report->add(new Finding('architecture.shared_kernel', Severity::Error, $e->getMessage(), hint: 'List modules\' Contracts namespaces in architecture.shared_kernel, such as Modules\Shared\Contracts.'));
            $rules = ModuleRules::for($modules);
        }
        // @phpstan-ignore function.alreadyNarrowedType
        $kernel = method_exists($rules, 'kernel') ? $rules->kernel() : [];
        // @phpstan-ignore function.alreadyNarrowedType
        foreach (method_exists($rules, 'emptyKernel') ? $rules->emptyKernel() : [] as $empty) {
            $report->add(new Finding('architecture.shared_kernel', Severity::Warning, "The shared kernel {$empty} has no directory, so it shares nothing.", hint: 'Check the module\'s name, or create its Contracts directory.'));
        }

        $violations = $rules->check();
        if ($violations === []) {
            $report->pass('Architecture: ' . count($rules->modules()) . ' module(s)' . ($kernel === [] ? '' : ', shared kernel ' . implode(', ', $kernel)) . ', no violation');

            return;
        }
        foreach ($violations as $violation) {
            $report->add(new Finding(
                'architecture.dependency',
                Severity::Error,
                "{$violation->namespace} depends on {$violation->dependency} ({$violation->rule}).",
                $violation->file,
                $violation->line,
                self::hint($violation->namespace, $violation->dependency, $kernel),
            ));
        }
    }

    /**
     * How to fix a dependency that breaks the module rules.
     */
    /**
     * @param list<string> $kernel The shared kernel's namespaces
     */
    private static function hint(string $namespace, string $dependency, array $kernel = []): string
    {
        $from = explode('\\', $namespace);
        $to = explode('\\', $dependency);
        $otherModule = $to[0] === $from[0] && isset($from[1], $to[1]) && $from[1] !== $to[1] ? $to[1] : null;
        $inKernel = array_filter($kernel, static fn (string $k): bool => $namespace === $k || str_starts_with($namespace, $k . '\\')) !== [];
        if ($inKernel) {
            return 'The shared kernel holds values: it depends only on plain PHP, Zana\Ddd and the other kernels, never on a module or a service. Move this to its module\'s Infrastructure.';
        }
        if ($otherModule !== null && ($to[2] ?? '') === 'Contracts' && in_array($from[2] ?? '', ['Domain', 'Contracts'], true)) {
            return "{$from[2]} may not use another module's Contracts. If {$otherModule}'s values belong to every module, such as money or a clock, declare its Contracts as the shared kernel (architecture.shared_kernel); otherwise use them from Application.";
        }
        if ($otherModule !== null) {
            return "Depend on the {$otherModule} module's Contracts instead of its internals, and declare the module in dependsOn().";
        }

        return match ($from[2] ?? '') {
            'Domain' => 'Domain code depends only on itself and Zana\Ddd: move this to Application or Infrastructure, behind an interface in Domain.',
            'Application' => 'Depend on an interface in this module\'s Domain or Contracts, and implement it in Infrastructure.',
            'Presentation' => 'Presentation goes through Application (a command or a query), not Domain or Infrastructure directly.',
            'Infrastructure' => 'Infrastructure must not use Presentation: move the shared code to Application.',
            default => 'See the dependency rules in the module cookbook.',
        };
    }

    private static function name(object $check): string
    {
        return substr((string) strrchr('\\' . $check::class, '\\'), 1);
    }
}
