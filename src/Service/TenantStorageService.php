<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Service for handling tenant-aware file storage operations.
 * 
 * This service demonstrates how to implement tenant-isolated file storage,
 * ensuring that files uploaded by one tenant cannot be accessed by another
 * tenant, while providing a clean API for file operations.
 */
final readonly class TenantStorageService
{
    private string $uploadsDirectory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private LoggerInterface $logger,
        string $projectDir
    ) {
        $this->uploadsDirectory = $projectDir . '/var/uploads';
    }

    /**
     * Upload a file for the current tenant.
     */
    public function uploadFile(
        UploadedFile $file,
        string $name,
        ?string $description = null,
        ?User $uploadedBy = null
    ): Document {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new \RuntimeException('No tenant context available for file upload');
        }

        // Create tenant-specific directory
        $tenantDir = $this->getTenantDirectory($tenant);
        $filesystem = $this->createFilesystem($tenantDir);

        // Generate unique filename
        $originalFilename = $file->getClientOriginalName() ?? 'unknown';
        $extension = $file->getClientOriginalExtension();
        $filename = $this->generateUniqueFilename($originalFilename, $extension);

        try {
            // Read file content and store it
            $fileContent = file_get_contents($file->getPathname());
            if ($fileContent === false) {
                throw new \RuntimeException('Failed to read uploaded file');
            }

            $filesystem->write($filename, $fileContent);

            // Create document entity
            $document = new Document();
            $document->setName($name);
            $document->setOriginalFilename($originalFilename);
            $document->setFilePath($filename);
            $document->setMimeType($file->getMimeType() ?? 'application/octet-stream');
            $document->setFileSize($file->getSize());
            $document->setDescription($description);
            $document->setTenant($tenant);
            $document->setUploadedBy($uploadedBy);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->logger->info('File uploaded successfully', [
                'document_id' => $document->getId(),
                'filename' => $filename,
                'tenant_slug' => $tenant->getSlug(),
                'file_size' => $file->getSize(),
            ]);

            return $document;

        } catch (\Exception $e) {
            $this->logger->error('Failed to upload file', [
                'filename' => $originalFilename,
                'tenant_slug' => $tenant->getSlug(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to upload file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get file content for a document.
     */
    public function getFileContent(Document $document): string
    {
        $tenant = $document->getTenant();
        $tenantDir = $this->getTenantDirectory($tenant);
        $filesystem = $this->createFilesystem($tenantDir);

        try {
            return $filesystem->read($document->getFilePath());
        } catch (\Exception $e) {
            $this->logger->error('Failed to read file', [
                'document_id' => $document->getId(),
                'file_path' => $document->getFilePath(),
                'tenant_slug' => $tenant->getSlug(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to read file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a file and its document record.
     */
    public function deleteFile(Document $document): void
    {
        $tenant = $document->getTenant();
        $tenantDir = $this->getTenantDirectory($tenant);
        $filesystem = $this->createFilesystem($tenantDir);

        try {
            // Delete physical file
            if ($filesystem->fileExists($document->getFilePath())) {
                $filesystem->delete($document->getFilePath());
            }

            // Delete document record
            $this->entityManager->remove($document);
            $this->entityManager->flush();

            $this->logger->info('File deleted successfully', [
                'document_id' => $document->getId(),
                'file_path' => $document->getFilePath(),
                'tenant_slug' => $tenant->getSlug(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete file', [
                'document_id' => $document->getId(),
                'file_path' => $document->getFilePath(),
                'tenant_slug' => $tenant->getSlug(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to delete file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the full file path for a document.
     */
    public function getFilePath(Document $document): string
    {
        $tenant = $document->getTenant();
        $tenantDir = $this->getTenantDirectory($tenant);
        
        return $tenantDir . '/' . $document->getFilePath();
    }

    /**
     * Check if a file exists for a document.
     */
    public function fileExists(Document $document): bool
    {
        $tenant = $document->getTenant();
        $tenantDir = $this->getTenantDirectory($tenant);
        $filesystem = $this->createFilesystem($tenantDir);

        return $filesystem->fileExists($document->getFilePath());
    }

    /**
     * Get tenant-specific directory path.
     */
    private function getTenantDirectory(Tenant $tenant): string
    {
        return $this->uploadsDirectory . '/tenant_' . $tenant->getSlug();
    }

    /**
     * Create filesystem instance for a directory.
     */
    private function createFilesystem(string $directory): Filesystem
    {
        // Ensure directory exists
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $adapter = new LocalFilesystemAdapter($directory);
        return new Filesystem($adapter);
    }

    /**
     * Generate a unique filename to avoid conflicts.
     */
    private function generateUniqueFilename(string $originalFilename, string $extension): string
    {
        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
        $random = bin2hex(random_bytes(4));
        
        return sprintf('%s_%s_%s.%s', $basename, $timestamp, $random, $extension);
    }

    /**
     * Get storage statistics for the current tenant.
     * 
     * @return array{total_files: int, total_size: int, formatted_size: string}
     */
    public function getStorageStats(): array
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant instanceof Tenant) {
            return ['total_files' => 0, 'total_size' => 0, 'formatted_size' => '0 B'];
        }

        $documents = $this->entityManager->getRepository(Document::class)
            ->findBy(['tenant' => $tenant, 'active' => true]);

        $totalFiles = count($documents);
        $totalSize = array_sum(array_map(fn(Document $doc) => $doc->getFileSize(), $documents));

        return [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'formatted_size' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * Format bytes into human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}