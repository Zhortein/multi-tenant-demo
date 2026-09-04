# Compatibility and Bundle Update Policy

## Supported application baseline

The demo currently targets PHP 8.3 or later, Symfony 7.4 LTS, DoctrineBundle 2.19, Doctrine ORM 3.6, and Doctrine DBAL 4.4. This is an explicitly tested bundle matrix entry and remains covered alongside newer framework combinations.

The bundle separately validates Symfony 8.1 on PHP 8.5 with both shared-database and multi-database consumer configurations. The full demo remains on Symfony 7.4 because its current stable DoctrineBundle, MonologBundle, and FrankenPHP runtime dependencies do not yet permit Symfony 8.1. This limitation is outside the bundle and must not be hidden by removing documented demo features.

## Release-candidate validation

The demo requires the exact `1.0.0-rc.8` bundle release candidate and `composer.lock` records the tagged source commit. The committed lock file is the reproducibility boundary: ordinary installations must run `composer install` and must not resolve a development branch or a future stable version implicitly.

Dependabot must ignore `zhortein/multi-tenant-bundle` throughout this release-candidate validation period. Bundle upgrades are deliberate compatibility changes: Dependabot must not replace the exact RC constraint with `dev-develop`, another pre-release, or any other version. Remove the ignore rule only in the same reviewed change that adopts an approved bundle release according to this policy.

After an upstream bundle pull request is merged:

1. Review the bundle compatibility and migration notes.
2. Run `composer update zhortein/multi-tenant-bundle --with-all-dependencies` in the project Docker environment.
3. Review the resolved source commit and the complete lock-file diff.
4. Run the clean installation, container compilation, database, and application smoke checks.
5. Commit the lock file together with any required demo adaptation.

## Stable releases

When a compatible stable bundle tag is published, review its migration notes before replacing the exact RC constraint with the narrowest supported stable constraint, refresh composer.lock through the same review process, and document the selected version. Do not remove the lock file: this application must remain installable from an immutable dependency graph.

## Upgrade validation

Every baseline upgrade must verify Composer validation and audit, dependency restoration from composer.lock, Symfony container compilation, Doctrine mappings and schema, migrations, PHPUnit, and HTTP smoke paths. Tenant-sensitive upgrades additionally require negative cross-tenant tests; successful installation alone is not evidence of isolation.

## Dependabot branch synchronization

Dependabot version updates target `develop` and must normally be merged there only after the complete required CI succeeds. Automatic merging is not enabled.

If a Dependabot pull request is exceptionally merged directly into `main`, synchronize it back to `develop` immediately:

1. Confirm that the Dependabot pull request and its complete required CI succeeded on `main`.
2. Create a dedicated synchronization branch from the latest `develop`.
3. Merge the latest `main` into that branch without rewriting either shared branch.
4. Resolve conflicts by preserving the reviewed dependency constraints and lock file from the Dependabot change, then run the complete required CI again.
5. Open a pull request from the synchronization branch to `develop`, merge it only when all required checks are green, and safely delete the merged synchronization branch.
# RC8 validation target

The fail-closed consumer target is PHP 8.5.9, Symfony 8.1.5, Doctrine ORM 3.6.8, DBAL 4.4.4, DoctrineBundle 3.3.1, DoctrineMigrationsBundle 4.0.1, and PostgreSQL 16 through 18. The current published demo graph remains Symfony 7.4 until its separately reviewed Symfony 8.1 migration is complete. The exact RC8 release is installed from Packagist and recorded in `composer.lock`; machine-specific Composer repositories must never be committed.

This demo uses one shared database and manages its application schema through
`doctrine:migrations:migrate`; it does not declare per-tenant migration paths or
tenant-specific connections. Consequently, `tenant:migrate` is not part of the
demo's operational contract. RC8's real normal, dry-run, idempotence, failure,
and connection-cleanup scenarios are exercised by the bundle's isolated public
consumer with DoctrineMigrationsBundle 4.0.1 on PostgreSQL 16 and 18.
