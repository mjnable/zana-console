<?php

// Prints a line, then sleeps: a command that does not finish in time.
echo "started\n";
sleep((int) ($argv[1] ?? 30));
