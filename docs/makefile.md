# Project Makefile

The repository Makefile is the supported entry point for local Docker tasks.
Run `make help` for the current command list. PHP and Composer always execute in
the project containers.

## Reproducible setup

From a complete checkout:

```console
make build
make start
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

The current `quality` target contains only PHPUnit because PHPStan and
PHP-CS-Fixer are not declared development dependencies in this application.
Targets for undeclared tools must not be advertised as working checks.

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
