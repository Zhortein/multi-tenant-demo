#!/bin/sh
set -eu
status=0
output=$(composer --no-ansi validate --strict 2>&1) || status=$?
if [ "$status" -eq 0 ]; then
    printf '%s\n' "$output"
    exit 0
fi
expected=$(cat <<'TEXT'
./composer.json is valid, but with a few warnings
See https://getcomposer.org/doc/04-schema.md for details on the schema
# General warnings
- require.zhortein/multi-tenant-bundle : exact version constraints (1.0.0-rc.11) should be avoided if the package follows semantic versioning
TEXT
)
if [ "$status" -eq 1 ] && [ "$output" = "$expected" ]; then
    echo 'Composer schema and lock valid; only the documented exact-RC warning is present.'
    exit 0
fi
printf '%s\n' "$output" >&2
exit "$status"
