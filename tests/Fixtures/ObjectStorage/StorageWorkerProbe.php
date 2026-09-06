<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ObjectStorage;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;

#[AsMessageHandler]
final class StorageWorkerProbe
{
    /** @var list<array{tenant: string, digest: string}> */
    public array $reads = [];
    /** @var list<StoredObjectReference> */
    public array $outgoing = [];
    public bool $allReferencesRestored = true;

    public function __construct(private readonly TenantContextInterface $context, private readonly TenantObjectStorageInterface $storage)
    {
    }

    public function __invoke(ReadStoredObjectMessage $message): void
    {
        foreach ($this->outgoing as $reference) {
            if ($message->reference === $reference) {
                $this->allReferencesRestored = false;
            }
        }
        // Revalidation happens in the facade under the worker's restored tenant.
        $content = $this->storage->read($message->reference);
        $tenant = $this->context->getTenant() ?? throw new \LogicException('Missing worker tenant.');
        $this->reads[] = ['tenant' => (string) $tenant->getId(), 'digest' => hash('sha256', $content)];
        if ($message->failAfterRead) {
            throw new \RuntimeException('Controlled object storage worker failure.');
        }
    }
}
