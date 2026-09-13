<?php

declare(strict_types=1);

// Application-only key: never mounted into MinIO or printed, including on reuse.
$directory = dirname(__DIR__).'/var/object-storage/audit';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot prepare local audit key directory.');
}
$path = $directory.'/current.key';
if (is_file($path)) {
    echo "Reusing local audit key.\n";
    exit(0);
}
umask(0077);
$stream = fopen($path, 'x');
if (false === $stream) {
    throw new RuntimeException('Cannot create local audit key.');
}
try {
    if (64 !== fwrite($stream, bin2hex(random_bytes(32)))) {
        throw new RuntimeException('Cannot write local audit key.');
    }
} finally {
    fclose($stream);
}
echo "Local audit key created.\n";
