<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;

/**
 * Demonstrates the bundle storage contract as a real downstream consumer.
 */
final readonly class TenantStorageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private TenantFileStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function uploadFile(
        UploadedFile $file,
        string $name,
        ?string $description = null,
        ?User $uploadedBy = null,
    ): Document {
        $tenant = $this->activeTenant();
        $originalFilename = $file->getClientOriginalName() ?: 'unknown';
        $extension = $file->getClientOriginalExtension();
        $filename = $this->generateUniqueFilename($originalFilename, $extension);
        $relativePath = 'documents/'.$filename;

        try {
            $this->storage->uploadFile($file, $relativePath);

            $document = (new Document())
                ->setName($name)
                ->setOriginalFilename($originalFilename)
                ->setFilePath($relativePath)
                ->setMimeType($file->getMimeType() ?? 'application/octet-stream')
                ->setFileSize($file->getSize())
                ->setDescription($description)
                ->setTenant($tenant)
                ->setUploadedBy($uploadedBy);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->logger->info('File uploaded successfully', [
                'document_id' => $document->getId(),
                'tenant_id' => (string) $tenant->getId(),
                'file_size' => $file->getSize(),
            ]);

            return $document;
        } catch (\Throwable $exception) {
            $this->logger->error('Tenant file upload failed', [
                'tenant_id' => (string) $tenant->getId(),
            ]);

            throw new \RuntimeException('Failed to upload tenant file.', 0, $exception);
        }
    }

    public function getFileContent(Document $document): string
    {
        $this->assertDocumentBelongsToActiveTenant($document);
        $content = file_get_contents($this->storage->getPath($document->getFilePath()));

        if (false === $content) {
            throw new \RuntimeException('Failed to read tenant file.');
        }

        return $content;
    }

    public function deleteFile(Document $document): void
    {
        $this->assertDocumentBelongsToActiveTenant($document);

        $this->storage->delete($document->getFilePath());
        $this->entityManager->remove($document);
        $this->entityManager->flush();

        $this->logger->info('File deleted successfully', [
            'document_id' => $document->getId(),
            'tenant_id' => (string) $document->getTenant()->getId(),
        ]);
    }

    public function getFilePath(Document $document): string
    {
        $this->assertDocumentBelongsToActiveTenant($document);

        return $this->storage->getPath($document->getFilePath());
    }

    public function fileExists(Document $document): bool
    {
        $this->assertDocumentBelongsToActiveTenant($document);

        return $this->storage->exists($document->getFilePath());
    }

    /**
     * @return array{total_files: int, total_size: int, formatted_size: string}
     */
    public function getStorageStats(): array
    {
        $tenant = $this->activeTenant();
        $documents = $this->entityManager->getRepository(Document::class)
            ->findBy(['tenant' => $tenant, 'active' => true]);

        $totalSize = array_sum(array_map(
            static fn (Document $document): int => $document->getFileSize(),
            $documents,
        ));

        return [
            'total_files' => count($documents),
            'total_size' => $totalSize,
            'formatted_size' => $this->formatBytes($totalSize),
        ];
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();

        if (!$tenant instanceof Tenant) {
            throw new TenantStorageException('Tenant storage requires an active application tenant.');
        }

        return $tenant;
    }

    private function assertDocumentBelongsToActiveTenant(Document $document): void
    {
        $activeTenant = $this->activeTenant();
        $documentTenant = $document->getTenant();
        $activeId = (string) $activeTenant->getId();
        $documentId = (string) $documentTenant->getId();

        if ($activeTenant !== $documentTenant && ('0' === $activeId || $activeId !== $documentId)) {
            throw new TenantStorageException('Cross-tenant document storage access is denied.');
        }
    }

    private function generateUniqueFilename(string $originalFilename, string $extension): string
    {
        $basename = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalFilename, PATHINFO_FILENAME));
        $suffix = '' !== $extension ? '.'.$extension : '';

        return sprintf(
            '%s_%s_%s%s',
            $basename,
            (new \DateTimeImmutable())->format('Y-m-d_H-i-s'),
            bin2hex(random_bytes(4)),
            $suffix,
        );
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value > 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return round($value, 2).' '.$units[$unit];
    }
}
