# Bundle Public Integration Validation

The demo exercises the bundle as an external Symfony application. Its
integration suite uses only public services and configuration: it does not copy
or mock bundle internals.

## Reproducible validation

Start the Docker Compose environment, restore `composer.lock`, and run:

```shell
docker compose config --quiet
docker compose exec -T php composer validate --strict
docker compose exec -T php composer audit --locked
make quality
```

The test environment replaces the production Messenger transports with
in-memory transports and the Mailer transport with `null://null`. Local storage
uses the configured directory under `var/`; each test removes the files that it
creates. PostgreSQL remains the real Doctrine database and the cache is the real
`cache.app` service explicitly decorated by bundle RC4. The application also
defines a separate undecorated `cache.global` pool for explicitly global data.

`tests/Integration/BundlePublicIntegrationsTest.php` proves the following
consumer contracts with the deterministic `tenant-a` and `tenant-b` fixtures:

- cache keys with the same logical name use distinct tenant namespaces and
  missing context fails closed; Symfony `CacheInterface` and
  `NamespacedPoolInterface` remain available, consumer sub-namespaces stay
  inside tenant boundaries, and explicitly global data uses `cache.global`;
- local files with the same logical path are stored below distinct
  `tenants/{tenant}/` roots, cross-tenant deletion is isolated, traversal is
  rejected, and missing context fails closed;
- the application explicitly injects the bundle's `TenantAwareMailer`, applies
  each tenant's sender independently, publishes no tenant metadata headers by
  default, and fails without context;
- Messenger adds exactly one `TenantStamp`, restores context for received
  messages, clears it afterward, and cannot process a Tenant A notification
  when the received stamp identifies Tenant B.

The notification payload deliberately carries only the notification ID. Tenant
identity comes exclusively from `TenantStamp`, and the handler still queries by
both notification ID and the restored tenant. This explicit repository
criterion is the application authorization boundary; the Doctrine filter is
defense in depth.

## Scope and limitations

The demo validates the supported shared-database consumer path on Symfony 7.4
LTS and PHP 8.3. The bundle's own matrix separately validates Symfony 7.4,
8.0, and 8.1, PHP 8.3 through 8.5, the multi-database strategy, and effective
PostgreSQL row-level security. The demo does not claim that an in-memory
transport proves an external queue broker; it proves serialization-independent
envelope metadata and the same middleware/handler path used by a worker.
