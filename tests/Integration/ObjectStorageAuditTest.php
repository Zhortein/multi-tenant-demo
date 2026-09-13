<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\ObjectStorage\ObjectStorageTestCase;
use App\Tests\Fixtures\ObjectStorage\StorageIoProbe;
use Composer\InstalledVersions;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Zhortein\MultiTenantBundle\ObjectStorage\BackendObjectPage;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\AuditableFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageProviderSelector;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\LogicalObjectIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectObservation;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectObservationState;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocation;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorage;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageAuditInterface;

final class ObjectStorageAuditTest extends ObjectStorageTestCase
{
    private TenantObjectStorageAuditInterface $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = self::service(TenantObjectStorageAuditInterface::class);
    }

    public function testPublicInventoryIsLazyAndTenantFilteredIncludingHistoricalGenerations(): void
    {
        self::assertSame('v1.0.0-rc.12', InstalledVersions::getPrettyVersion('zhortein/multi-tenant-bundle'));
        $constructed = StorageIoProbe::$constructions;
        $this->context->setTenant($this->tenantA);
        $first = $this->audit->inventoryLocations(1);
        self::assertCount(1, $first->locations);
        self::assertSame('dedicated_v1', $first->locations[0]->locationId);
        self::assertTrue(null !== $first->nextCursor);
        $second = $this->audit->inventoryLocations(1, $first->nextCursor);
        self::assertSame('shared_v1', $second->locations[0]->locationId);
        self::assertTrue($second->locations[0]->active);
        $third = $this->audit->inventoryLocations(1, $second->nextCursor);
        self::assertSame('shared_v2', $third->locations[0]->locationId);
        self::assertSame('shared', $third->locations[0]->provider);
        self::assertFalse($third->locations[0]->active);
        self::assertNull($third->nextCursor);
        foreach ([$first, $second, $third] as $page) {
            self::assertTrue($page->locations[0]->auditListing);
            self::assertTrue($page->locations[0]->identityObservation);
        }
        $this->context->setTenant($this->tenantB);
        $b = $this->audit->inventoryLocations();
        self::assertSame(['shared_v1', 'shared_v2'], array_column($b->locations, 'locationId'));
        $this->context->setTenant($this->tenantA);
        self::assertSame(3, count($this->registry->inventory($this->tenantA)->locations));
        self::assertSame($constructed, StorageIoProbe::$constructions, 'Inventory must not construct any S3 backend or client.');
        // Check errors only after measuring laziness: rejected() itself resolves I/O counters.
        $this->context->setTenant($this->tenantB);
        $this->rejected(ObjectStorageError::INVALID_REFERENCE, fn () => $this->audit->inventoryLocations(1, $first->nextCursor));
        $this->rejected(ObjectStorageError::TENANT_NOT_ALLOWED, fn () => $this->audit->auditScope('dedicated_v1'));
    }

    public function testNewIdentityAndHistoricalV1ReferenceRemainDistinctAfterReboot(): void
    {
        $legacy = $this->allocate($this->tenantA);
        $this->storage->write($legacy, 'historical payload'); // Unchanged RC11 write, no metadata backfill.
        $json = $legacy->toJson();
        $new = $this->allocate($this->tenantA);
        $identity = LogicalObjectIdentity::forReference($new, bin2hex(random_bytes(32)));
        $this->audit->writeWithIdentity($new, 'new payload', $identity);
        $observed = $this->audit->observe($new);
        self::assertSame(ObjectObservationState::VERIFIED, $observed->state);
        self::assertTrue($observed->identity?->matchesReference($new));
        self::assertTrue($observed->identity->sameLogicalObject($identity));
        self::assertSame('dedicated_v1', $observed->identity->generation);
        self::assertSame('new payload', $this->storage->read($new));
        $this->resetServices();
        $this->rejected(ObjectStorageError::MISSING_CONTEXT, fn () => $this->audit->observe($new));
        self::ensureKernelShutdown();
        parent::setUp();
        $this->audit = self::service(TenantObjectStorageAuditInterface::class);
        self::assertNull($this->context->getTenant());
        $this->context->setTenant($this->tenantA);
        $restored = StoredObjectReference::fromJson($json);
        self::assertTrue($legacy->equals($restored));
        self::assertTrue($json === $restored->toJson());
        self::assertSame('historical payload', $this->storage->read($restored));
        $historical = $this->audit->observe($restored);
        self::assertSame(ObjectObservationState::IDENTITY_ABSENT, $historical->state);
        self::assertNull($historical->identity);
        self::assertTrue($historical->reference?->equals($legacy));
        self::assertSame(ObjectObservationState::VERIFIED, $this->audit->observe($new)->state);
    }

    public function testPaginatedObservationsAndCursorsStayInsideTenantAcrossAThenBThenA(): void
    {
        // shared_v2 is a registered historical location, with no need for old application records.
        $writer = $this->facade('shared_v2');
        $a1 = $this->allocate($this->tenantA, $writer);
        $a2 = $this->allocate($this->tenantA, $writer);
        $this->audit->writeWithIdentity($a1, 'A identified', LogicalObjectIdentity::forReference($a1, bin2hex(random_bytes(32))));
        $writer->write($a2, 'A historical');
        $scopeA = $this->audit->auditScope('shared_v2');
        $page = $this->audit->auditList($scopeA, 1);
        self::assertCount(1, $page->observations);
        self::assertTrue(null !== $page->nextCursor);
        $cursor = $page->nextCursor;
        $this->rejected(ObjectStorageError::INVALID_REFERENCE, fn () => $this->audit->auditList($scopeA, 1, 'invalid-cursor'));
        $this->rejected(ObjectStorageError::INVALID_REFERENCE, fn () => $this->audit->auditList($scopeA, 2, $cursor));
        $b = $this->allocate($this->tenantB, $writer);
        $this->audit->writeWithIdentity($b, 'B identified', LogicalObjectIdentity::forReference($b, bin2hex(random_bytes(32))));
        $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->audit->observe($a1));
        $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->audit->auditList($scopeA, 1, $cursor));
        $scopeB = $this->audit->auditScope('shared_v2');
        $this->rejected(ObjectStorageError::INVALID_REFERENCE, fn () => $this->audit->auditList($scopeB, 1, $cursor));
        $pageB = $this->audit->auditList($scopeB, 1);
        self::assertTrue($pageB->observations[0]->reference?->equals($b));
        self::assertNull($pageB->nextCursor);
        $this->context->setTenant($this->tenantA);
        $next = $this->audit->auditList($scopeA, 1, $cursor);
        self::assertCount(1, $next->observations);
        self::assertNull($next->nextCursor);
        $observations = [...$page->observations, ...$next->observations];
        foreach ([$a1, $a2] as $reference) {
            self::assertCount(1, array_filter($observations, static fn (ObjectObservation $item): bool => $item->reference?->equals($reference) ?? false));
        }
        $this->resetServices();
        $this->rejected(ObjectStorageError::MISSING_CONTEXT, fn () => $this->audit->auditList($scopeA, 1, $cursor));
        $this->context->setTenant($this->tenantA);
        self::assertCount(1, $this->audit->auditList($this->audit->auditScope('shared_v2'), 1, $cursor)->observations);
    }

    public function testInvalidAndForeignIdentityWritesFailBeforeIo(): void
    {
        $a = $this->allocate($this->tenantA);
        $identityA = LogicalObjectIdentity::forReference($a, bin2hex(random_bytes(32)));
        $b = $this->allocate($this->tenantB);
        $this->rejected(ObjectStorageError::INVALID_REFERENCE, fn () => LogicalObjectIdentity::forReference($b, 'original-filename.txt'));
        $this->rejected(ObjectStorageError::INVALID_ARGUMENT, fn () => LogicalObjectIdentity::fromArray([...$identityA->toArray(), 'version' => 2]));
        $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->audit->writeWithIdentity($b, 'denied', $identityA));
        self::assertFalse($this->storage->exists($b));
    }

    public function testRealForeignAndInvalidMetadataAreSanitizedAndPagingCanContinue(): void
    {
        $foreign = $this->allocate($this->tenantB);
        $claim = LogicalObjectIdentity::forReference($foreign, bin2hex(random_bytes(32)));
        $codec = self::getContainer()->get('app.object_storage.audit_codec');
        self::assertTrue($codec instanceof ObjectStorageAuditCodec);
        $envelope = $codec->seal($claim->toArray(), 'identity');
        $a = $this->allocate($this->tenantA);
        $probe = $this->probe($a->locationId);
        // The trusted fixture replaces only the metadata of this newly allocated object.
        // Its address is still selected by the facade; the anomaly is stored in real MinIO.
        $probe->withFixtureEnvelope($envelope, fn () => $this->audit->writeWithIdentity($a, 'synthetic foreign claim', LogicalObjectIdentity::forReference($a, bin2hex(random_bytes(32)))));
        $invalid = $this->allocate($this->tenantA);
        $probe->withFixtureEnvelope('invalid-envelope', fn () => $this->audit->writeWithIdentity($invalid, 'synthetic invalid claim', LogicalObjectIdentity::forReference($invalid, bin2hex(random_bytes(32)))));
        $observed = $this->audit->observe($a);
        self::assertSame(ObjectObservationState::FOREIGN, $observed->state);
        self::assertNull($observed->reference);
        self::assertNull($observed->identity);
        self::assertNull($observed->metadata);
        self::assertTrue(1 === preg_match('/\A[0-9a-f]{64}\z/', $observed->observationId));
        $encoded = json_encode($observed, JSON_THROW_ON_ERROR);
        foreach ([$foreign->key, $foreign->tenantNamespace, $foreign->locationBinding, $claim->correlationId, $envelope] as $private) {
            self::assertFalse(str_contains($encoded, $private), 'Foreign observation must expose no address or claim.');
        }
        $bad = $this->audit->observe($invalid);
        self::assertSame(ObjectObservationState::IDENTITY_INVALID, $bad->state);
        self::assertNull($bad->identity);
        $scope = $this->audit->auditScope($a->locationId);
        $first = $this->audit->auditList($scope, 1);
        self::assertTrue(null !== $first->nextCursor);
        $second = $this->audit->auditList($scope, 1, $first->nextCursor);
        self::assertNull($second->nextCursor);
        $states = [$first->observations[0]->state->value, $second->observations[0]->state->value];
        sort($states);
        self::assertSame(['foreign', 'identity_invalid'], $states);
        $keyPath = getenv('OBJECT_AUDIT_KEY_FILE');
        self::assertIsString($keyPath);
        $auditKey = file_get_contents($keyPath);
        self::assertIsString($auditKey);
        self::assertSame(64, strlen($auditKey));
        $logs = self::getContainer()->getParameter('kernel.logs_dir');
        $cache = self::getContainer()->getParameter('kernel.cache_dir');
        self::assertIsString($logs);
        self::assertIsString($cache);
        $this->assertFilesDoNotContain($logs, [$a->key, $foreign->key, $foreign->tenantNamespace, $envelope, $auditKey]);
        $this->assertFilesDoNotContain($cache, [$auditKey]);
    }

    public function testWholePageAndInFlightResetFailClosedBeforeAnyObservation(): void
    {
        $a = $this->allocate($this->tenantA);
        $this->storage->write($a, 'real page member');
        $scope = $this->audit->auditScope($a->locationId);
        $probe = $this->probe($a->locationId);
        $before = $probe->observations;
        // Model a broken adapter response after an actual LIST, never a production fault switch.
        $probe->afterAuditList = static fn (BackendObjectPage $page): BackendObjectPage => new BackendObjectPage([...$page->keys, 'foreign-physical-key']);
        try {
            $this->rejected(ObjectStorageError::BACKEND_FAILURE, fn () => $this->audit->auditList($scope, 10), false);
            self::assertSame($before, $probe->observations, 'No partial page or per-entry HEAD may escape a broken tenant boundary.');
            $probe->afterAuditList = function (BackendObjectPage $page): BackendObjectPage {
                $this->resetServices();

                return $page;
            };
            // After I/O, the public contract reports uncertain completion as backend_failure.
            $this->rejected(ObjectStorageError::BACKEND_FAILURE, fn () => $this->audit->auditList($scope, 10), false);
            self::assertNull($this->context->getTenant());
            self::assertSame($before, $probe->observations);
        } finally {
            $probe->afterAuditList = null;
        }
        $this->context->setTenant($this->tenantA);
        self::assertCount(1, $this->audit->auditList($this->audit->auditScope($a->locationId), 10)->observations);
    }

    public function testActualUnavailableS3PageIsAnErrorAndNeverFallsBack(): void
    {
        $this->context->setTenant($this->tenantB);
        $config = self::getContainer()->get('app.object_storage.shared_v1.configuration');
        self::assertTrue($config instanceof S3LocationConfiguration);
        $missing = new S3LocationConfiguration($config->endpoint, 'demo-rc12-missing-'.bin2hex(random_bytes(8)), signingEndpoint: $config->signingEndpoint);
        $backend = S3CompatibleStorageFactory::create($missing, (string) getenv('OBJECT_ACCESS_KEY'), (string) getenv('OBJECT_SECRET_KEY'), caBundle: (string) getenv('OBJECT_CA_BUNDLE'), audit: true);
        self::assertTrue($backend instanceof AuditableFlysystemBackend);
        $probe = new StorageIoProbe($backend);
        $registry = new ObjectStorageRegistry([new StorageLocation('missing_v1', $probe, $probe, ['*'])], ['shared' => 'missing_v1']);
        $codec = self::getContainer()->get('app.object_storage.audit_codec');
        self::assertTrue($codec instanceof ObjectStorageAuditCodec);
        $audit = new TenantObjectStorage($this->context, new ConfiguredTenantStorageProviderSelector('shared'), $this->namespaces, $registry, auditCodec: $codec);
        $scope = $audit->auditScope('missing_v1');
        $calls = $this->ioCalls();
        $this->rejected(ObjectStorageError::BACKEND_FAILURE, fn () => $audit->auditList($scope, 1), false);
        self::assertSame(1, $probe->calls);
        self::assertSame(0, $probe->observations);
        self::assertSame($calls, $this->ioCalls(), 'No other registered backend may be used.');
    }

    private function resetServices(): void
    {
        $resetter = self::getContainer()->get('services_resetter');
        self::assertTrue($resetter instanceof ServicesResetter);
        $resetter->reset();
        self::assertNull($this->context->getTenant());
    }

    private function probe(string $location): StorageIoProbe
    {
        $probe = $this->registry->location($location)->backend;
        self::assertTrue($probe instanceof StorageIoProbe);

        return $probe;
    }

    /** @param list<string> $privateValues */
    private function assertFilesDoNotContain(string $directory, #[\SensitiveParameter] array $privateValues): void
    {
        $leaked = false;
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $content = file_get_contents($file->getPathname());
                    if (false === $content) {
                        self::fail('Cannot inspect audit diagnostics.');
                    }
                    foreach ($privateValues as $value) {
                        $leaked = $leaked || str_contains($content, $value);
                    }
                }
            }
        }
        self::assertFalse($leaked, 'Diagnostics and compiled configuration must not expose runtime audit secrets.');
    }
}
