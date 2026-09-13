# RC12 object storage and audit consumer proof

This demo consumes exactly `zhortein/multi-tenant-bundle:1.0.0-rc.12` from
Packagist. The [public prerelease](https://github.com/Zhortein/multi-tenant-bundle/releases/tag/v1.0.0-rc.12)
is non-draft; annotated tag object `7314dc7768d7e12f3bbfb7501692a6998267e135`
resolves to `e97425d098a0ae5b8578ac47bfa205ec95be2255`. Both Packagist source
and dist references and both lock references match that commit. The bundle is
MIT; Packagist reported no bundle advisory on 2026-09-13.
Only the bundle changes from RC11 to RC12: Symfony, Doctrine, Flysystem and S3
packages keep their exact locked versions. No alternative repository or local
package archive is used. See the [validation report](rc12-validation.md).

The demo owns configuration, synthetic infrastructure and application-level
integration probes. The bundle owns the public technical isolation contract.
Read its [migration guide](https://github.com/Zhortein/multi-tenant-bundle/blob/v1.0.0-rc.12/docs/migration-rc11-to-rc12.md),
[generic API contract](https://github.com/Zhortein/multi-tenant-bundle/blob/v1.0.0-rc.12/docs/object-storage.md)
and [optional S3-compatible bridge](https://github.com/Zhortein/multi-tenant-bundle/blob/v1.0.0-rc.12/docs/object-storage-flysystem.md)
for the normative API details.

## Dependency boundary

The consumer explicitly requires:

```console
composer require 'zhortein/multi-tenant-bundle:1.0.0-rc.12' \
  'league/flysystem:^3.30.2' 'league/flysystem-aws-s3-v3:^3.30.1' \
  'aws/aws-sdk-php:^3.371.5'
```

Run Composer in the project container. Ordinary setup uses `composer install`.
Flysystem, the S3 adapter and the SDK are optional bundle suggestions; none is
a mandatory transitive dependency of the bundle. The SDK implements the S3
protocol against an explicitly configured endpoint. No Amazon account or service
is used. The lock selects Flysystem 3.36.0, S3 adapter 3.35.3 and SDK 3.394.9.
No custom Composer repository is configured.

Flysystem and its adapter are MIT; the SDK and AWS CRT are Apache-2.0; the
additional HTTP libraries and JMESPath are MIT. MinIO and mc are AGPL-3.0
infrastructure executables in separate containers. The bundle remains MIT.
The pre-existing discrepancy between this application's `proprietary` Composer
metadata and its MIT LICENSE is recorded in the earlier repository audit.

## Provider, generation and reference

`config/packages/object_storage.yaml` explicitly enables the generic API.

| Logical provider | Current location | Local fixture allocation |
| --- | --- | --- |
| `shared` (default) | `shared_v1` | Tenant B |
| `dedicated` | `dedicated_v1` | Tenant A through a server-owned override |

`shared_v2` is registered for the historical-reference proof. Each location uses
one `S3LocationConfiguration` to construct its client, Flysystem adapter, signer,
listing capability and physical binding. The same backend implements the binding
contract. No independently maintained fingerprint is used. Renewable credentials
and the CA file are excluded from that identity.

Only allocation consults the provider. Reads, metadata, existence, streams,
listing, copy/move, deletion and signing use the location and binding in the
stored reference. Reusing a location ID with changed physical addressing fails
before I/O. Changing credentials does not change the binding.

Namespaces are server-owned 64-character opaque values keyed by immutable tenant
IDs. They are independent of slugs and account details. Local defaults are
explicit synthetic fixtures for IDs 1 and 2; provision separate random values
once for any real consumer. Do not recycle IDs or namespaces. A request, header,
route parameter, cookie or browser payload cannot select a provider, endpoint or
bucket through this configuration.

## Local development

```console
make build
make storage-start
make test-database fixtures
make storage-status
make storage-test
make quality
make storage-stop
```

`storage-start` explicitly combines base, development and `compose.storage.yaml`.
It generates an independent random application audit key and a seven-day local TLS certificate in ignored
`var/object-storage/tls`, starts MinIO with verified HTTPS readiness, and
provisions private buckets and synthetic accounts. It preserves existing TLS
material, audit keys and named data volumes. The audit key is stored separately at
`var/object-storage/audit/current.key` with mode 0600, mounted only into PHP,
and never mounted into MinIO. `OBJECT_AUDIT_KEY_FILE` points to this runtime file.
Keep it for as long as these objects need identity verification. Inspect expired/incomplete generated material
before explicitly replacing it; a readiness failure is never ignored.

PHP uses the internal endpoint. The signing endpoint is a second DNS alias of
the same TLS server, directly reachable by the test's download client inside
the PHP container. Both certificate names are verified against the generated CA.
Signed URLs are downloaded unchanged. There are no published MinIO ports,
console access, anonymous objects or permanent URLs. This proof does not expose
a browser-facing download UI.

| Local variable | Purpose |
| --- | --- |
| `MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD` | Synthetic local provisioning account |
| `OBJECT_ACCESS_KEY`, `OBJECT_SECRET_KEY` | Local consumer account |
| `OBJECT_ROTATED_ACCESS_KEY`, `OBJECT_ROTATED_SECRET_KEY` | Second local account for rotation proof |
| `OBJECT_BUCKET_SHARED_V1`, `OBJECT_BUCKET_SHARED_V2`, `OBJECT_BUCKET_DEDICATED` | Three private technical containers |
| `OBJECT_DEDICATED_TENANT_ID`, `OBJECT_TENANT_B_ID` | Actual immutable IDs of the two local fixtures |
| `OBJECT_NAMESPACE_A`, `OBJECT_NAMESPACE_B` | Stable opaque namespace values |

Defaults are synthetic demo values, never production credentials. On a database
that already has tenants, inspect the fixture IDs and supply the matching values
before `storage-start`; the demo does not infer identity from a slug. The local
overlay injects the endpoint, signing endpoint, CA path, namespace map and
allocation override. PHP storage code consumes those injected services only.

The following official manifest-list digests were rechecked on 2026-09-06 and
match the bundle's validated infrastructure:

| Image | Tag | Digest |
| --- | --- | --- |
| `quay.io/minio/minio` | `RELEASE.2025-09-07T16-13-09Z` | `sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e` |
| `quay.io/minio/mc` | `RELEASE.2025-08-13T08-35-41Z` | `sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727` |

See the official [MinIO release](https://github.com/minio/minio/releases/tag/RELEASE.2025-09-07T16-13-09Z)
and [mc release](https://github.com/minio/mc/releases/tag/RELEASE.2025-08-13T08-35-41Z).

## External provider configuration

Use `compose.yaml` with `compose.override.yaml` or `compose.prod.yaml`, excluding
`compose.storage.yaml`. Neither the base application nor production configuration
has a MinIO dependency. Inject these variables through the deployment environment:

- `OBJECT_ENDPOINT` and `OBJECT_SIGNING_ENDPOINT`: final verified HTTPS origins
  addressing the same storage. Never rewrite a signed URL.
- The three `OBJECT_BUCKET_*` values, `OBJECT_REGION`, `OBJECT_ACCESS_KEY` and
  `OBJECT_SECRET_KEY`.
- Optional `OBJECT_CA_BUNDLE`: a mounted CA path; otherwise the system trust store.
- `OBJECT_AUDIT_KEY_FILE`: mounted application-only file containing exactly 64
  lowercase hex characters from 32 random bytes, without a trailing newline.
  It must be independent of storage credentials. No usable default key exists;
  an unconfigured key fails at runtime when audit is explicitly enabled.
  Keys are resolved at runtime, never embedded
  in container definitions or compiled cache. Keep old keys when rotating the
  configured codec keyring; the local demo starts with one key named `current`.
- `OBJECT_NAMESPACES`: a JSON map from immutable tenant IDs to unique opaque values.
- `OBJECT_PROVIDER_OVERRIDES`: a JSON map, for example `{"1":"dedicated"}`.
- `OBJECT_DEDICATED_TENANT_ID`: the dedicated location's explicit allowlist entry.

With no storage environment configured, container compilation succeeds and
unprovisioned tenant storage fails closed. The production image is compiled in
a network-disabled container as a durable CI check. No external storage is
provisioned or contacted by that check.

## Explicit RC12 audit adoption

`config/packages/test/object_storage.yaml` explicitly sets `audit.enabled: true`
and registers `ObjectStorageAuditCodec`. The shared configuration declares each
location's provider and potential audit capabilities, and calls the S3 factory
with `audit: true` and the declared class `AuditableFlysystemBackend`. Audit is
disabled by default in the bundle itself.
There is no database migration or automatic rewrite of existing objects.
This activation belongs to the `test` profile, alongside the existing executable
fixtures. Default development/production storage keeps audit disabled and preserves
ordinary RC11 behavior without requiring an audit key. To adopt audit outside the
proof, explicitly copy the audit codec and activation into the desired profile
and provision its application-only key before starting commands or workers.
Do not include the test Doctrine/Messenger configuration in production.

The new executable example is `tests/Integration/ObjectStorageAuditTest.php`.
There is no existing generic object-storage page, so the proof remains tests and
documentation. The older local-file UI, upload/download and Messenger stay covered.

After the trusted execution boundary has authorized and selected a tenant:

```php
// Inject TenantObjectStorageInterface and TenantObjectStorageAuditInterface.
$locations = $audit->inventoryLocations(10);
// Public locationId is also the immutable generation ID. Descriptors include
// provider, active, auditListing and identityObservation; no physical target.
$reference = $storage->allocate();
$identity = LogicalObjectIdentity::forReference($reference, bin2hex(random_bytes(32)));
$audit->writeWithIdentity($reference, 'synthetic payload', $identity);
$observation = $audit->observe($reference);
// verified + matchesReference() correlates the application claim with placement.
$scope = $audit->auditScope($reference->locationId);
$page = $audit->auditList($scope, 1);
// Pass nextCursor unchanged with the same tenant, scope and page limit.
```

Inventory includes allowed active and historical generations and constructs no
backend, binding or network client. The configured test-profile factory construction counter
proves this for A/B/A and paginated inventory. Selecting a scope may construct
only the selected backend; it allocates no object. Identity writes and observation
use real MinIO. Applications own durable persistence of the expected reference
and logical identity; these bounded fixtures do not add an application store.

A logical identity is an **application correlation claim**. It does not attest
content, business ownership or prior existence. It does not protect against a
storage administrator who copies/replays a valid envelope with other content or
removes it. `verified` authenticates the claim under an application key; it does
not turn the object into proof of historical provenance.

An unchanged RC11 write and restored v1 reference remain readable, with
`identity_absent` and no identity. An actual object written using the public RC11
package was also read and observed after the local upgrade. No provenance is
invented from dates, sizes, content or technical addresses. Ordinary writes remain
valid and do not backfill identity metadata.

The fixture uses a public backend decorator to place a foreign authenticated
claim and an invalid envelope on objects created only by the test, in real MinIO.
A `foreign` observation exposes only its state and a random observation ID:
reference, identity and metadata are null. `identity_invalid` exposes no envelope.
One anomalous entry does not prevent a subsequent page. No raw reference, bucket,
physical key, endpoint, credential, signed URL or vendor diagnostic is rendered.

A malformed or cross-tenant cursor, foreign scope or broken global page fails
closed. Tests inject a foreign physical entry after an actual MinIO LIST and
verify rejection before any HEAD; a real unavailable S3 bucket separately proves
a global error with no fallback. A reset during LIST invalidates the entire page
and returns a sanitized `backend_failure`, with no chained vendor exception.
An error means incomplete observation, never absence or an orphan verdict.
After reset, a cursor is reusable only after restoring the same authorized scope.
A kernel shutdown/reboot also clears context and preserves historical readability.

Audit adds no repair, deletion, reconciliation command, ledger, quota, notification,
business lifecycle, migration or administrative interface. Test teardown removes
only test-allocated objects. It never scans for or deletes orphans. Pagination is
bounded and is not a distributed snapshot or proof of global completeness. See the
[public audit contract](https://github.com/Zhortein/multi-tenant-bundle/blob/v1.0.0-rc.12/docs/object-storage-audit.md).

## Persistence and authorization fixtures

`StoredObjectReference` is technical data that the consumer must persist. Its
five public fields are format version, location ID, physical binding, tenant
namespace and generated key. It contains no endpoint, credential, original name
or document permission.

`ReferencePersistenceProbe` writes the reference JSON to a PostgreSQL temporary
table and restores it through a fresh repository instance. Every lookup includes
the active immutable tenant ID and revalidates the namespace. Missing context
and foreign references fail closed. PostgreSQL removes this connection-owned
table automatically. There is no new application entity, production table or
migration; migration rollback is consequently unnecessary for this addition.
Both PostgreSQL 16 and 18 execute the persistence proof.

The historical test writes on `shared_v1`, persists and restores the reference,
constructs a new allocation policy pointing `shared` at `shared_v2`, and proves
that reading the old reference still calls only `shared_v1`. Keep old locations
registered while references exist. There is no automatic data migration.

`DownloadAuthorizationProbe` first checks an active authenticated fixture user's
active membership in the current tenant, then loads a tenant-scoped persisted
reference, and only then signs it. This is an explicit demo authorization rule,
not a final document permission system. Defaults are 10 seconds, with a maximum
of 60 seconds. Tests deny another tenant's user before any adapter call, download
the original signed HTTPS URL, reject anonymous access, and observe server-side
expiration. A capability remains valid until expiry independently of later
context resets; expiry is not revocation or durable authorization.

## Executable proofs and boundaries

`ObjectStorageTest` proves configured A/B allocation, independent namespaces in
one physical location even with an identical technical suffix, correct reads,
pre-I/O denials, streams, metadata, bounded paging, copy/move, cross-location
rejection, repeated deletion, missing context, unknown selectors and locations,
physical mismatch, real credential rotation, historical references, and private
authorized signed downloads. Missing/inaccessible buckets produce a sanitized
failure instead of absence or provider fallback.

`ObjectStorageMessengerTest` persists real messages to a dedicated Doctrine
transport and runs one Worker through A, B, a foreign-reference failure, an A
handler exception and a global message. Restored references are new validated
instances; only three permitted reads reach the adapter. Success, failure and
global handling leave no residual tenant. The existing Scheduler, native routing,
cache and lifecycle suites remain part of the complete gate.

Tests log no keys, signed URLs or vendor diagnostics. Adapter probes count calls
without retaining addresses. Assertions on physical objects use booleans to
avoid dumping sensitive object state on failure. Tests delete only references
they allocated; CI tears down only its own disposable Compose resources.
SQL parameter logging and profiling are disabled in the test connection because
the persisted JSON and Messenger payloads contain complete technical references.

This is not a document-management system: no quotas, billing, antivirus,
quarantine, trash, business retention or final permissions are introduced.
The existing `Document` and local file service belong to the historical RC10 API
and do not become the persistence model for this generic proof. The historical
S3 adapter remains incomplete. S3 moves are non-atomic, and ambiguous write or
move outcomes require consumer recovery decisions; they never trigger an
automatic provider fallback or historical migration.

## Quality and database matrix

The CI matrix runs the same real MinIO tests, complete PHPUnit suite, Doctrine
migrations/fixtures/schema, asset scripts and lints with PostgreSQL 16 and 18.
Both cells must pass the existing required `Tests` aggregate. MinIO tests contain
no skip path. PostgreSQL 18 is a tested upper boundary, not a production minimum.
`compose.test.yaml` provides a version-independent disposable data directory.

For a separate local matrix run, set a fresh `COMPOSE_PROJECT_NAME`,
`POSTGRES_VERSION=16` (or `18`),
`COMPOSE_FILE=compose.yaml:compose.override.yaml:compose.storage.yaml:compose.test.yaml`,
and `TEST_DATABASE_BASE_URL` with the matching `serverVersion`. Build, generate
TLS with `make storage-certificates`, start with `docker compose up --wait`,
provision with `docker compose run --rm storage-provision`, then run `make quality`.
Use free application ports when another stack is running. Remove volumes only
for a project you created as disposable test infrastructure.

PHPStan now runs at maximum level across application, tests and tools. Its
versioned baseline contains exactly 194 diagnostics independently reproduced on
the unchanged base `91a870bd0a4775ada4bdb0017a3e04b9aa66f503`, with RC10 installed
from its lock. None belongs to the new object-storage code. New or unmatched
diagnostics fail the gate. PHP-CS-Fixer checks the RC11/RC12 storage PHP surface; legacy
formatting is not rewritten. This does not claim the inherited application has
zero static-analysis debt.

The fixer runs in its official `3.95.24-php8.3` image, pinned to
`sha256:7033fc432deb3dc29531da39a1fbd09c10bb0e7d61b33ce354b7a25f96486087`,
with a read-only checkout and networking disabled. This avoids its PHP-version
warning and checks formatting on the declared minimum language version. The
existing development lock already required PHP 8.4 through Doctrine Instantiator
2.1.0 before RC11; application execution is validated here on PHP 8.5.9.
Production cache compilation also emits eight pre-existing Doctrine/lazy-object
deprecations. The same cold/warm compilation and container lint on the unchanged
RC10 checkout reproduce all eight; RC11 adds no new deprecation to that set.

`make composer-check` accepts only the existing exact-RC constraint warning,
matches it exactly under strict Composer validation, and runs `composer audit`.
Any other Composer warning fails. Flex adds only the official PHP-CS-Fixer recipe;
contributed recipes remain disabled. The historical bundle recipe metadata in
`symfony.lock` is not a dependency selector; only `composer.lock` selects RC12.
