<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\AuditListingBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendIdentityObservation;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\AuditableFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\SigningFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectIdentityBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectMetadata;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\PhysicalStorageIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocationBindingInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

/** Counts public adapter calls; never records keys, content, URLs or diagnostics. */
final class StorageIoProbe implements ObjectStorageBackendInterface, StorageLocationBindingInterface, TemporaryObjectUrlBackendInterface, AuditListingBackendInterface, ObjectIdentityBackendInterface
{
    public static int $constructions = 0;
    public int $calls = 0;
    public int $observations = 0;
    /** @var ?\Closure(BackendObjectPage): BackendObjectPage Test-only fault after a real MinIO LIST. */
    public ?\Closure $afterAuditList = null;
    private ?string $fixtureEnvelope = null;

    public function __construct(private readonly SigningFlysystemBackend|AuditableFlysystemBackend $inner)
    {
        ++self::$constructions;
    }

    public function auditList(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        ++$this->calls;
        $page = $this->auditable()->auditList($tenantPrefix, $limit, $afterKey);

        return null === $this->afterAuditList ? $page : ($this->afterAuditList)($page);
    }

    public function observeIdentity(string $qualifiedKey): BackendIdentityObservation
    {
        ++$this->calls;
        ++$this->observations;

        return $this->auditable()->observeIdentity($qualifiedKey);
    }

    public function writeWithIdentity(string $qualifiedKey, string $content, string $envelope): void
    {
        ++$this->calls;
        $this->auditable()->writeWithIdentity($qualifiedKey, $content, $this->fixtureEnvelope ?? $envelope);
    }

    public function writeFromStreamWithIdentity(string $qualifiedKey, ObjectStreamSourceInterface $source, string $envelope): void
    {
        ++$this->calls;
        $this->auditable()->writeFromStreamWithIdentity($qualifiedKey, $source, $envelope);
    }

    /** @param \Closure(): void $write Writes anomalous metadata to a newly allocated test object only. */
    public function withFixtureEnvelope(#[\SensitiveParameter] string $envelope, \Closure $write): void
    {
        $this->fixtureEnvelope = $envelope;
        try {
            $write();
        } finally {
            $this->fixtureEnvelope = null;
        }
    }

    private function auditable(): AuditableFlysystemBackend
    {
        return $this->inner instanceof AuditableFlysystemBackend ? $this->inner : throw new \LogicException('Audit fixture requires an auditable backend.');
    }

    public function identity(ObjectStorageBackendInterface $backend): PhysicalStorageIdentity
    {
        if ($backend !== $this) {
            throw new \LogicException('Unexpected probe binding.');
        }

        return $this->inner->identity($this->inner);
    }

    public function write(string $qualifiedKey, string $content): void
    {
        ++$this->calls;
        $this->inner->write($qualifiedKey, $content);
    }

    public function writeFromStream(string $qualifiedKey, ObjectStreamSourceInterface $source): void
    {
        ++$this->calls;
        $this->inner->writeFromStream($qualifiedKey, $source);
    }

    public function read(string $qualifiedKey): string
    {
        ++$this->calls;

        return $this->inner->read($qualifiedKey);
    }

    public function readToStream(string $qualifiedKey, ObjectStreamDestinationInterface $destination): void
    {
        ++$this->calls;
        $this->inner->readToStream($qualifiedKey, $destination);
    }

    public function exists(string $qualifiedKey): bool
    {
        ++$this->calls;

        return $this->inner->exists($qualifiedKey);
    }

    public function metadata(string $qualifiedKey): ObjectMetadata
    {
        ++$this->calls;

        return $this->inner->metadata($qualifiedKey);
    }

    public function list(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage
    {
        ++$this->calls;

        return $this->inner->list($tenantPrefix, $limit, $afterKey);
    }

    public function copy(string $sourceKey, string $destinationKey): void
    {
        ++$this->calls;
        $this->inner->copy($sourceKey, $destinationKey);
    }

    public function move(string $sourceKey, string $destinationKey): void
    {
        ++$this->calls;
        $this->inner->move($sourceKey, $destinationKey);
    }

    public function delete(string $qualifiedKey): void
    {
        ++$this->calls;
        $this->inner->delete($qualifiedKey);
    }

    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl
    {
        ++$this->calls;

        return $this->inner->temporaryUrl($qualifiedKey, $expiresAt);
    }
}
