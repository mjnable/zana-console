<?php

declare(strict_types=1);

namespace Zana\Console;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Container\Contracts\ServiceResolverInterface;

/**
 * A Symfony command whose handle() method gets its dependencies from the
 * command's container scope. Parameters typed InputInterface, OutputInterface
 * or SymfonyStyle receive the current input, output and a style for them.
 *
 *     public function handle(InputInterface $input, SymfonyStyle $io, PatientRepository $patients): int
 *
 * handle() returns an exit code, or null for success.
 */
abstract class Command extends SymfonyCommand
{
    private ?ServiceResolverInterface $resolver = null;

    /**
     * Set by the console kernel for each run; set it yourself to run the command elsewhere.
     */
    public function setResolver(?ServiceResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->resolver === null) {
            throw new \LogicException(static::class . ' needs a resolver: run it through ConsoleKernel or call setResolver().');
        }

        if (!method_exists($this, 'handle')) {
            throw new \LogicException(static::class . ' must define a handle() method.');
        }

        $result = $this->resolver->call([$this, 'handle'], $this->overrides($input, $output));

        return match (true) {
            $result === null => self::SUCCESS,
            is_int($result) => $result,
            default => throw new \UnexpectedValueException(static::class . '::handle() must return an int or null, got ' . get_debug_type($result) . '.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(InputInterface $input, OutputInterface $output): array
    {
        $overrides = [];
        foreach ((new \ReflectionMethod($this, 'handle'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            $value = match (true) {
                $name === null => null,
                is_a($name, SymfonyStyle::class, true) => new SymfonyStyle($input, $output),
                is_a($name, InputInterface::class, true) => $input,
                is_a($name, OutputInterface::class, true) => $output,
                default => null,
            };

            if ($value !== null) {
                $overrides[$parameter->getName()] = $value;
            }
        }

        return $overrides;
    }
}
