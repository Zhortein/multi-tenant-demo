# Bundle Public Integration Validation

The demo exercises the bundle as an external Symfony application. Its
integration suite uses only public services and configuration: it does not copy
or mock bundle internals.

## Reproducible validation

Start the Docker Compose environment, restore `composer.lock`, and run:

```shell
docker compose config --quiet
docker compose exec -T php composer validate
docker compose exec -T php composer audit --locked
make quality
```

The exact RC pin intentionally produces Composer's general constraint warning;
strict mode reports it as a nonzero exit even when the schema and lock are
valid. Keep the reviewed exact version instead of relaxing it to silence that
warning. Dependency audit remains required.

The test environment replaces the ordinary async and notification Messenger
transports with in-memory transports and the Mailer transport with `null://null`.
The Scheduler destination remains a persistent Doctrine transport. Local storage
uses the configured directory under `var/`; each test removes the files that it
creates. PostgreSQL remains the real Doctrine database and the cache is the real
`cache.app` service explicitly decorated by bundle RC10. The application also
defines a separate undecorated `cache.global` pool for explicitly global data.

RC10 treats every main HTTP request, Messenger delivery, Scheduler execution,
and Console command as a tenant-state boundary. The demo uses the early path
resolver because its tenant identity is infrastructure-derived. A main request
starts from `NONE`, and null resolution, controller failure, terminal cleanup,
and the real Symfony `services_resetter` all leave the shared context and its
derived Doctrine/cache state at `NONE`. Applications whose resolver depends on
the authenticated user should disable automatic resolution and invoke the
public request context loader after authentication instead.

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
- Messenger uses the explicit `symfony_routing` strategy. YAML routes send
  notifications to the dedicated `notifications` transport, `#[AsMessage]`
  selects `async`, configured routing takes precedence over the attribute,
  and an explicit `TransportNamesStamp` remains authoritative.
- A message with a handler but no native route is handled synchronously.
  Applications that require asynchronous delivery must therefore route every
  such message exhaustively; RC10 deliberately provides no default-transport
  fallback in `symfony_routing` mode.
- `tests/Integration/SchedulerRedispatchTest.php` drives a real
  `SchedulerTransport`, `MessageGenerator`, controlled `MockClock`, Messenger
  bus, and two real Workers. Its global health-check message is wrapped in
  `RedispatchMessage` for `scheduler_persistent`: the Scheduler Worker leaves
  the application handler untouched and writes exactly one serialized Doctrine
  message, then the application Worker handles it, empties the queue, and leaves
  tenant context empty. The destination remains Symfony-owned; the bundle does
  not add a replacement `TransportNamesStamp` in `symfony_routing` mode.

## RC10 middleware composition proof

The production bus explicitly enables Symfony `validation`. The test bus adds
`MiddlewareExecutionProbe` after it. RC9's configuration prepend was replaced by
such application lists because Symfony uses `performNoDeepMerging()`. RC10
composes the final bus constructor iterable after Symfony's `MessengerPass`, so
both application middleware and the bundle's tenant guards remain effective.
Consumers do not need to add bundle middleware manually or change public APIs.
Messenger remains an installed runtime dependency; integration can independently
be disabled through the bundle's `messenger.enabled` setting.

The Scheduler test records real Validator callbacks and middleware entry/exit
for the received `RedispatchMessage`, the nested outgoing health-check payload,
and its later persistent delivery. Each dispatch validates once, application
middleware runs once in order, and the Scheduler Worker never invokes the
business handler. Success and a controlled application-handler exception both
leave the context empty. Notification tests additionally prove that validation
sees tenant A, then B on a rejected cross-tenant delivery, then A on a valid
delivery. An unclassified message is rejected before application middleware or
transport despite `validation` being configured.

`tests/Integration/PersistentTenantLifecycleTest.php` reuses one kernel and one
Console application to prove `A -> NONE`, `A -> exception -> NONE`,
`A -> B -> NONE`, initialized tenant-aware cache reset, idempotent real
`services_resetter`, and command-to-command isolation.

The notification payload deliberately carries only the notification ID. Tenant
identity comes exclusively from `TenantStamp`, and the handler still queries by
both notification ID and the restored tenant. This explicit repository
criterion is the application authorization boundary; the Doctrine filter is
defense in depth.

## Scope and limitations

The demo validates the supported shared-database consumer path on Symfony 7.4
LTS and PHP 8.3. The bundle's own matrix separately validates Symfony 7.4,
8.0, and 8.1, PHP 8.3 through 8.5, the multi-database strategy, and effective
PostgreSQL row-level security. The demo uses in-memory transports for native
sender-selection tests and a real Doctrine transport for Scheduler redispatch.
The latter proves the serialization boundary and separation between the
Scheduler and application Workers without claiming support for a separate
external broker.
