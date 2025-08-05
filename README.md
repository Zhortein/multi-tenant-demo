# Multi-Tenant Demo Application

A comprehensive demonstration of multi-tenancy patterns using the **Zhortein Multi-Tenant Bundle** for Symfony 7+.

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
- **Symfony 7.0+** following best practices
- **PostgreSQL 16** with Doctrine ORM
- **Bootstrap 5** responsive UI
- **Docker containerization**
- **PHPStan Level Max** compliance

## 🚀 Quick Start

### Prerequisites
- Docker & Docker Compose
- PHP 8.3+ (for local development)
- PostgreSQL 16 (handled by Docker)

### Installation

1. **Clone and setup the project:**
```bash
git clone <repository-url> multi-tenant-demo
cd multi-tenant-demo
```

2. **Start the Docker environment:**
```bash
docker compose up -d
```

3. **Install dependencies:**
```bash
docker compose exec php composer install
```

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
    resolution:
        strategy: path
        parameter_name: tenantSlug
    context:
        auto_set: true
```

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

### Running Tests

```bash
docker compose exec php php bin/phpunit
```

### Code Quality

```bash
# PHPStan analysis
docker compose exec php vendor/bin/phpstan analyse

# PHP CS Fixer
docker compose exec php vendor/bin/php-cs-fixer fix
```

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

**Built with ❤️ using Symfony 7+ and the Zhortein Multi-Tenant Bundle**
