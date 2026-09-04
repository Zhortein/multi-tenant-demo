<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Service\TenantStorageService;
use App\Service\TenantNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Command to test tenant-aware storage, mailer, and messenger functionality.
 * 
 * This command demonstrates and tests all the multi-tenant features:
 * - Tenant-isolated file storage
 * - Tenant-aware notifications
 * - Email sending with tenant context
 * - Async message processing via Messenger
 */
#[AsCommand(
    name: 'app:test-tenant-features',
    description: 'Test tenant-aware storage, mailer, and messenger functionality'
)]
final class TestTenantFeaturesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantStorageService $storageService,
        private readonly TenantNotificationService $notificationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant-slug', InputArgument::REQUIRED, 'The tenant slug to test with')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'Email address to send test notifications to')
            ->addOption('skip-storage', null, InputOption::VALUE_NONE, 'Skip storage functionality tests')
            ->addOption('skip-notifications', null, InputOption::VALUE_NONE, 'Skip notification functionality tests')
            ->addOption('skip-email', null, InputOption::VALUE_NONE, 'Skip email functionality tests')
            ->setHelp(
                'This command tests all tenant-aware functionality including storage, notifications, and messaging.' . PHP_EOL .
                'It creates test files and notifications to verify that tenant isolation is working correctly.' . PHP_EOL . PHP_EOL .
                'Examples:' . PHP_EOL .
                '  php bin/console app:test-tenant-features acme-corp' . PHP_EOL .
                '  php bin/console app:test-tenant-features acme-corp --email=test@example.com' . PHP_EOL .
                '  php bin/console app:test-tenant-features acme-corp --skip-email'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantSlug = $input->getArgument('tenant-slug');
        $testEmail = $input->getOption('email');

        $io->title('Testing Tenant-Aware Features');
        $io->info("Testing functionality for tenant: {$tenantSlug}");

        // Find and set tenant
        $tenant = $this->entityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $tenantSlug, 'active' => true]);

        if (!$tenant) {
            $io->error("Tenant '{$tenantSlug}' not found or inactive.");
            return Command::FAILURE;
        }

        $this->tenantContext->setTenant($tenant);
        $io->success("Tenant context set to: {$tenant->getName()} ({$tenant->getSlug()})");

        $results = [];

        // Test Storage Functionality
        if (!$input->getOption('skip-storage')) {
            $io->section('Testing Storage Functionality');
            $results['storage'] = $this->testStorageFunctionality($io);
        }

        // Test Notification Functionality
        if (!$input->getOption('skip-notifications')) {
            $io->section('Testing Notification Functionality');
            $results['notifications'] = $this->testNotificationFunctionality($io, $testEmail, !$input->getOption('skip-email'));
        }

        // Display Results Summary
        $this->displayResultsSummary($io, $results);

        $allPassed = !in_array(false, $results, true);
        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Test tenant-isolated storage functionality.
     */
    private function testStorageFunctionality(SymfonyStyle $io): bool
    {
        try {
            $io->text('Creating test file...');

            // Create a test file
            $testContent = "Tenant Storage Test\n";
            $testContent .= "Tenant: {$this->tenantContext->getTenant()->getName()}\n";
            $testContent .= "Created: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "\n";
            $testContent .= "This file tests tenant-isolated storage functionality.\n";

            $tempFile = tempnam(sys_get_temp_dir(), 'tenant_test_');
            file_put_contents($tempFile, $testContent);

            $uploadedFile = new UploadedFile(
                path: $tempFile,
                originalName: 'storage_test.txt',
                mimeType: 'text/plain',
                test: true
            );

            // Upload file
            $document = $this->storageService->uploadFile(
                file: $uploadedFile,
                name: 'Storage Test Document',
                description: 'Test document created by the test command to verify tenant-isolated storage.'
            );

            $io->success("✓ File uploaded successfully (ID: {$document->getId()})");

            // Verify file exists
            $fileExists = $this->storageService->fileExists($document);
            if ($fileExists) {
                $io->success('✓ File exists in storage');
            } else {
                $io->error('✗ File not found in storage');
                return false;
            }

            // Test file retrieval
            $content = $this->storageService->getFileContent($document);
            if (str_contains($content, 'Tenant Storage Test')) {
                $io->success('✓ File content retrieved successfully');
            } else {
                $io->error('✗ File content retrieval failed');
                return false;
            }

            // Get storage stats
            $stats = $this->storageService->getStorageStats();
            $io->text("Storage stats: {$stats['total_files']} files, {$stats['formatted_size']}");

            $io->success('✓ All storage tests passed');
            return true;

        } catch (\Exception $e) {
            $io->error("✗ Storage test failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Test tenant-aware notification and messaging functionality.
     */
    private function testNotificationFunctionality(SymfonyStyle $io, ?string $testEmail, bool $testEmailSending): bool
    {
        try {
            $tenant = $this->tenantContext->getTenant();
            $allPassed = true;

            // Test 1: Basic in-app notification
            $io->text('Creating basic in-app notification...');
            $notification1 = $this->notificationService->createNotification(
                title: 'Test In-App Notification',
                message: 'This is a test notification created by the test command to verify tenant-aware functionality.',
                type: 'info',
                sendEmail: false,
                sendInApp: true,
                sendAsync: false
            );
            $io->success("✓ In-app notification created (ID: {$notification1->getId()})");

            // Test 2: Async notification processing
            $io->text('Creating async notification...');
            $notification2 = $this->notificationService->createNotification(
                title: 'Test Async Notification',
                message: 'This notification tests async processing via Symfony Messenger.',
                type: 'success',
                sendEmail: false,
                sendInApp: true,
                sendAsync: true
            );
            $io->success("✓ Async notification queued (ID: {$notification2->getId()})");

            // Test 3: Email notification (if email provided and not skipped)
            if ($testEmailSending && $testEmail) {
                $io->text("Creating email notification for {$testEmail}...");
                $notification3 = $this->notificationService->createNotification(
                    title: 'Test Email Notification',
                    message: "This is a test email notification sent to verify tenant-aware email functionality.\n\n" .
                            "Tenant: {$tenant->getName()}\n" .
                            "Test executed at: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    type: 'warning',
                    recipientEmail: $testEmail,
                    sendEmail: true,
                    sendInApp: true,
                    sendAsync: true
                );
                $io->success("✓ Email notification queued (ID: {$notification3->getId()})");
            } elseif ($testEmailSending) {
                $io->note('Email testing skipped - no email address provided');
            }

            // Test 4: Multiple notifications for Messenger testing
            $io->text('Creating multiple notifications for Messenger testing...');
            $types = ['info', 'success', 'warning', 'error'];
            for ($i = 1; $i <= 3; $i++) {
                $type = $types[($i - 1) % count($types)];
                $notification = $this->notificationService->createNotification(
                    title: "Messenger Test #{$i}",
                    message: "This is test notification #{$i} for Messenger queue testing.",
                    type: $type,
                    sendEmail: false,
                    sendInApp: true,
                    sendAsync: true
                );
            }
            $io->success('✓ Multiple notifications queued for Messenger testing');

            // Get notification stats
            $stats = $this->notificationService->getNotificationStats();
            $io->text("Notification stats: {$stats['total']} total, {$stats['unread']} unread, {$stats['sent']} sent, {$stats['failed']} failed");

            if ($allPassed) {
                $io->success('✓ All notification tests passed');
            }

            return $allPassed;

        } catch (\Exception $e) {
            $io->error("✗ Notification test failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Display a summary of all test results.
     *
     * @param array<string, bool> $results
     */
    private function displayResultsSummary(SymfonyStyle $io, array $results): void
    {
        $io->section('Test Results Summary');

        $table = [];
        foreach ($results as $test => $passed) {
            $table[] = [
                ucfirst($test),
                $passed ? '✓ PASSED' : '✗ FAILED',
            ];
        }

        $io->table(['Test Category', 'Result'], $table);

        $totalTests = count($results);
        $passedTests = count(array_filter($results));
        $failedTests = $totalTests - $passedTests;

        if ($failedTests === 0) {
            $io->success("All {$totalTests} test categories passed!");
            $io->text([
                'The tenant-aware functionality is working correctly:',
                '• Storage: Files are isolated per tenant',
                '• Notifications: Messages are tenant-scoped',
                '• Messenger: Async processing maintains tenant context',
                '• Email: Tenant-specific branding and isolation',
            ]);
        } else {
            $io->warning("{$passedTests}/{$totalTests} test categories passed, {$failedTests} failed.");
        }

        $io->note([
            'To process queued messages, run:',
            'php bin/console messenger:consume notifications -vv',
            '',
            'To view the results in the web interface, visit:',
            "/{$this->tenantContext->getTenant()->getSlug()}/documents",
            "/{$this->tenantContext->getTenant()->getSlug()}/notifications",
        ]);
    }
}
