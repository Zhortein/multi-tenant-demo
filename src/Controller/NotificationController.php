<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\TenantNotificationTrait;
use App\Entity\Notification;
use App\Service\TenantNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Controller for testing tenant-aware notification and messaging functionality.
 * 
 * This controller demonstrates how notifications, emails, and messaging work
 * within a multi-tenant context, ensuring proper data isolation and
 * tenant-specific communication management.
 */
#[Route('/{tenantSlug}/notifications')]
final class NotificationController extends AbstractController
{
    use TenantNotificationTrait;
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantNotificationService $notificationService
    ) {
    }

    /**
     * List all notifications for the current tenant.
     */
    #[Route('', name: 'tenant_notification_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(['tenant' => $tenant], ['createdAt' => 'DESC']);

        $stats = $this->notificationService->getNotificationStats();

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('notification/index.html.twig', [
            'tenant' => $tenant,
            'notifications' => $notifications,
            'stats' => $stats,
            ...$notificationData,
        ]);
    }

    /**
     * Show notification creation form and handle creation.
     */
    #[Route('/create', name: 'tenant_notification_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();

        if ($request->isMethod('POST')) {
            $title = $request->request->get('title', '');
            $message = $request->request->get('message', '');
            $type = $request->request->get('type', Notification::TYPE_INFO);
            $recipientEmail = $request->request->get('recipient_email', '');
            $sendEmail = $request->request->getBoolean('send_email', false);
            $sendAsync = $request->request->getBoolean('send_async', true);

            if (empty($title) || empty($message)) {
                $this->addFlash('error', 'Title and message are required.');
                return $this->redirectToRoute('tenant_notification_create', ['tenantSlug' => $tenant->getSlug()]);
            }

            try {
                $notification = $this->notificationService->createNotification(
                    title: $title,
                    message: $message,
                    type: $type,
                    recipientEmail: $recipientEmail ?: null,
                    sendEmail: $sendEmail,
                    sendInApp: true,
                    sendAsync: $sendAsync
                );

                $this->addFlash('success', sprintf(
                    'Notification "%s" created successfully! %s',
                    $notification->getTitle(),
                    $sendAsync ? 'It will be processed asynchronously.' : 'It has been processed immediately.'
                ));

                return $this->redirectToRoute('tenant_notification_show', [
                    'tenantSlug' => $tenant->getSlug(),
                    'id' => $notification->getId(),
                ]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to create notification: ' . $e->getMessage());
            }
        }

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('notification/create.html.twig', [
            'tenant' => $tenant,
            'notification_types' => Notification::getTypes(),
            ...$notificationData,
        ]);
    }

    /**
     * Show notification details.
     */
    #[Route('/{id}', name: 'tenant_notification_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Notification $notification): Response
    {
        $tenant = $this->tenantContext->getTenant();

        // Verify notification belongs to current tenant
        if ($notification->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Notification not found.');
        }

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('notification/show.html.twig', [
            'tenant' => $tenant,
            'notification' => $notification,
            ...$notificationData,
        ]);
    }

    /**
     * Mark notification as read.
     */
    #[Route('/{id}/read', name: 'tenant_notification_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markAsRead(Notification $notification, Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();

        // Verify notification belongs to current tenant
        if ($notification->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Notification not found.');
        }

        // CSRF protection
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('read_notification_' . $notification->getId(), $token)) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('tenant_notification_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $notification->getId(),
            ]);
        }

        try {
            $this->notificationService->markAsRead($notification);
            $this->addFlash('success', 'Notification marked as read.');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to mark notification as read: ' . $e->getMessage());
        }

        return $this->redirectToRoute('tenant_notification_show', [
            'tenantSlug' => $tenant->getSlug(),
            'id' => $notification->getId(),
        ]);
    }

    /**
     * Test notification functionality.
     */
    #[Route('/test/create', name: 'tenant_notification_test', methods: ['POST'])]
    public function testNotification(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $recipientEmail = $request->request->get('recipient_email', '');
        $sendEmail = $request->request->getBoolean('send_email', false);

        try {
            $notification = $this->notificationService->sendTestNotification(
                recipientEmail: $recipientEmail ?: null,
                sendEmail: $sendEmail
            );

            $this->addFlash('success', sprintf(
                'Test notification created successfully! ID: %d. %s',
                $notification->getId(),
                $sendEmail ? 'Email will be sent asynchronously.' : 'No email will be sent.'
            ));

            return $this->redirectToRoute('tenant_notification_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $notification->getId(),
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create test notification: ' . $e->getMessage());
        }

        return $this->redirectToRoute('tenant_notification_index', [
            'tenantSlug' => $tenant->getSlug(),
        ]);
    }

    /**
     * Test messenger functionality by creating multiple notifications.
     */
    #[Route('/test/messenger', name: 'tenant_notification_test_messenger', methods: ['POST'])]
    public function testMessenger(): Response
    {
        $tenant = $this->tenantContext->getTenant();

        try {
            $notifications = [];
            $types = [Notification::TYPE_INFO, Notification::TYPE_SUCCESS, Notification::TYPE_WARNING, Notification::TYPE_ERROR];

            // Create 5 test notifications with different types
            for ($i = 1; $i <= 5; $i++) {
                $type = $types[($i - 1) % count($types)];
                
                $notification = $this->notificationService->createNotification(
                    title: "Messenger Test Notification #{$i}",
                    message: "This is test notification #{$i} created to verify that Symfony Messenger " .
                            "is working correctly with tenant isolation. Created at " . 
                            (new \DateTimeImmutable())->format('Y-m-d H:i:s') . " for tenant '{$tenant->getName()}'.",
                    type: $type,
                    sendEmail: false,
                    sendInApp: true,
                    sendAsync: true
                );

                $notifications[] = $notification;
            }

            $this->addFlash('success', sprintf(
                'Created %d test notifications for Messenger testing! They will be processed asynchronously.',
                count($notifications)
            ));

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create test notifications: ' . $e->getMessage());
        }

        return $this->redirectToRoute('tenant_notification_index', [
            'tenantSlug' => $tenant->getSlug(),
        ]);
    }

    /**
     * Test email functionality.
     */
    #[Route('/test/email', name: 'tenant_notification_test_email', methods: ['POST'])]
    public function testEmail(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $recipientEmail = $request->request->get('recipient_email', '');

        if (empty($recipientEmail)) {
            $this->addFlash('error', 'Recipient email is required for email testing.');
            return $this->redirectToRoute('tenant_notification_index', ['tenantSlug' => $tenant->getSlug()]);
        }

        try {
            $notification = $this->notificationService->createNotification(
                title: 'Email Test Notification',
                message: "This is a test email notification sent to verify that the tenant-aware email " .
                        "functionality is working correctly.\n\n" .
                        "Tenant: {$tenant->getName()}\n" .
                        "Sent at: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "\n\n" .
                        "If you receive this email, the multi-tenant email system is functioning properly!",
                type: Notification::TYPE_SUCCESS,
                recipientEmail: $recipientEmail,
                sendEmail: true,
                sendInApp: true,
                sendAsync: true
            );

            $this->addFlash('success', sprintf(
                'Test email notification created and queued for sending to %s! Notification ID: %d',
                $recipientEmail,
                $notification->getId()
            ));

            return $this->redirectToRoute('tenant_notification_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $notification->getId(),
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create test email notification: ' . $e->getMessage());
        }

        return $this->redirectToRoute('tenant_notification_index', [
            'tenantSlug' => $tenant->getSlug(),
        ]);
    }
}