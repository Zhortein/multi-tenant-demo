# Tenant-Aware Features Documentation

This document describes the tenant-aware storage, mailer, and messenger features implemented in the multi-tenant demo application.

## Overview

The application now includes comprehensive tenant-aware functionality that demonstrates:

- **Tenant-Isolated Storage**: Files are stored in tenant-specific directories
- **Tenant-Aware Notifications**: In-app and email notifications with tenant context
- **Tenant-Aware Messaging**: Async processing via Symfony Messenger with tenant isolation
- **Tenant-Specific Email**: Email notifications with tenant branding and isolation

## Features

### 1. Tenant-Isolated File Storage

#### Service: `TenantStorageService`

**Key Features:**
- Files are stored in tenant-specific directories (`/var/uploads/tenants/{slug}/`)
- Automatic file type detection and validation
- Unique filename generation to prevent conflicts
- File existence and content retrieval with tenant isolation
- Storage statistics per tenant

**Usage:**
```php
// Upload a file
$document = $storageService->uploadFile($uploadedFile, 'Document Name', 'Description');

// Check if file exists
$exists = $storageService->fileExists($document);

// Get file content
$content = $storageService->getFileContent($document);

// Get storage statistics
$stats = $storageService->getStorageStats();
```

**Web Interface:**
- Visit `/{tenant-slug}/documents` to manage documents
- Upload files via `/{tenant-slug}/documents/upload`
- View document details at `/{tenant-slug}/documents/{id}`

### 2. Tenant-Aware Notifications

#### Service: `TenantNotificationService`

**Key Features:**
- In-app notifications with read/unread status
- Email notifications with tenant-specific branding
- Async processing via Symfony Messenger
- Notification statistics and management
- Multiple notification types (info, success, warning, error)

**Usage:**
```php
// Create a notification
$notification = $notificationService->createNotification(
    title: 'Notification Title',
    message: 'Notification message',
    type: 'info',
    recipientEmail: 'user@example.com',
    sendEmail: true,
    sendInApp: true,
    sendAsync: true
);

// Get unread notifications
$unread = $notificationService->getUnreadNotifications($user, 10);

// Mark as read
$notificationService->markAsRead($notification);

// Get statistics
$stats = $notificationService->getNotificationStats();
```

**Web Interface:**
- Visit `/{tenant-slug}/notifications` to view notifications
- Create notifications via `/{tenant-slug}/notifications/create`
- View notification details at `/{tenant-slug}/notifications/{id}`

### 3. Symfony Messenger Integration

#### Message: `SendNotificationMessage`
#### Handler: `SendNotificationMessageHandler`

**Key Features:**
- Async processing of email notifications
- Tenant context preservation in message queue
- Automatic retry on failure
- Tenant-specific error handling and logging

**Configuration:**
```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            App\Message\SendNotificationMessage: async
```

**Processing Messages:**
```bash
# Process queued messages
php bin/console messenger:consume async -vv

# Check message statistics
php bin/console messenger:stats
```

## Testing

### Command Line Testing

Use the comprehensive test command to verify all functionality:

```bash
# Test all features (without email)
php bin/console app:test-tenant-features acme-corp --skip-email

# Test with email functionality
php bin/console app:test-tenant-features acme-corp --email=test@example.com

# Test specific features only
php bin/console app:test-tenant-features acme-corp --skip-storage
php bin/console app:test-tenant-features acme-corp --skip-notifications
```

### Web Interface Testing

1. **Storage Testing:**
   - Visit `/{tenant-slug}/documents`
   - Click "Test Storage Functionality" to create a test file
   - Upload files and verify they're isolated per tenant

2. **Notification Testing:**
   - Visit `/{tenant-slug}/notifications`
   - Use the test buttons to create various notification types
   - Verify async processing and email delivery

3. **Cross-Tenant Isolation:**
   - Create files/notifications in one tenant
   - Switch to another tenant and verify isolation

## Architecture

### Tenant Context Management

The `TenantContextInterface` is used throughout the application to maintain tenant context:

```php
// Set tenant context
$tenantContext->setTenant($tenant);

// Get current tenant
$tenant = $tenantContext->getTenant();

// Check if tenant is set
$hasTenant = $tenantContext->hasTenant();
```

### Database Schema

#### Documents Table
- `id`: Primary key
- `name`: Display name
- `original_filename`: Original file name
- `file_path`: Relative path in tenant directory
- `mime_type`: File MIME type
- `file_size`: File size in bytes
- `tenant_id`: Foreign key to tenant
- `uploaded_by_id`: Foreign key to user (optional)

#### Notifications Table
- `id`: Primary key
- `title`: Notification title
- `message`: Notification content
- `type`: Notification type (info, success, warning, error)
- `status`: Processing status (pending, sent, failed)
- `recipient_email`: Email address (optional)
- `send_email`: Whether to send email
- `send_in_app`: Whether to show in app
- `is_read`: Read status for in-app notifications
- `tenant_id`: Foreign key to tenant
- `recipient_id`: Foreign key to user (optional)

### File System Structure

```
var/uploads/
├── tenants/acme-corp/
│   ├── document1_2024-12-30_12-34-56_abc123.pdf
│   └── document2_2024-12-30_12-35-10_def456.jpg
├── tenants/globex/
│   ├── file1_2024-12-30_13-00-00_ghi789.docx
│   └── file2_2024-12-30_13-01-00_jkl012.png
└── .gitkeep
```

## Security Considerations

### Tenant Isolation
- All database queries are automatically filtered by tenant
- File storage is physically separated by tenant directories
- Cross-tenant access is prevented at the service level
- Tenant context is preserved in async message processing

### File Security
- Files are stored outside the web root
- Access is controlled through the application
- File type validation and sanitization
- Unique filename generation prevents conflicts

### Email Security
- Tenant-specific sender addresses
- Email content is sanitized
- Tenant branding prevents spoofing
- Async processing prevents blocking

## Performance Considerations

### Storage
- Files are stored on the local filesystem for simplicity
- Consider cloud storage (S3, etc.) for production
- Implement file cleanup for deleted documents
- Monitor disk usage per tenant

### Messaging
- Async processing prevents UI blocking
- Message queue can be scaled horizontally
- Consider message prioritization for critical notifications
- Implement dead letter queues for failed messages

### Database
- Indexes on tenant_id for all tenant-scoped tables
- Consider partitioning for large datasets
- Monitor query performance with tenant filtering

## Configuration

### Environment Variables
```env
# Messenger transport (default: doctrine://default)
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async

# Mailer configuration (tenant-specific DSNs can be configured per tenant)
MAILER_DSN=smtp://localhost:1025
```

### Services Configuration
```yaml
# config/services.yaml
services:
    App\Service\TenantStorageService:
        arguments:
            $projectDir: '%kernel.project_dir%'
```

## Monitoring and Logging

### Storage Monitoring
- File upload/download events are logged
- Storage statistics are available per tenant
- File existence checks are logged

### Notification Monitoring
- Notification creation and processing events are logged
- Email delivery status is tracked
- Failed notifications are logged with error details

### Message Queue Monitoring
```bash
# Check queue status
php bin/console messenger:stats

# Monitor failed messages
php bin/console messenger:failed:show

# Retry failed messages
php bin/console messenger:failed:retry
```

## Troubleshooting

### Common Issues

1. **Files not uploading:**
   - Check directory permissions for `var/uploads/`
   - Verify tenant context is set
   - Check file size limits

2. **Notifications not sending:**
   - Verify mailer configuration
   - Check message queue processing
   - Review tenant-specific mailer DSN

3. **Cross-tenant access:**
   - Verify tenant context is properly set
   - Check service method implementations
   - Review database query filtering

### Debug Commands
```bash
# Test tenant features
php bin/console app:test-tenant-features {tenant-slug}

# Check message queue
php bin/console messenger:stats

# Process messages manually
php bin/console messenger:consume async --limit=10

# Clear cache
php bin/console cache:clear
```

## Future Enhancements

### Planned Features
- Cloud storage integration (S3, Google Cloud, etc.)
- Advanced notification templates
- Bulk file operations
- File versioning and history
- Advanced search and filtering
- Notification preferences per user
- Email template customization per tenant
- File sharing between tenants (with permissions)
- Audit logging for all operations
- API endpoints for mobile/external access

### Scalability Improvements
- Database sharding by tenant
- Separate message queues per tenant
- CDN integration for file delivery
- Caching layer for frequently accessed files
- Background cleanup jobs
- Metrics and monitoring dashboard