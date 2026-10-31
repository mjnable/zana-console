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
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * Checks the server before going live (FE-18): the PHP version and the
 * extensions the application needs (app.extensions), a writable var/
 * directory, the length of security.secret, every deployment check the
 * application runs at startup, and that every singleton can be created: a
 * factory that resolves a scoped service, which the build cannot see, fails
 * here rather than in traffic (FE-40). Exits with 1 when something is fatal.
 *
 * --format=json writes the same report as the check command (ADR-023).
 */
#[AsCommand(name: 'doctor', description: 'Check that this server is ready to run the application')]
final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    public function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io, ApplicationInterface $application): int
    {
        $format = Report::format($input->getOption('format'));
        $config = $application->config();
        $report = new Report();

        if (version_compare(PHP_VERSION, '8.5.0', '>=')) {
            $report->pass('PHP ' . PHP_VERSION);
        } else {
            $report->add(new Finding('server.php', Severity::Error, 'PHP ' . PHP_VERSION . ' is too old: Zana 2 needs 8.5 or later.'));
        }

        /** @var list<string> $extensions */
        $extensions = $config->array('app.extensions', []);
        foreach (array_unique(['json', 'mbstring', ...$extensions]) as $extension) {
            if (extension_loaded($extension)) {
                $report->pass("Extension {$extension}");
            } else {
                $report->add(new Finding('server.extension', Severity::Error, "The PHP extension {$extension} is missing."));
            }
        }

        $var = $application->path('var');
        match (true) {
            is_dir($var) && is_writable($var) => $report->pass("{$var} is writable"),
            is_dir($var) => $report->add(new Finding('server.var', Severity::Error, "{$var} is not writable.")),
            default => $report->add(new Finding('server.var', Severity::Warning, "{$var} does not exist; the application creates it on first write.")),
        };

        if ($config->has('security.secret')) {
            if (strlen($config->string('security.secret')) >= 32) {
                $report->pass('security.secret is long enough');
            } else {
                $report->add(new Finding('security.secret', Severity::Error, 'security.secret must be at least 32 bytes.', hint: 'Generate one: php -r "echo bin2hex(random_bytes(32));"'));
            }
        }

        Diagnostics::deploymentChecks($application, $report);
        Diagnostics::singletons($application, $report);

        return $report->render($output, $io, $format, 'This server is ready.', 'This server is not ready.');
    }
}
