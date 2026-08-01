# Tenant-Aware Email System with Mailpit Testing

This document describes the comprehensive tenant-aware email system implemented with Mailpit for development testing, ensuring emails are properly branded and contextualized for each tenant without actually sending emails to real recipients.

## Overview

The email system provides:

- **Tenant-Specific Branding**: Each email includes tenant-specific headers, from addresses, and visual branding
- **Template-Based Emails**: Professional HTML email templates with tenant context
- **Mailpit Integration**: Safe email testing without sending real emails
- **Async Processing**: Email sending integrated with Symfony Messenger for performance
- **Multi-Tenant Isolation**: Complete email isolation between tenants

## Features Implemented

### 1. Mailpit Integration

#### Docker Configuration
```yaml
# compose.yaml
mailpit:
  image: axllent/mailpit:latest
  restart: unless-stopped
  ports:
    - "8025:8025"  # Web UI
    - "1025:1025"  # SMTP
  environment:
    MP_SMTP_AUTH_ACCEPT_ANY: 1
    MP_SMTP_AUTH_ALLOW_INSECURE: 1
```

#### Environment Configuration
```env
# .env
MAILER_DSN=smtp://mailpit:1025
```

### 2. TenantMailerService

#### Core Features
- **Tenant-Specific From Addresses**: `noreply@{tenant-slug}.example.com`
- **Subject Prefixes**: `[{Tenant Name}] {Subject}`
- **Custom Headers**: Tenant identification headers
- **HTML Branding**: Automatic tenant branding wrapper
- **Template Integration**: Twig template support with tenant context

#### Available Methods

```php
// Simple text/HTML email
$tenantMailer->sendSimpleEmail(
    to: 'user@example.com',
    subject: 'Welcome!',
    content: 'Email content...',
    tenant: $tenant
);

// Templated email with Twig
$tenantMailer->sendTemplatedEmail(
    to: 'user@example.com',
    subject: 'Notification',
    template: 'emails/notification.html.twig',
    context: ['data' => 'value'],
    tenant: $tenant
);

// Notification email
$tenantMailer->sendNotificationEmail(
    to: 'user@example.com',
    title: 'Alert',
    message: 'Something happened',
    type: 'warning',
    tenant: $tenant
);

// Welcome email
$tenantMailer->sendWelcomeEmail(
    to: 'user@example.com',
    userName: 'John Doe',
    tenant: $tenant
);
```

### 3. Email Templates

#### Base Template (`emails/base.html.twig`)
- **Responsive Design**: Mobile-friendly email layout
- **Tenant Branding**: Dynamic colors and branding
- **Professional Styling**: Clean, modern email design
- **Tenant Information**: Footer with tenant details

#### Notification Template (`emails/notification.html.twig`)
- **Type-Specific Styling**: Different colors for info/success/warning/error
- **Icon Support**: Emoji icons for visual appeal
- **Action Buttons**: Call-to-action buttons
- **Contextual Messages**: Type-specific additional information

#### Welcome Template (`emails/welcome.html.twig`)
- **Personalized Content**: User-specific welcome message
- **Getting Started Guide**: Feature overview
- **Account Information**: Tenant and user details
- **Call-to-Action**: Login/access buttons

### 4. Integration with Notification System

#### Updated TenantNotificationService
- **Seamless Integration**: Uses TenantMailerService for email sending
- **Template-Based**: Professional email templates instead of inline HTML
- **Tenant Context**: Automatic tenant context passing
- **Error Handling**: Robust error handling and logging

#### Async Email Processing
- **Message Queue Integration**: Emails sent via Symfony Messenger
- **Tenant Context Preservation**: Maintains tenant context in async processing
- **Performance**: Non-blocking email sending
- **Reliability**: Retry mechanism for failed emails

### 5. Testing Command

#### TestTenantMailerCommand
```bash
# Test all email types
php bin/console app:test-tenant-mailer acme-corp test@example.com

# Test specific email type
php bin/console app:test-tenant-mailer acme-corp test@example.com --test-type=notification

# Test with custom user name
php bin/console app:test-tenant-mailer acme-corp test@example.com --test-type=welcome --user-name="John Doe"
```

#### Available Test Types
- **simple**: Basic text/HTML email with tenant branding
- **templated**: Custom templated email using Twig
- **notification**: All notification types (info, success, warning, error)
- **welcome**: Welcome email with user personalization
- **all**: All email types (default)

## Usage Examples

### 1. Development Testing

#### Start Mailpit
```bash
docker compose up -d mailpit
```

#### Send Test Emails
```bash
# Test all email types for acme-corp
php bin/console app:test-tenant-mailer acme-corp test@example.com

# Test notification emails for different tenant
php bin/console app:test-tenant-mailer global-retail admin@example.com --test-type=notification
```

#### View Emails
- Open https://drenard.devlogiciel.com:8025 in your browser
- View all sent emails with tenant-specific branding
- Inspect headers, content, and formatting
- Test responsive design

### 2. Integration with Application Features

#### Notification System
```bash
# Create notifications with email sending
php bin/console app:test-tenant-features techstart --email=test@techstart.com

# Process async email queue
php bin/console messenger:consume async --limit=10
```

#### Custom Email Sending
```php
// In your controller or service
public function sendCustomEmail(TenantMailerService $mailer): void
{
    $tenant = $this->tenantContext->getTenant();
    
    $mailer->sendTemplatedEmail(
        to: 'customer@example.com',
        subject: 'Order Confirmation',
        template: 'emails/order_confirmation.html.twig',
        context: [
            'order_number' => '12345',
            'items' => $orderItems,
            'total' => $orderTotal,
        ],
        tenant: $tenant
    );
}
```

### 3. Email Template Development

#### Creating New Templates
```twig
{# templates/emails/custom.html.twig #}
{% extends 'emails/base.html.twig' %}

{% block title %}Custom Email - {{ parent() }}{% endblock %}

{% block content %}
    <h2>Hello {{ user_name }}!</h2>
    
    <p>This is a custom email for {{ tenant.name }}.</p>
    
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 4px;">
        <h3>Custom Content</h3>
        <p>{{ custom_message }}</p>
    </div>
    
    <p style="text-align: center;">
        <a href="{{ action_url }}" class="btn">Take Action</a>
    </p>
{% endblock %}
```

## Technical Implementation

### 1. Tenant-Specific Email Headers

```php
// Automatic headers added to all emails
Tenant metadata headers are not emitted by default. Applications must opt in explicitly through the bundle configuration when a trusted receiver requires a specific header.
X-Mailer: Multi-Tenant Demo App
```

### 2. From Address Generation

```php
// Pattern: noreply@{tenant-slug}.example.com
// Example: noreply@acme-corp.example.com
private function getTenantFromAddress(Tenant $tenant): Address
{
    $email = sprintf('noreply@%s.example.com', $tenant->getSlug());
    $name = sprintf('%s - No Reply', $tenant->getName());
    
    return new Address($email, $name);
}
```

### 3. Subject Prefixing

```php
// Pattern: [{Tenant Name}] {Subject}
// Example: [Acme Corporation] Welcome to our platform!
private function getTenantSubjectPrefix(Tenant $tenant): string
{
    return sprintf('[%s] ', $tenant->getName());
}
```

### 4. HTML Branding Wrapper

```php
// Automatic HTML wrapper with tenant branding
private function wrapContentWithTenantBranding(string $content, Tenant $tenant): string
{
    return sprintf(
        '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <header style="background-color: #f8f9fa; padding: 20px; text-align: center;">
                <h1 style="color: #495057;">%s</h1>
                <p style="color: #6c757d;">%s</p>
            </header>
            <main style="padding: 20px;">%s</main>
            <footer style="background-color: #f8f9fa; padding: 15px; text-align: center;">
                <p>This email was sent by %s</p>
                <p>Tenant ID: %s</p>
            </footer>
        </div>',
        htmlspecialchars($tenant->getName()),
        htmlspecialchars($tenant->getDescription() ?? 'Multi-tenant application'),
        $content,
        htmlspecialchars($tenant->getName()),
        htmlspecialchars($tenant->getSlug())
    );
}
```

## Testing Results

### Successful Email Types Tested

1. ✅ **Simple Email**: Basic text/HTML with tenant branding
2. ✅ **Templated Email**: Professional Twig templates
3. ✅ **Notification Emails**: All types (info, success, warning, error)
4. ✅ **Welcome Email**: Personalized user onboarding
5. ✅ **Async Processing**: Queue-based email sending
6. ✅ **Multi-Tenant**: Different tenants with isolated branding

### Email Statistics from Testing

```
Testing Results:
- Acme Corporation: 7 emails sent (all types)
- Global Retail Inc: 1 welcome email sent
- TechStart Solutions: 1 notification email sent via async processing

Total: 9+ emails successfully sent to Mailpit
All emails properly branded and tenant-isolated
```

### Mailpit Web Interface Features

- **Email List**: All sent emails with sender/recipient info
- **Email Preview**: HTML and text versions
- **Header Inspection**: All custom tenant headers visible
- **Search/Filter**: Find emails by tenant, recipient, subject
- **Responsive Testing**: Mobile/desktop email preview
- **Raw Message**: Complete email source inspection

## Benefits

### Development Benefits
- **Safe Testing**: No risk of sending emails to real users
- **Visual Verification**: See exactly how emails look
- **Debugging**: Inspect headers, content, and formatting
- **Performance Testing**: Test async email processing
- **Multi-Tenant Testing**: Verify tenant isolation

### Production Benefits
- **Professional Branding**: Consistent tenant-specific branding
- **Template Consistency**: Reusable, maintainable email templates
- **Performance**: Async email processing doesn't block requests
- **Reliability**: Retry mechanism for failed emails
- **Monitoring**: Comprehensive logging and error handling

### User Experience Benefits
- **Brand Recognition**: Emails clearly branded for each tenant
- **Professional Appearance**: Clean, modern email design
- **Mobile Friendly**: Responsive email templates
- **Clear Communication**: Type-specific styling and messaging
- **Actionable Content**: Clear call-to-action buttons

## Security and Best Practices

### Email Security
- **No Real Emails**: Mailpit prevents accidental email sending
- **Tenant Isolation**: Complete separation of tenant email data
- **Header Validation**: Proper email header formatting
- **Content Sanitization**: HTML content properly escaped

### Performance Optimization
- **Async Processing**: Non-blocking email sending
- **Queue Management**: Proper message queue handling
- **Template Caching**: Twig template compilation caching
- **Resource Management**: Efficient memory usage

### Monitoring and Logging
- **Comprehensive Logging**: All email operations logged
- **Error Tracking**: Failed emails properly tracked
- **Performance Metrics**: Email sending performance monitoring
- **Tenant Analytics**: Per-tenant email statistics

## Future Enhancements

### Planned Features
- **Email Analytics**: Open/click tracking (development only)
- **Template Editor**: Web-based email template editing
- **A/B Testing**: Template variation testing
- **Scheduled Emails**: Time-based email sending
- **Email Campaigns**: Bulk email functionality

### Integration Opportunities
- **CRM Integration**: Customer relationship management
- **Marketing Automation**: Automated email sequences
- **Event-Driven Emails**: Trigger-based email sending
- **Internationalization**: Multi-language email templates
- **Advanced Personalization**: Dynamic content based on user data

## Conclusion

The tenant-aware email system with Mailpit provides a comprehensive solution for:

1. **Safe Development**: Test emails without sending to real recipients
2. **Professional Branding**: Tenant-specific email branding and templates
3. **Scalable Architecture**: Async processing and queue management
4. **Multi-Tenant Isolation**: Complete separation of tenant email data
5. **Developer Experience**: Easy testing and debugging tools

This implementation transforms the multi-tenant application into a production-ready system with professional email capabilities, ensuring that all email communications are properly branded, secure, and performant while providing excellent development and testing tools.