<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Document;
use App\Entity\Notification;
use App\Entity\Tenant;

/**
 * Trait to provide notification data for tenant navigation.
 * 
 * This trait provides common functionality for fetching notification
 * data that is needed in the tenant navigation across all tenant pages.
 */
trait TenantNotificationTrait
{
    /**
     * Get notification data for the tenant navigation.
     * 
     * @return array{recent_notifications: Notification[], unread_notifications_count: int, notification_stats: array<string, int>, storage_stats: array<string, mixed>}
     */
    protected function getNotificationDataForNavigation(Tenant $tenant): array
    {
        // Get recent notifications for the dropdown (last 5)
        $recentNotifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(
                ['tenant' => $tenant, 'sendInApp' => true],
                ['createdAt' => 'DESC'],
                5
            );

        // Get unread notifications count for the badge
        $unreadNotificationsCount = $this->entityManager->getRepository(Notification::class)
            ->count([
                'tenant' => $tenant,
                'sendInApp' => true,
                'isRead' => false
            ]);

        // Get notification stats for navigation badges
        $notificationRepository = $this->entityManager->getRepository(Notification::class);
        $notificationStats = [
            'total' => $notificationRepository->count(['tenant' => $tenant]),
            'unread' => $notificationRepository->count(['tenant' => $tenant, 'isRead' => false, 'sendInApp' => true]),
            'sent' => $notificationRepository->count(['tenant' => $tenant, 'status' => Notification::STATUS_SENT]),
            'failed' => $notificationRepository->count(['tenant' => $tenant, 'status' => Notification::STATUS_FAILED]),
        ];

        // Get storage stats for navigation badges
        $documentRepository = $this->entityManager->getRepository(Document::class);
        $documents = $documentRepository->findBy(['tenant' => $tenant, 'active' => true]);
        $totalFiles = count($documents);
        $totalSize = array_sum(array_map(fn(Document $doc) => $doc->getFileSize(), $documents));
        
        $storageStats = [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'formatted_size' => $this->formatBytes($totalSize),
        ];

        return [
            'recent_notifications' => $recentNotifications,
            'unread_notifications_count' => $unreadNotificationsCount,
            'notification_stats' => $notificationStats,
            'storage_stats' => $storageStats,
        ];
    }

    /**
     * Format bytes into human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($bytes, 1024);
        $unitIndex = (int) floor($base);
        $size = round(pow(1024, $base - $unitIndex), 2);

        return $size . ' ' . ($units[$unitIndex] ?? 'PB');
    }
}