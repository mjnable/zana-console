# Zana Console

Console commands on Symfony Console, each run in its own container scope.
Requires PHP 8.5 or later.

```
composer require mj-zana/console
```

## Usage

```php
// bin/zana
$app = require __DIR__ . '/../bootstrap/app.php';   // with ConsoleServiceProvider
exit(Zana\Console\ConsoleKernel::runApplication($app));
```

```php
#[AsCommand(name: 'bmi:report', description: 'Print the BMI report of a patient')]
final class BmiReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('patient', InputArgument::REQUIRED);
    }

    public function handle(InputInterface $input, SymfonyStyle $io, MeasurementRepository $measurements): int
    {
        $io->table(['Date', 'BMI'], $measurements->rowsFor($input->getArgument('patient')));

        return self::SUCCESS;   // or null
    }
}
```

Register commands as services tagged `console.commands`:

```php
$services->transient(BmiReportCommand::class)->tag(ConsoleKernel::TAG, BmiReportCommand::class);
```

- **Scope:** every run gets a fresh scope (`ScopeMetadata::cli('bmi:report')`),
  disposed afterwards.
- **`handle()` injection:** the command's dependencies are injected, plus the
  current input, output and a `SymfonyStyle`, by type.
- **Plain Symfony commands** work too.

## Deployment commands

| Command | |
|---|---|
| `cache:clear`, `cache:warm` | The compiled container, the configuration cache, and tagged caches |
| `about` | The application, its environment and its Zana packages |
| `doctor` | Checks the server before going live, singletons included |
| `release:build` | Upload archives for shared hosting, from a commit (2.5). `-v` shows git and Composer, `--timeout` stops a stuck build |

## License

MIT — see [LICENSE](LICENSE).
