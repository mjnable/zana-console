<?php

declare(strict_types=1);

namespace Zana\Console\Release;

/**
 * Runs a command to completion (FE-41). The output, stderr merged into
 * stdout, goes to a temporary file rather than pipes: a pipe that nobody
 * reads fills up (about 4 KB on Windows) and blocks the command for ever,
 * and PHP cannot wait on pipes with a timeout on Windows. The file is polled
 * instead, which also lets the caller show the output as it comes.
 *
 * @internal
 */
final readonly class Process
{
    /**
     * @param int $timeout Seconds before the command and its children are stopped
     * @param (\Closure(string): void)|null $output Receives the output as it comes
     */
    public function __construct(private int $timeout = 600, private ?\Closure $output = null)
    {
    }

    /**
     * @param list<string> $command
     * @return string The whole output
     *
     * @throws ReleaseException When the command cannot start, fails, or runs out of time
     */
    public function run(array $command, string $directory): string
    {
        $log = tempnam(sys_get_temp_dir(), 'zana-release-');
        if ($log === false) {
            throw new ReleaseException('A temporary file for the command output cannot be created.');
        }

        $writer = fopen($log, 'w');
        try {
            if ($writer === false) {
                throw new ReleaseException("{$log} cannot be written.");
            }
            // One handle for stdout and stderr: the output in order, and nothing can fill up
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => $writer, 2 => $writer], $pipes, $directory);
            fclose($writer);
            if (!is_resource($process)) {
                throw new ReleaseException("{$command[0]} cannot be started. Pass its path with --composer, or set COMPOSER_BINARY.");
            }
            fclose($pipes[0]);

            $reader = fopen($log, 'r');
            $output = '';
            $started = microtime(true);
            $status = proc_get_status($process);
            while ($status['running']) {
                $output .= $this->read($reader);
                if (microtime(true) - $started > $this->timeout) {
                    self::stop($process, $status['pid']);
                    throw new ReleaseException(sprintf("%s did not finish within %d seconds:\n%s", implode(' ', $command), $this->timeout, self::tail($output . $this->read($reader))));
                }
                usleep(50_000);
                $status = proc_get_status($process);
            }
            $output .= $this->read($reader);
            if (is_resource($reader)) {
                fclose($reader);
            }
            // proc_get_status() reports the exit code once, when it first sees the process ended
            $code = $status['exitcode'];
            proc_close($process);

            if ($code !== 0) {
                throw new ReleaseException(implode(' ', $command) . " failed (exit code {$code}):\n" . self::tail($output));
            }

            return $output;
        } finally {
            @unlink($log);
        }
    }

    /**
     * @param resource|false $reader
     */
    private function read($reader): string
    {
        if (!is_resource($reader)) {
            return '';
        }
        $chunk = (string) stream_get_contents($reader);
        if ($chunk !== '' && $this->output !== null) {
            ($this->output)($chunk);
        }

        return $chunk;
    }

    /**
     * Stop the command and the processes it started (Composer run through cmd, for example).
     *
     * @param resource $process
     */
    private static function stop($process, int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
        }
        proc_terminate($process, 9);
        proc_close($process);
    }

    /**
     * The last lines of an output, for error messages.
     */
    private static function tail(string $output, int $lines = 30): string
    {
        $all = preg_split('/\R/', rtrim($output)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
