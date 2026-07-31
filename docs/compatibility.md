# Compatibility and Bundle Update Policy

## Supported application baseline

The demo currently targets PHP 8.3 or later, Symfony 7.4 LTS, DoctrineBundle 2.19, Doctrine ORM 3.6, and Doctrine DBAL 4.4. This is an explicitly tested bundle matrix entry and remains covered alongside newer framework combinations.

The bundle separately validates Symfony 8.1 on PHP 8.5 with both shared-database and multi-database consumer configurations. The full demo remains on Symfony 7.4 because its current stable DoctrineBundle, MonologBundle, and FrankenPHP runtime dependencies do not yet permit Symfony 8.1. This limitation is outside the bundle and must not be hidden by removing documented demo features.

## Development branch validation

Before a stable bundle release exists, composer.json uses dev-develop and composer.lock records the exact source commit. The committed lock file is the reproducibility boundary: ordinary installations must run composer install and must not resolve a newer develop commit implicitly.

After an upstream bundle pull request is merged:

1. Review the bundle compatibility and migration notes.
2. Run composer update zhortein/multi-tenant-bundle --with-all-dependencies in the project Docker environment.
3. Review the resolved source commit and the complete lock-file diff.
4. Run the clean installation, container compilation, database, and application smoke checks.
5. Commit the lock file together with any required demo adaptation.

## Stable releases

Once a compatible stable bundle tag is available, replace dev-develop with the narrowest supported stable constraint, refresh composer.lock through the same review process, and document the selected version. Do not remove the lock file: this application must remain installable from an immutable dependency graph.

## Upgrade validation

Every baseline upgrade must verify Composer validation and audit, dependency restoration from composer.lock, Symfony container compilation, Doctrine mappings and schema, migrations, PHPUnit, and HTTP smoke paths. Tenant-sensitive upgrades additionally require negative cross-tenant tests; successful installation alone is not evidence of isolation.
