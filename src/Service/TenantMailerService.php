<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Tenant-aware mailer service for sending emails with tenant-specific branding and context.
 * 
 * This service demonstrates how to send emails in a multi-tenant environment,
 * ensuring that emails are properly branded and contextualized for each tenant.
 * It integrates with Mailpit for development testing without actually sending emails.
 */
final readonly class TenantMailerService
{
    public function __construct(
        private MailerInterface $mailer,
        private TenantContextInterface $tenantContext,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Send a simple text email with tenant context.
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $content Email content
     * @param Tenant|null $tenant Tenant context (uses current if null)
     * 
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function sendSimpleEmail(
        string $to,
        string $subject,
        string $content,
        ?Tenant $tenant = null
    ): void {
        $tenant = $tenant ?? $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available for sending email');
        }

        $email = (new Email())
            ->from($this->getTenantFromAddress($tenant))
            ->to($to)
            ->subject($this->getTenantSubjectPrefix($tenant) . $subject)
            ->text($content)
            ->html($this->wrapContentWithTenantBranding($content, $tenant));

        $this->addTenantHeaders($email, $tenant);

        $this->logger->info('Sending simple email', [
            'tenant_slug' => $tenant->getSlug(),
            'to' => $to,
            'subject' => $subject,
        ]);

        $this->mailer->send($email);
    }

    /**
     * Send a templated email with tenant context.
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $template Twig template path
     * @param array<string, mixed> $context Template context variables
     * @param Tenant|null $tenant Tenant context (uses current if null)
     * 
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function sendTemplatedEmail(
        string $to,
        string $subject,
        string $template,
        array $context = [],
        ?Tenant $tenant = null
    ): void {
        $tenant = $tenant ?? $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available for sending email');
        }

        // Add tenant context to template variables
        $context['tenant'] = $tenant;
        $context['tenant_branding'] = $this->getTenantBrandingContext($tenant);

        $email = (new TemplatedEmail())
            ->from($this->getTenantFromAddress($tenant))
            ->to($to)
            ->subject($this->getTenantSubjectPrefix($tenant) . $subject)
            ->htmlTemplate($template)
            ->context($context);

        $this->addTenantHeaders($email, $tenant);

        $this->logger->info('Sending templated email', [
            'tenant_slug' => $tenant->getSlug(),
            'to' => $to,
            'subject' => $subject,
            'template' => $template,
        ]);

        $this->mailer->send($email);
    }

    /**
     * Send a notification email for a specific notification.
     * 
     * @param string $to Recipient email address
     * @param string $title Notification title
     * @param string $message Notification message
     * @param string $type Notification type
     * @param Tenant|null $tenant Tenant context (uses current if null)
     * 
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function sendNotificationEmail(
        string $to,
        string $title,
        string $message,
        string $type = 'info',
        ?Tenant $tenant = null
    ): void {
        $tenant = $tenant ?? $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available for sending email');
        }

        $this->sendTemplatedEmail(
            to: $to,
            subject: $title,
            template: 'emails/notification.html.twig',
            context: [
                'notification_title' => $title,
                'notification_message' => $message,
                'notification_type' => $type,
                'notification_icon' => $this->getNotificationIcon($type),
            ],
            tenant: $tenant
        );
    }

    /**
     * Send a welcome email to a new user.
     * 
     * @param string $to Recipient email address
     * @param string $userName User name
     * @param Tenant|null $tenant Tenant context (uses current if null)
     * 
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function sendWelcomeEmail(
        string $to,
        string $userName,
        ?Tenant $tenant = null
    ): void {
        $tenant = $tenant ?? $this->tenantContext->getTenant();
        
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available for sending email');
        }

        $this->sendTemplatedEmail(
            to: $to,
            subject: sprintf('Welcome to %s!', $tenant->getName()),
            template: 'emails/welcome.html.twig',
            context: [
                'user_name' => $userName,
                'tenant_name' => $tenant->getName(),
                'login_url' => sprintf('https://example.com/%s/login', $tenant->getSlug()),
            ],
            tenant: $tenant
        );
    }

    /**
     * Get tenant-specific from address.
     */
    private function getTenantFromAddress(Tenant $tenant): Address
    {
        $email = sprintf('noreply@%s.example.com', $tenant->getSlug());
        $name = sprintf('%s - No Reply', $tenant->getName());
        
        return new Address($email, $name);
    }

    /**
     * Get tenant-specific subject prefix.
     */
    private function getTenantSubjectPrefix(Tenant $tenant): string
    {
        return sprintf('[%s] ', $tenant->getName());
    }

    /**
     * Wrap content with tenant branding for HTML emails.
     */
    private function wrapContentWithTenantBranding(string $content, Tenant $tenant): string
    {
        return sprintf(
            '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <header style="background-color: #f8f9fa; padding: 20px; text-align: center; border-bottom: 2px solid #dee2e6;">
                    <h1 style="color: #495057; margin: 0;">%s</h1>
                    <p style="color: #6c757d; margin: 5px 0 0 0;">%s</p>
                </header>
                <main style="padding: 20px;">
                    %s
                </main>
                <footer style="background-color: #f8f9fa; padding: 15px; text-align: center; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d;">
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

    /**
     * Add tenant-specific headers to the email.
     */
    private function addTenantHeaders(Email $email, Tenant $tenant): void
    {
        $email->getHeaders()
            ->addTextHeader('X-Tenant-Slug', $tenant->getSlug())
            ->addTextHeader('X-Tenant-Name', $tenant->getName())
            ->addTextHeader('X-Tenant-ID', (string) $tenant->getId())
            ->addTextHeader('X-Mailer', 'Multi-Tenant Demo App');
    }

    /**
     * Get tenant branding context for templates.
     * 
     * @return array<string, mixed>
     */
    private function getTenantBrandingContext(Tenant $tenant): array
    {
        return [
            'name' => $tenant->getName(),
            'slug' => $tenant->getSlug(),
            'description' => $tenant->getDescription(),
            'primary_color' => '#007bff', // Could be tenant-specific
            'logo_url' => sprintf('https://example.com/logos/%s.png', $tenant->getSlug()),
            'website_url' => sprintf('https://example.com/%s', $tenant->getSlug()),
        ];
    }

    /**
     * Get icon for notification type.
     */
    private function getNotificationIcon(string $type): string
    {
        return match ($type) {
            'success' => '✅',
            'warning' => '⚠️',
            'error' => '❌',
            'info' => 'ℹ️',
            default => '📧',
        };
    }
}