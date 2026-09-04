<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Service\TenantMailerService;
use App\Service\TenantNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Controller for testing tenant-aware email functionality through web interface.
 * 
 * This controller provides a user-friendly web interface for testing various
 * email types with different tenants, demonstrating the email system capabilities
 * without requiring command-line access.
 * 
 * @phpstan-type EmailTestData array{
 *     recipient_email: string,
 *     test_type: string,
 *     user_name?: string,
 *     custom_subject?: string,
 *     custom_message?: string,
 *     notification_type?: string
 * }
 */
#[Route('/{tenantSlug}/email-test', name: 'app_email_test_')]
final class EmailTestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantMailerService $tenantMailer,
        private readonly TenantNotificationService $notificationService,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * Display the email testing interface.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('Tenant not found');
        }

        return $this->render('email_test/index.html.twig', [
            'current_tenant' => $tenant,
            'email_types' => $this->getAvailableEmailTypes(),
            'notification_types' => $this->getNotificationTypes(),
        ]);
    }

    /**
     * Send a test email based on the form submission.
     */
    #[Route('/send', name: 'send', methods: ['POST'])]
    public function sendTestEmail(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('Tenant not found');
        }

        /** @var EmailTestData $data */
        $data = [
            'recipient_email' => $request->request->get('recipient_email', ''),
            'test_type' => $request->request->get('test_type', 'simple'),
            'user_name' => $request->request->get('user_name', 'Test User'),
            'custom_subject' => $request->request->get('custom_subject', ''),
            'custom_message' => $request->request->get('custom_message', ''),
            'notification_type' => $request->request->get('notification_type', 'info'),
        ];

        // Validate the input data
        $violations = $this->validateEmailTestData($data);
        
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }
            
            return $this->redirectToRoute('app_email_test_index', [
                'tenantSlug' => $tenant->getSlug()
            ]);
        }

        try {
            $emailsSent = $this->sendEmailByType($data, $tenant);
            
            $this->addFlash('success', sprintf(
                '✅ Successfully sent %d email(s) to Mailpit! Check https://drenard.devlogiciel.com:8025 to view them.',
                $emailsSent
            ));
            
            $this->addFlash('info', sprintf(
                '📧 Email sent from: %s with tenant branding for "%s"',
                sprintf('noreply@%s.example.com', $tenant->getSlug()),
                $tenant->getName()
            ));

        } catch (\Exception $e) {
            $this->addFlash('error', sprintf(
                '❌ Failed to send email: %s',
                $e->getMessage()
            ));
        }

        return $this->redirectToRoute('app_email_test_index', [
            'tenantSlug' => $tenant->getSlug()
        ]);
    }

    /**
     * Send a notification with email through the notification system.
     */
    #[Route('/send-notification', name: 'send_notification', methods: ['POST'])]
    public function sendNotificationWithEmail(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('Tenant not found');
        }

        $recipientEmail = $request->request->get('recipient_email', '');
        $title = $request->request->get('notification_title', 'Test Notification');
        $message = $request->request->get('notification_message', 'This is a test notification sent through the notification system.');
        $type = $request->request->get('notification_type', 'info');
        $sendAsync = $request->request->getBoolean('send_async', false);

        // Validate email
        $violations = $this->validator->validate($recipientEmail, [
            new Assert\NotBlank(message: 'Email address is required'),
            new Assert\Email(message: 'Please provide a valid email address'),
        ]);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }
            
            return $this->redirectToRoute('app_email_test_index', [
                'tenantSlug' => $tenant->getSlug()
            ]);
        }

        try {
            $notification = $this->notificationService->createNotification(
                title: $title,
                message: $message,
                type: $type,
                recipientEmail: $recipientEmail,
                sendEmail: true,
                sendInApp: true,
                sendAsync: $sendAsync
            );

            if ($sendAsync) {
                $this->addFlash('success', sprintf(
                    '✅ Notification queued for async processing (ID: %d). Run "php bin/console messenger:consume notifications" to process it.',
                    $notification->getId()
                ));
                
                $this->addFlash('info', '⏳ The email will be sent asynchronously through the message queue.');
            } else {
                $this->addFlash('success', sprintf(
                    '✅ Notification sent immediately (ID: %d). Check Mailpit to view the email.',
                    $notification->getId()
                ));
            }

        } catch (\Exception $e) {
            $this->addFlash('error', sprintf(
                '❌ Failed to create notification: %s',
                $e->getMessage()
            ));
        }

        return $this->redirectToRoute('app_email_test_index', [
            'tenantSlug' => $tenant->getSlug()
        ]);
    }

    /**
     * Display Mailpit interface in an iframe.
     */
    #[Route('/mailpit', name: 'mailpit', methods: ['GET'])]
    public function mailpit(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('Tenant not found');
        }

        return $this->render('email_test/mailpit.html.twig', [
            'current_tenant' => $tenant,
        ]);
    }

    /**
     * Send email based on the selected type.
     * 
     * @param EmailTestData $data
     */
    private function sendEmailByType(array $data, Tenant $tenant): int
    {
        $recipientEmail = $data['recipient_email'];
        $testType = $data['test_type'];

        return match ($testType) {
            'simple' => $this->sendSimpleTestEmail($recipientEmail, $data, $tenant),
            'templated' => $this->sendTemplatedTestEmail($recipientEmail, $data, $tenant),
            'notification' => $this->sendNotificationTestEmail($recipientEmail, $data, $tenant),
            'welcome' => $this->sendWelcomeTestEmail($recipientEmail, $data, $tenant),
            'all' => $this->sendAllTestEmails($recipientEmail, $data, $tenant),
            default => throw new \InvalidArgumentException(sprintf('Unknown test type: %s', $testType)),
        };
    }

    private function sendSimpleTestEmail(string $recipientEmail, array $data, Tenant $tenant): int
    {
        $subject = $data['custom_subject'] ?: 'Simple Email Test';
        $content = $data['custom_message'] ?: sprintf(
            "Hello!\n\nThis is a simple email test for tenant %s.\n\nThis email demonstrates:\n- Tenant-specific from address\n- Tenant branding in HTML\n- Custom headers\n\nBest regards,\nThe %s Team",
            $tenant->getName(),
            $tenant->getName()
        );

        $this->tenantMailer->sendSimpleEmail(
            to: $recipientEmail,
            subject: $subject,
            content: $content,
            tenant: $tenant
        );

        return 1;
    }

    private function sendTemplatedTestEmail(string $recipientEmail, array $data, Tenant $tenant): int
    {
        $subject = $data['custom_subject'] ?: 'Templated Email Test';
        $message = $data['custom_message'] ?: sprintf(
            'This is a templated email test for tenant %s. This demonstrates how to use Twig templates with tenant-specific context and branding.',
            $tenant->getName()
        );

        $this->tenantMailer->sendTemplatedEmail(
            to: $recipientEmail,
            subject: $subject,
            template: 'emails/notification.html.twig',
            context: [
                'notification_title' => $subject,
                'notification_message' => $message,
                'notification_type' => 'info',
                'notification_icon' => '🧪',
            ],
            tenant: $tenant
        );

        return 1;
    }

    private function sendNotificationTestEmail(string $recipientEmail, array $data, Tenant $tenant): int
    {
        $notificationType = $data['notification_type'];
        $title = sprintf('%s Notification Test', ucfirst($notificationType));
        $message = match ($notificationType) {
            'success' => 'This is a success notification indicating that an operation completed successfully.',
            'warning' => 'This is a warning notification that requires your attention.',
            'error' => 'This is an error notification indicating that something went wrong and needs to be addressed.',
            default => 'This is an informational notification for testing purposes.',
        };

        $this->tenantMailer->sendNotificationEmail(
            to: $recipientEmail,
            title: $title,
            message: $message,
            type: $notificationType,
            tenant: $tenant
        );

        return 1;
    }

    private function sendWelcomeTestEmail(string $recipientEmail, array $data, Tenant $tenant): int
    {
        $userName = $data['user_name'] ?: 'Test User';

        $this->tenantMailer->sendWelcomeEmail(
            to: $recipientEmail,
            userName: $userName,
            tenant: $tenant
        );

        return 1;
    }

    private function sendAllTestEmails(string $recipientEmail, array $data, Tenant $tenant): int
    {
        $emailsSent = 0;

        // Send simple email
        $emailsSent += $this->sendSimpleTestEmail($recipientEmail, $data, $tenant);

        // Send templated email
        $emailsSent += $this->sendTemplatedTestEmail($recipientEmail, $data, $tenant);

        // Send all notification types
        foreach (['info', 'success', 'warning', 'error'] as $type) {
            $notificationData = array_merge($data, ['notification_type' => $type]);
            $emailsSent += $this->sendNotificationTestEmail($recipientEmail, $notificationData, $tenant);
        }

        // Send welcome email
        $emailsSent += $this->sendWelcomeTestEmail($recipientEmail, $data, $tenant);

        return $emailsSent;
    }

    /**
     * Validate email test data.
     * 
     * @param EmailTestData $data
     * @return \Symfony\Component\Validator\ConstraintViolationListInterface
     */
    private function validateEmailTestData(array $data): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        $constraints = new Assert\Collection([
            'recipient_email' => [
                new Assert\NotBlank(message: 'Email address is required'),
                new Assert\Email(message: 'Please provide a valid email address'),
            ],
            'test_type' => [
                new Assert\NotBlank(),
                new Assert\Choice(
                    choices: array_keys($this->getAvailableEmailTypes()),
                    message: 'Please select a valid test type'
                ),
            ],
            'user_name' => new Assert\Optional([
                new Assert\Length(max: 100, maxMessage: 'User name cannot be longer than 100 characters'),
            ]),
            'custom_subject' => new Assert\Optional([
                new Assert\Length(max: 200, maxMessage: 'Subject cannot be longer than 200 characters'),
            ]),
            'custom_message' => new Assert\Optional([
                new Assert\Length(max: 2000, maxMessage: 'Message cannot be longer than 2000 characters'),
            ]),
            'notification_type' => new Assert\Optional([
                new Assert\Choice(
                    choices: array_keys($this->getNotificationTypes()),
                    message: 'Please select a valid notification type'
                ),
            ]),
        ]);

        return $this->validator->validate($data, $constraints);
    }

    /**
     * Get available email types for testing.
     * 
     * @return array<string, string>
     */
    private function getAvailableEmailTypes(): array
    {
        return [
            'simple' => 'Simple Email',
            'templated' => 'Templated Email',
            'notification' => 'Notification Email',
            'welcome' => 'Welcome Email',
            'all' => 'All Email Types',
        ];
    }

    /**
     * Get available notification types.
     * 
     * @return array<string, string>
     */
    private function getNotificationTypes(): array
    {
        return [
            'info' => 'Information',
            'success' => 'Success',
            'warning' => 'Warning',
            'error' => 'Error',
        ];
    }
}
