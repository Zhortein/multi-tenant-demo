# Tenant isolation verification

The demo enforces tenant access at two independent layers:

1. route authorization requires the authenticated account to be assigned to the tenant selected by the URL, unless the account has the explicit platform administrator role;
2. tenant-facing repositories and ownership checks include the authorized tenant explicitly.

The Doctrine tenant filter remains useful defense in depth, but it is not the application authorization boundary. The test environment deliberately disables that filter in `config/packages/test/zhortein_multi_tenant.yaml`. The functional isolation suite must therefore fail if a controller or repository removes its explicit tenant criterion.

Run the complete supported verification in Docker:

```bash
make quality
```

The deterministic fixtures create Tenant A and Tenant B with distinguishable users and products. Tests cover allowed same-tenant list, show, create, update, and delete operations; rejected cross-tenant direct object access; attempted tenant parameter injection; tenant-safe email navigation; and commands that require one explicit tenant.

The demo uses the shared-database strategy without PostgreSQL row-level security. Effective PostgreSQL RLS coverage belongs to the bundle's PostgreSQL integration suite. This demo suite independently proves the consuming application's authorization boundary even when the Doctrine filter is unavailable.
