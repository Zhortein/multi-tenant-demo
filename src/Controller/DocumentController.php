<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\TenantNotificationTrait;
use App\Entity\Document;
use App\Service\TenantStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Controller for testing tenant-aware document storage functionality.
 * 
 * This controller demonstrates how file uploads and downloads work
 * within a multi-tenant context, ensuring proper data isolation
 * and tenant-specific storage management.
 */
#[Route('/{tenantSlug}/documents')]
final class DocumentController extends AbstractController
{
    use TenantNotificationTrait;
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantStorageService $storageService
    ) {
    }

    /**
     * List all documents for the current tenant.
     */
    #[Route('', name: 'tenant_document_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $documents = $this->entityManager->getRepository(Document::class)
            ->findBy(['tenant' => $tenant, 'active' => true], ['createdAt' => 'DESC']);

        $storageStats = $this->storageService->getStorageStats();

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('document/index.html.twig', [
            'tenant' => $tenant,
            'documents' => $documents,
            'storage_stats' => $storageStats,
            ...$notificationData,
        ]);
    }

    /**
     * Show document upload form and handle file uploads.
     */
    #[Route('/upload', name: 'tenant_document_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();

        if ($request->isMethod('POST')) {
            $uploadedFile = $request->files->get('file');
            $name = $request->request->get('name', '');
            $description = $request->request->get('description', '');

            if (!$uploadedFile instanceof UploadedFile) {
                $this->addFlash('error', 'Please select a file to upload.');
                return $this->redirectToRoute('tenant_document_upload', ['tenantSlug' => $tenant->getSlug()]);
            }

            if (!$uploadedFile->isValid()) {
                $this->addFlash('error', 'The uploaded file is not valid.');
                return $this->redirectToRoute('tenant_document_upload', ['tenantSlug' => $tenant->getSlug()]);
            }

            if (empty($name)) {
                $name = $uploadedFile->getClientOriginalName() ?? 'Untitled Document';
            }

            try {
                $document = $this->storageService->uploadFile(
                    file: $uploadedFile,
                    name: $name,
                    description: $description ?: null
                );

                $this->addFlash('success', sprintf(
                    'Document "%s" uploaded successfully! File size: %s',
                    $document->getName(),
                    $document->getFormattedFileSize()
                ));

                return $this->redirectToRoute('tenant_document_show', [
                    'tenantSlug' => $tenant->getSlug(),
                    'id' => $document->getId(),
                ]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to upload file: ' . $e->getMessage());
            }
        }

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('document/upload.html.twig', [
            'tenant' => $tenant,
            ...$notificationData,
        ]);
    }

    /**
     * Show document details.
     */
    #[Route('/{id}', name: 'tenant_document_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Document $document): Response
    {
        $tenant = $this->tenantContext->getTenant();

        // Verify document belongs to current tenant (should be automatic with TenantAware)
        if ($document->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Document not found.');
        }

        $fileExists = $this->storageService->fileExists($document);

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('document/show.html.twig', [
            'tenant' => $tenant,
            'document' => $document,
            'file_exists' => $fileExists,
            ...$notificationData,
        ]);
    }

    /**
     * Download a document file.
     */
    #[Route('/{id}/download', name: 'tenant_document_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(Document $document): Response
    {
        $tenant = $this->tenantContext->getTenant();

        // Verify document belongs to current tenant
        if ($document->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Document not found.');
        }

        try {
            $fileContent = $this->storageService->getFileContent($document);

            $response = new Response($fileContent);
            $response->headers->set('Content-Type', $document->getMimeType());
            $response->headers->set('Content-Length', (string) $document->getFileSize());
            
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $document->getOriginalFilename()
            );
            $response->headers->set('Content-Disposition', $disposition);

            return $response;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to download file: ' . $e->getMessage());
            return $this->redirectToRoute('tenant_document_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $document->getId(),
            ]);
        }
    }

    /**
     * Delete a document.
     */
    #[Route('/{id}/delete', name: 'tenant_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Document $document, Request $request): Response
    {
        $tenant = $this->tenantContext->getTenant();

        // Verify document belongs to current tenant
        if ($document->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Document not found.');
        }

        // CSRF protection
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_document_' . $document->getId(), $token)) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('tenant_document_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $document->getId(),
            ]);
        }

        try {
            $documentName = $document->getName();
            $this->storageService->deleteFile($document);

            $this->addFlash('success', sprintf('Document "%s" deleted successfully.', $documentName));

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete document: ' . $e->getMessage());
            return $this->redirectToRoute('tenant_document_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $document->getId(),
            ]);
        }

        return $this->redirectToRoute('tenant_document_index', [
            'tenantSlug' => $tenant->getSlug(),
        ]);
    }

    /**
     * Test storage functionality with sample files.
     */
    #[Route('/test/storage', name: 'tenant_document_test_storage', methods: ['POST'])]
    public function testStorage(): Response
    {
        $tenant = $this->tenantContext->getTenant();

        try {
            // Create a test file
            $testContent = "This is a test file for tenant: {$tenant->getName()}\n";
            $testContent .= "Created at: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "\n";
            $testContent .= "Tenant slug: {$tenant->getSlug()}\n";
            $testContent .= "This file demonstrates tenant-isolated storage functionality.\n";

            $tempFile = tempnam(sys_get_temp_dir(), 'tenant_test_');
            file_put_contents($tempFile, $testContent);

            $uploadedFile = new UploadedFile(
                path: $tempFile,
                originalName: 'tenant_storage_test.txt',
                mimeType: 'text/plain',
                test: true
            );

            $document = $this->storageService->uploadFile(
                file: $uploadedFile,
                name: 'Storage Test File',
                description: 'This is a test file created to verify tenant-isolated storage functionality.'
            );

            $this->addFlash('success', sprintf(
                'Test file created successfully! Document ID: %d, File size: %s',
                $document->getId(),
                $document->getFormattedFileSize()
            ));

            return $this->redirectToRoute('tenant_document_show', [
                'tenantSlug' => $tenant->getSlug(),
                'id' => $document->getId(),
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create test file: ' . $e->getMessage());
        }

        return $this->redirectToRoute('tenant_document_index', [
            'tenantSlug' => $tenant->getSlug(),
        ]);
    }
}