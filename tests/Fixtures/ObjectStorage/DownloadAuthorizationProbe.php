<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ObjectStorage;

use App\Entity\User;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;

/** Explicit demo authorization, not a permanent document permission model. */
final readonly class DownloadAuthorizationProbe
{
    public function __construct(
        private TenantContextInterface $context,
        private ReferencePersistenceProbe $references,
        private TenantObjectStorageInterface $storage,
    ) {
    }

    public function download(User $authenticatedUser, string $fixtureId, int $ttl): TemporaryObjectUrl
    {
        $tenant = $this->context->getTenant() ?? throw new \LogicException('Download access denied.');
        $member = false;
        foreach ($authenticatedUser->getMemberships() as $membership) {
            if ($membership->isActive() && $membership->getTenant()->getId() === $tenant->getId()) {
                $member = true;
            }
        }
        if (!$authenticatedUser->isActive() || !$member) {
            throw new \LogicException('Download access denied.');
        }

        // The lookup is tenant-scoped and precedes the capability-producing call.
        return $this->storage->temporaryUrl($this->references->load($fixtureId), $ttl);
    }
}
