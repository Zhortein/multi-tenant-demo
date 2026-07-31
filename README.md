# Multi-Tenant Demo Application

[![CI](https://github.com/Zhortein/multi-tenant-demo/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/Zhortein/multi-tenant-demo/actions/workflows/ci.yml)

A comprehensive demonstration of multi-tenancy patterns using the **Zhortein Multi-Tenant Bundle** for Symfony 7.4 LTS.

## 🏢 Overview

This application showcases how to implement multi-tenancy in a Symfony application where multiple organizations (tenants) can use the same application instance while maintaining complete data isolation. Each tenant has their own isolated data space while sharing the same codebase and infrastructure.

## ✨ Features

### Multi-Tenant Architecture
- **Path-based tenant resolution** (`/tenant-slug/...`)
- **Automatic data isolation** using tenant-aware entities
- **Shared database with tenant filtering**
- **Complete tenant context management**

### Demo Functionality
- **Tenant Management**: Create, edit, and manage tenants
- **Product Catalog**: Tenant-specific product management
- **User Management**: Tenant-scoped user accounts
- **Admin Dashboard**: System-wide administration
- **Tenant Dashboards**: Individual tenant analytics

### Technical Features
- **PHP 8.3+** with strict typing
- **Symfony 7.4 LTS** following best practices
- **PostgreSQL 16** with Doctrine ORM
- **Bootstrap 5** responsive UI
- **Docker containerization**
- **PHPStan Level Max** compliance

## 🚀 Quick Start

### Prerequisites
- Docker Engine with Docker Compose
- PostgreSQL 16 is provided by the project stack; PHP and Composer run inside the application container.

See the compatibility and bundle update policy in docs/compatibility.md for the supported dependency baseline and reproducible bundle update procedure.

### Installation

1. **Clone and setup the project:**
```bash
git clone <repository-url> multi-tenant-demo
cd multi-tenant-demo
```

2. **Build and start the Docker environment:**
```bash
make build
make start
```

3. **Restore the locked dependencies:**
```bash
make install
```

`make install` only runs `composer install` against the committed
`composer.lock`; it never creates a new Symfony project or updates dependency
versions. The container also restores the same lock file automatically when
`vendor/` is empty.

4. **Setup the database:**
```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

5. **Create sample data:**
```bash
docker compose exec php php bin/console app:create-sample-data
```

6. **Access the application:**
- Homepage: https://drenard.devlogiciel.com/
- Admin Dashboard: https://drenard.devlogiciel.com/admin

### Local cleanup

`make clean` stops and removes this project's containers without deleting
database or runtime volumes. The explicitly destructive
`make destroy-local-data CONFIRM=destroy` command also removes this project's
volumes and irreversibly deletes the local database. It never prunes unrelated
Docker resources.

## Demo authentication and accounts

Authentication uses a global, unique email address and is deliberately separate
from path-based tenant resolution. Signing in does not select or change a
tenant. A `ROLE_USER` account may access only routes whose `tenantSlug` matches
its assigned tenant; this explicit HTTP authorization runs in addition to the
Doctrine tenant filter. Only fixtures explicitly assigned `ROLE_ADMIN` can use
platform administration or inspect multiple tenant routes.

Run `make fixtures` to create or update the deterministic accounts below in the
isolated test database. The password is the non-secret demonstration value
`demo-password` for every account:

| Account | Tenant | Purpose |
| --- | --- | --- |
| `alice@tenant-a.example.test` | `tenant-a` | Tenant A user |
| `bob@tenant-b.example.test` | `tenant-b` | Tenant B user |
| `admin@example.test` | `platform` | Explicit platform administrator |

The fixture command is idempotent and identifies records by stable slugs,
e-mails, and SKUs. These credentials are for local demonstration and CI only;
they must never be reused for a deployed environment.

Authentication requires globally unique user e-mail addresses. Before applying
migration `Version20260731170000` to an existing demo database, resolve any
duplicate addresses created by older sample data. The unique constraint rejects
ambiguous login identifiers and never chooses or deletes an account implicitly.

## 🏗️ Architecture

### Entity Structure

```
Tenant (TenantInterface)
├── Users (TenantAware)
├── Products (TenantAware)
└── ... other tenant-specific entities
```

### Key Components

#### Entities
- **`Tenant`**: Implements `TenantInterface`, represents organizations
- **`User`**: Tenant-aware user entity with authentication
- **`Product`**: Tenant-aware product catalog entity

#### Controllers
- **`DashboardController`**: Main application entry points
- **`TenantController`**: Admin tenant management
- **`ProductController`**: Tenant-specific product management

#### Multi-Tenant Integration
- **Bundle Configuration**: `config/packages/zhortein_multi_tenant.yaml`
- **Tenant Resolution**: Path-based (`/{tenantSlug}/...`)
- **Data Isolation**: Automatic filtering via `@TenantAware` attribute

## 🎯 Demo Scenarios

### 1. Homepage Experience
Visit the homepage to see all available tenants and choose one to explore.

### 2. Tenant-Specific Access
Each tenant has isolated access:
- **Acme Corp**: `/acme-corp/products`
- **Global Retail**: `/global-retail/products`
- **TechStart**: `/techstart/products`

### 3. Admin Management
System administrators can:
- View all tenants: `/admin/tenants`
- Create new tenants: `/admin/tenants/new`
- Monitor system-wide statistics: `/admin`

### 4. Data Isolation Verification
- Products created in one tenant are invisible to others
- Users belong to specific tenants
- Database queries are automatically filtered

## 🔧 Configuration

### Multi-Tenant Bundle Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    tenant_entity: App\Entity\Tenant
    resolver: 'path'
    database:
        strategy: 'shared_db'
        enable_filter: true
    mailer:
        enabled: true
        add_tenant_id_header: false
        add_tenant_name_header: false
    storage:
        enabled: true
        type: 'local'
        local:
            base_path: '%kernel.project_dir%/var/uploads'
            base_url: '/tenant-files'
```

Tenant storage fails closed without an active context and stores files below `tenants/{slug}/...`. The demo uses the bundle storage interface for every document operation and rejects a document whose tenant differs from the active tenant. Public tenant email headers are disabled; routing and branding remain internal. Global files require a separate explicit application service.

### Database Configuration

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        server_version: '16'
    orm:
        auto_generate_proxy_classes: true
        enable_lazy_ghost_objects: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
```

## 📊 Sample Data

The application includes three pre-configured tenants:

### 1. Acme Corporation (`/acme-corp`)
- **Focus**: Technology solutions
- **Products**: Smart widgets, connectors, security modules
- **Users**: 3 sample users

### 2. Global Retail Inc (`/global-retail`)
- **Focus**: Retail and fashion
- **Products**: Clothing, shoes, accessories
- **Users**: 3 sample users

### 3. TechStart Solutions (`/techstart`)
- **Focus**: Software development tools
- **Products**: Code editors, APIs, monitoring tools
- **Users**: 3 sample users

## 🛠️ Development

### Adding New Tenant-Aware Entities

1. **Create the entity:**
```php
<?php

namespace App\Entity;

use App\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\TenantAware;

#[ORM\Entity]
#[TenantAware(tenantFieldName: 'tenant')]
class YourEntity
{
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;
    
    // ... other properties
}
```

2. **The bundle automatically handles:**
- Tenant context injection
- Query filtering
- Data isolation

### Running tests and validation

The [tenant isolation verification](docs/tenant-isolation.md) explains the independent application authorization boundary and the filter-disabled regression suite.

```bash
make quality
```

This required check creates and migrates the isolated `app_test` PostgreSQL
database, validates Doctrine mappings and schema synchronization, and runs the
complete PHPUnit suite against that database. The same commands run for pull
requests and pushes to `main` and `develop`.

PHPStan and PHP-CS-Fixer are not currently declared by this application and are
therefore not presented as effective local checks.

## 🌐 API Endpoints

### Public Routes
- `GET /` - Homepage with tenant selection
- `GET /admin` - Admin dashboard
- `GET /admin/tenants` - Tenant management

### Tenant-Specific Routes
- `GET /{tenantSlug}` - Tenant dashboard
- `GET /{tenantSlug}/products` - Product listing
- `POST /{tenantSlug}/products` - Create product
- `GET /{tenantSlug}/products/{id}` - Product details

## 🔒 Security Considerations

### Data Isolation
- **Automatic filtering**: All queries are automatically scoped to the current tenant
- **Context validation**: Tenant context is validated on each request
- **Cross-tenant protection**: Users cannot access other tenants' data

### Authentication
- Users are scoped to their tenant
- Admin access is separate from tenant access
- CSRF protection on all forms

## 📈 Performance

### Optimizations
- **Database indexing** on tenant foreign keys
- **Query optimization** with automatic tenant filtering
- **Caching strategies** for tenant resolution
- **Lazy loading** for related entities

### Monitoring
- Tenant-specific analytics in dashboards
- System-wide statistics for administrators
- Performance metrics per tenant

## 🚀 Deployment

### Production Checklist
- [ ] Configure environment variables
- [ ] Set up SSL certificates
- [ ] Configure database connection
- [ ] Run migrations
- [ ] Set up monitoring
- [ ] Configure backup strategy

### Environment Variables
```bash
DATABASE_URL=postgresql://user:pass@localhost:5432/db_name
APP_ENV=prod
APP_SECRET=your-secret-key
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Follow PSR-12 coding standards
4. Add tests for new functionality
5. Submit a pull request

## 📝 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🙏 Acknowledgments

- **Zhortein Multi-Tenant Bundle** - The core multi-tenancy functionality
- **Symfony Framework** - The robust foundation
- **Bootstrap** - The responsive UI framework
- **Docker** - Containerization platform

## 📞 Support

For questions about this demo application:
- Create an issue in the repository
- Check the Zhortein Multi-Tenant Bundle documentation
- Review Symfony best practices documentation

---

**Built with ❤️ using Symfony 7.4 LTS and the Zhortein Multi-Tenant Bundle**
