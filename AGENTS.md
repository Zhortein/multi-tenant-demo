# Repository Working Guidelines

## Purpose and relationship

This repository is the reference Symfony application for
`Zhortein/multi-tenant-bundle`. It must consume the bundle as a real external
application and serve as executable documentation, integration validation, and
non-regression coverage. Do not duplicate bundle internals in the demo.

When bundle APIs or configuration change, update the demo in the same roadmap
sequence and keep the compatibility boundary explicit.

## Git workflow

- `main` is the published integration branch; `develop` is the active
  development branch.
- Create focused implementation branches from `develop` and target pull
  requests to `develop`, unless the release workflow specifies otherwise.
- Preserve unrelated local and IDE files. Keep repository guidance in
  `AGENTS.md` aligned with the application workflow.
- Do not commit secrets, `.env.local`, `.env.test`, runtime uploads, database
  volumes, caches, or generated assets.

## Environment and commands

Use Docker Compose and the repository Makefile; do not install project tooling
on the host.

- `docker compose config --quiet`: validate Compose configuration.
- `make build`: build the application images (uses `--no-cache`).
- `make start` / `make stop`: start or stop the stack.
- `make migrate`: apply migrations.
- `make test`: run PHPUnit inside the `php` service.
- `make quality`: prepare the test database, load fixtures, validate the
  Doctrine schema, and run PHPUnit.

The `clean`, `reset`, and `install` targets are destructive or rewrite the
project; do not run them without explicit authorization. Restore dependencies
from `composer.lock` and do not update it unless dependency changes are the
authorized task.

## Application conventions

- Symfony configuration lives under `config/`; routes use PHP attributes.
- Doctrine entities and migrations are under `src/Entity` and `migrations`.
- Tenant-owned behavior must use the bundle’s current public attribute/trait
  API and must never rely only on controller filtering.
- Every tenant-scoped read, write, upload, queued message, email configuration,
  and cache key needs a negative cross-tenant test.
- Administration and tenant management must have explicit authentication and
  authorization rules; UI navigation is not an authorization boundary.
- Demo fixtures must be deterministic and usable in CI.

## Validation

For changes, add application-level tests and run, when practical:

1. `docker compose config --quiet`
2. dependency restoration from `composer.lock`
3. migrations in the test environment
4. Doctrine schema validation
5. PHPUnit, including cross-tenant isolation scenarios
6. HTTP smoke tests

The CI workflow is the source of truth for the Docker build, HTTP and Mercure
smoke checks, database migration, deterministic fixtures, Doctrine schema
validation, PHPUnit, and Dockerfile linting. Report all skipped checks and
environment limitations.


## Public communication and security

Write repository documentation, issues, pull requests, review comments,
changelog entries, release notes, branch names, and commit messages in
professional English. Never publish credentials, private infrastructure
details, production data, or machine-specific configuration. This demo proves
consumer integration; it does not define bundle-internal APIs or release
policy. Report tenant-isolation or authorization regressions as
security-sensitive defects.
