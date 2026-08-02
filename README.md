# Zana Console

Console (CLI) support for the Zana framework.

## Features

- **`CommandInterface`** / **`ConsoleKernelInterface`** — command contracts
- **`Command`** — base class for implementing a CLI command
- **`ConsoleKernel`** — loads commands and dispatches by name; auto-creates the registration table on first run

## Install

```
composer require zana/console
```

## Usage

```php
use Zana\Console\Kernel\ConsoleKernel;

$kernel = new ConsoleKernel();
$kernel->registerCommands([
    new MyCommand(),
]);
$kernel->handle(['my:command', 'arg1']);
```

## Testing

```
vendor/bin/phpunit packages/console/tests
```

## License

MIT — see [LICENSE](LICENSE).

