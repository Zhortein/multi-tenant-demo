# Project Makefile

The repository Makefile is the supported entry point for local Docker tasks.
Run `make help` for the current command list. PHP and Composer always execute in
the project containers.

## Reproducible setup

From a complete checkout:

```console
make build
make storage-start
make install
make migrate
```

`make install` runs `composer install` against the committed `composer.lock`.
It never creates a Symfony skeleton, runs `composer update`, or rewrites project
sources. Container startup uses the same locked installation when `vendor/` is
empty and fails explicitly when either `composer.json` or `composer.lock` is
missing.

`make dev-setup` combines startup, locked dependency restoration, bundle cache
setup, and migrations for an already built image.

## Validation

```console
docker compose config --quiet
make test
make quality
```

The `quality` target creates and migrates the isolated `app_test` PostgreSQL
database, validates Doctrine mappings and schema synchronization, and runs
PHPUnit with the same database URL used by CI. It is safe to run repeatedly.

`make storage-start` generates local TLS, starts the private MinIO overlay and
provisions synthetic accounts. `make storage-status` checks its state,
`make storage-test` runs the mandatory real object-storage tests, and
`make storage-stop` stops the local stack while preserving named data volumes.
`make start` remains available for a separately configured external provider.

The quality target also runs `make phpstan`, `make cs-check` and
`make composer-check`. PHPStan is maximal with 194 independently measured
pre-existing diagnostics recorded in a baseline; formatting covers the new RC11
PHP surface. See [object-storage validation](object-storage.md#quality-and-database-matrix)
for the exact scope and the PostgreSQL 16/18 recipe. Development container
compilation uses 256 MiB; PHPUnit explicitly has 512 MiB available.

## Cleanup and data deletion

```console
make clean
```

This stops and removes this project's containers and orphaned containers. It
preserves named volumes and therefore preserves the local PostgreSQL database.

To intentionally delete this project's containers and named volumes:

```console
make destroy-local-data CONFIRM=destroy
```

This operation is irreversible for data stored only in those volumes. The
confirmation argument is mandatory. No Make target invokes `docker system
prune`, so unrelated Docker images, containers, networks, and caches are never
removed by project maintenance commands.
