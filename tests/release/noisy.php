<?php

// Writes the given number of KB to stderr, then "done" to stdout: more than a pipe buffer holds.
$kb = (int) ($argv[1] ?? 100);
for ($i = 0; $i < $kb; $i++) {
    fwrite(STDERR, str_repeat('x', 1023) . "\n");
}
echo 'done';
exit((int) ($argv[2] ?? 0));
