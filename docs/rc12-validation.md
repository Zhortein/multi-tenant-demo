# RC12 consumer validation record — 2026-09-13

## Scope and starting state

Project: `Zhortein/multi-tenant-demo` only. Branch:
`feature/rc12-object-storage-audit-demo`, created from `origin/develop` at
`6ffa3b15c551bffede0d3a3a504a99c87e8c9551` after an authorized fetch/prune.
The initial clean checkout was the retained RC11 source branch at
`86b55fb15c0ff446503a8a3ebdf64faa30d9c5f6`. There was no staged/untracked work,
unfinished Git operation or open competing PR. The remote was verified as
`git@github.com:Zhortein/multi-tenant-demo.git`.

[PR #30](https://github.com/Zhortein/multi-tenant-demo/pull/30), its commit and
self-review history were checked. Its merge is the starting `develop` SHA, whose
[post-merge CI succeeded](https://github.com/Zhortein/multi-tenant-demo/actions/runs/34020414062).
`main` remains at `d07432aca304a5d0769707d6af9de5d009cdd2ec`.

This is a fixture-level extension of the existing S3/MinIO demonstration. There
is no suitable generic storage page, so no UI is added. No business entity,
SQL migration, ledger, quota, notification, document lifecycle, index,
reconciliation/repair command, orphan deletion or administration interface is
introduced. The bundle repository and other applications are outside this work.

## Public package provenance

- Exact package: `zhortein/multi-tenant-bundle:v1.0.0-rc.12`, Packagist only.
- [Public release](https://github.com/Zhortein/multi-tenant-bundle/releases/tag/v1.0.0-rc.12):
  non-draft and prerelease, published 2026-09-13.
- Annotated tag object: `7314dc7768d7e12f3bbfb7501692a6998267e135`.
- Commit, Packagist source, Packagist dist and both lock references:
  `e97425d098a0ae5b8578ac47bfa205ec95be2255`.
- MIT license; the public Packagist advisory endpoint reported no bundle advisory
  on the validation date. `composer audit --locked` also found no advisory in
  the whole installed graph.
- All 518 installed distributed files match public Git blob hashes at RC12.
- No alternate Composer repository, fork, development branch or local package
  archive was used. Composer ran in the project image with a fresh Composer home.

Exactly one package changed; there are no additions/removals or other package
metadata changes. Symfony, Doctrine, Flysystem, the S3 adapter and SDK are intact.
The update command was:

```console
composer update zhortein/multi-tenant-bundle --minimal-changes --no-scripts --no-interaction --prefer-dist --no-progress
```

## Executable evidence

`ObjectStorageAuditTest` adds seven tests using the public generic contracts:

- Explicit audit activation in the `test` profile, with an independent generated
  runtime key. Default development/production keeps audit disabled, preserving
  compilation without a storage account or audit key. The capable S3 backends
  and each generation's provider/capabilities are declared once in shared config.
- Paginated public inventory, including historical `shared_v2`, with no backend
  constructions. A sees the dedicated location; B does not; A/B/A remains stable.
- A new identity-bearing write and `verified` observation with identity matching.
  Identity expresses application correlation, never content attestation or
  protection against storage-administrator replay/removal.
- Unchanged RC11 writes, restored v1 JSON and `identity_absent`; no backfill or
  invented provenance. A separate actual RC11-written MinIO witness was read
  under RC12 and observed as `identity_absent`, then only that witness was removed.
- Bounded object pages, continuation, malformed/changed-limit/cross-tenant
  cursors, foreign scope and identity rejection before I/O, A/B/A, reset and
  shutdown/reboot. Resumption requires restoring the same valid tenant/scope.
- A trusted test-only decorator writes foreign and malformed identity envelopes
  onto newly allocated objects in real MinIO. `foreign` exposes no reference,
  namespace, metadata or identity; the next page remains available.
- A broken physical boundary injected after a real LIST rejects the whole page
  before any HEAD. A real unavailable S3 bucket independently proves a global
  failure without fallback. Reset during LIST also rejects the entire page.
  These sanitized failures have no previous vendor exception.
- Foreign response redaction, absence of physical references/envelopes/key in
  application logs, and absence of the runtime key in compiled container files.

No audit API call repairs or deletes objects. Teardown deletes only references
allocated by these fixtures. No database transaction is held across audit I/O,
no global inventory is persisted, and no orphan/business classification is made.

## Local gates

Separate disposable Compose projects used the documented base/development/
storage/test overlays, PostgreSQL 16 and 18, and real pinned MinIO over verified
HTTPS. Existing data volumes were never mounted. PHP was 8.5.9. All project
commands ran in Docker; no host tooling or dependencies were installed.

| Gate | Result |
| --- | --- |
| Initial RC11 real storage | 9 tests / 577 assertions |
| RC12 targeted audit | 7 tests / 379 assertions; no skip |
| Full `make quality`, PostgreSQL 16 | 51 tests / 1,253 assertions; all gates pass |
| Full `make quality`, PostgreSQL 18 | 51 tests / 1,253 assertions; all gates pass |
| `make storage-test`, both versions | 16 tests / 956 assertions; skips explicitly fail |
| Existing HTTP/authorization/upload/download/Messenger/Scheduler | Included in complete PHPUnit and storage suites |
| HTTP / HTTPS / Mercure, both versions | 308 / 200 / 200 |
| Doctrine migrations, deterministic fixtures, mapping/schema | Pass on both versions; `doctrine:schema:update --dump-sql` reports nothing to update |
| `make phpstan` | Maximum level; unchanged baseline, no new/unmatched diagnostic |
| `make cs-check` | Pass in the pinned official PHP 8.3 fixer image |
| `make composer-check` | Strict validation accepts only the exact-RC warning; audit clean |
| YAML / Twig / XLIFF / JSON / PHP / container lints | Pass; test-profile container also linted explicitly |
| Composer post-install scripts / assets / importmap | Pass; dependency and recipe locks unchanged afterward |
| ShellCheck / actionlint / Hadolint | Pass in Docker |
| Production build from lock / offline `cache:clear --env=prod` | Pass; no network or audit key needed for compilation |
| `git diff --check` / targeted secret search | Pass; no candidate in changed files |

The checks used `make test-database fixtures schema-validate test phpstan cs-check
composer-check`, `make storage-test`, Symfony's `lint:*` commands, Composer's
`post-install-cmd`, `doctrine:schema:update --dump-sql` and the repository's HTTP
smoke commands. The unchanged CI matrix repeats these gates on PostgreSQL 16/18;
`make storage-test` now includes the new audit test and rejects skips.

Early local iterations corrected two test expectations for public error reasons,
import ordering and the placement of audit activation. The additional test-profile
container lint uses a synthetic `TEST_TOKEN=lint`: its inherited Doctrine suffix
otherwise resolves an absent token to null during strict environment resolution. A globally enabled codec
required an audit key during Console initialization and broke keyless production
compilation; keeping activation in the existing test demonstration resolves that
regression. No gate was weakened to obtain the final results.

Inherited limits remain: 194 PHPStan diagnostics in the unchanged pre-RC11
baseline; eight distinct production deprecation messages from the existing
Doctrine/lazy-object/validation configuration; the pre-existing development lock
requires PHP 8.4 through Doctrine Instantiator although Composer declares PHP 8.3;
the application's existing license metadata discrepancy; the separate historical
local-file/incomplete S3 API. None is expanded into unrelated remediation.

## Exact file inventory

- `.php-cs-fixer.dist.php`
- `Makefile`
- `README.md`
- `compose.storage.yaml`
- `compose.yaml`
- `composer.json`
- `composer.lock`
- `config/packages/object_storage.yaml`
- `config/packages/test/object_storage.yaml`
- `docs/bundle-integrations.md`
- `docs/compatibility.md`
- `docs/object-storage.md`
- `docs/rc12-validation.md`
- `tests/Fixtures/ObjectStorage/StorageIoProbe.php`
- `tests/Integration/ObjectStorageAuditTest.php`
- `tools/storage-audit-key.php`
- `tools/validate-composer.sh`

## Exact Composer diff

```diff
diff --git a/composer.json b/composer.json
index 1360759..a2f3c69 100644
--- a/composer.json
+++ b/composer.json
@@ -53,7 +53,7 @@
         "symfony/yaml": "7.4.*",
         "twig/extra-bundle": "^2.12|^3.0",
         "twig/twig": "^2.12|^3.0",
-        "zhortein/multi-tenant-bundle": "1.0.0-rc.11"
+        "zhortein/multi-tenant-bundle": "1.0.0-rc.12"
     },
     "config": {
         "allow-plugins": {
diff --git a/composer.lock b/composer.lock
index c23847a..b89607d 100644
--- a/composer.lock
+++ b/composer.lock
@@ -4,7 +4,7 @@
         "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
         "This file is @generated automatically"
     ],
-    "content-hash": "9dc317fb34fe003ca9f012de9b6a28d7",
+    "content-hash": "23244c6a5f93fc7914521c01af2a6651",
     "packages": [
         {
             "name": "aws/aws-crt-php",
@@ -9024,16 +9024,16 @@
         },
         {
             "name": "zhortein/multi-tenant-bundle",
-            "version": "v1.0.0-rc.11",
+            "version": "v1.0.0-rc.12",
             "source": {
                 "type": "git",
                 "url": "https://github.com/Zhortein/multi-tenant-bundle.git",
-                "reference": "fe769e9e2ea6fc5db6bd2f8bd23f2e52ba04ee94"
+                "reference": "e97425d098a0ae5b8578ac47bfa205ec95be2255"
             },
             "dist": {
                 "type": "zip",
-                "url": "https://api.github.com/repos/Zhortein/multi-tenant-bundle/zipball/fe769e9e2ea6fc5db6bd2f8bd23f2e52ba04ee94",
-                "reference": "fe769e9e2ea6fc5db6bd2f8bd23f2e52ba04ee94",
+                "url": "https://api.github.com/repos/Zhortein/multi-tenant-bundle/zipball/e97425d098a0ae5b8578ac47bfa205ec95be2255",
+                "reference": "e97425d098a0ae5b8578ac47bfa205ec95be2255",
                 "shasum": ""
             },
             "require": {
@@ -9146,7 +9146,7 @@
                 "issues": "https://github.com/zhortein/multi-tenant-bundle/issues",
                 "source": "https://github.com/zhortein/multi-tenant-bundle"
             },
-            "time": "2026-09-06T06:06:31+00:00"
+            "time": "2026-09-13T09:01:02+00:00"
         }
     ],
     "packages-dev": [
```

## Delivery and resource boundary

The associated pull request records the immutable implementation SHA, hostile
self-review, branch/PR CI runs, normal merge and exact post-merge CI. Merge is
conditional on every required gate succeeding with no unresolved blocker. Local
`develop` is synchronized only by fast-forward; the source branch is retained.
There is no main promotion, bundle change, tag, release or deployment.

Temporary resources are limited to the two isolated PostgreSQL/MinIO Compose
projects, their volumes, two task-specific image tags, generated local TLS/audit
material and validation files. Only these may be removed after delivery. The
three original demo volumes, existing branches, ignored environment files,
unrelated running services and pre-existing images are preserved.
