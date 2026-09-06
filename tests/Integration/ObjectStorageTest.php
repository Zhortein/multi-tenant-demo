<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Tests\Fixtures\ObjectStorage\DownloadAuthorizationProbe;
use App\Tests\Fixtures\ObjectStorage\ObjectStorageTestCase;
use App\Tests\Fixtures\ObjectStorage\StorageIoProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3CompatibleStorageFactory;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\S3LocationConfiguration;
use Zhortein\MultiTenantBundle\ObjectStorage\Bridge\Flysystem\SigningFlysystemBackend;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageRegistry;
use Zhortein\MultiTenantBundle\ObjectStorage\StorageLocation;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;

final class ObjectStorageTest extends ObjectStorageTestCase
{
    public function testConfiguredAllocationAndReusedKernelAreIsolated(): void
    {
        $a = $this->allocate($this->tenantA);
        self::assertSame('dedicated_v1', $a->locationId);
        $this->storage->write($a, 'A payload');
        $b = $this->allocate($this->tenantB);
        self::assertSame('shared_v1', $b->locationId);
        $this->storage->write($b, 'B payload');
        self::assertSame('B payload', $this->storage->read($b));
        $deniedLocation = new StoredObjectReference($a->locationId, $a->locationBinding, $b->tenantNamespace, $a->key);
        $this->rejected(ObjectStorageError::TENANT_NOT_ALLOWED, fn () => $this->storage->read($deniedLocation));
        foreach ([
            fn () => $this->storage->read($a), fn () => $this->storage->write($a, 'forbidden'),
            fn () => $this->storage->exists($a), fn () => $this->storage->metadata($a),
            fn () => $this->storage->list($a, 1), fn () => $this->storage->delete($a),
            fn () => $this->storage->temporaryUrl($a), fn () => $this->storage->copy($b, $a),
            fn () => $this->storage->move($a, $b),
        ] as $operation) {
            $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, $operation);
        }
        $this->context->clear();
        $this->rejected(ObjectStorageError::MISSING_CONTEXT, fn () => $this->storage->allocate());
        $this->rejected(ObjectStorageError::MISSING_CONTEXT, fn () => $this->storage->read($a));
        $this->context->setTenant($this->tenantA);
        self::assertSame('A payload', $this->storage->read($a));
        $slug = $this->tenantA->getSlug();
        try {
            $this->tenantA->setSlug('changed-demo-slug');
            self::assertSame('A payload', $this->storage->read($a));
            self::assertTrue($a->tenantNamespace === $this->storage->allocate()->tenantNamespace);
        } finally {
            $this->tenantA->setSlug($slug);
        }
        $resetter = self::getContainer()->get('services_resetter');
        self::assertTrue($resetter instanceof ServicesResetter);
        $resetter->reset();
        self::assertNull($this->context->getTenant());
    }

    public function testIdenticalLogicalSuffixInOnePhysicalLocationAndBoundedListing(): void
    {
        $shared = $this->facade();
        $a = $this->allocate($this->tenantA, $shared);
        $shared->write($a, 'A same logical filename');
        $bAllocated = $this->allocate($this->tenantB, $shared);
        // The generic API never accepts an original filename. Force an identical
        // technical suffix to prove the namespace boundary even without randomness.
        $b = new StoredObjectReference($bAllocated->locationId, $bAllocated->locationBinding, $bAllocated->tenantNamespace, $a->key);
        $this->track($this->tenantB, $b);
        $shared->write($b, 'B same logical filename');
        self::assertSame('B same logical filename', $shared->read($b));
        $this->context->setTenant($this->tenantA);
        self::assertSame('A same logical filename', $shared->read($a));
        $extra = $this->allocate($this->tenantA, $shared);
        $shared->write($extra, 'another A object');
        $cursor = null;
        $count = 0;
        do {
            $page = $shared->list($a, 1, $cursor);
            self::assertLessThanOrEqual(1, count($page->references));
            foreach ($page->references as $reference) {
                self::assertTrue($reference->tenantNamespace === $a->tenantNamespace);
                self::assertFalse($reference->equals($b));
                ++$count;
            }
            $cursor = $page->nextCursor;
            self::assertLessThanOrEqual(3, $count, 'Listing must remain bounded.');
        } while (null !== $cursor);
        self::assertSame(2, $count);
        $this->rejected(ObjectStorageError::INVALID_ARGUMENT, fn () => $shared->list($a, 1001));
    }

    public function testStreamsMetadataCopiesMovesAndRepeatedDeletion(): void
    {
        $source = $this->allocate($this->tenantA);
        $content = str_repeat('stream-payload-', 10000);
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        self::assertIsResource($input);
        self::assertIsResource($output);
        try {
            fwrite($input, 'skip'.$content);
            fseek($input, 4);
            $this->storage->writeFromStream($source, $input);
            self::assertTrue(is_resource($input));
            fwrite($output, 'prefix:');
            $this->storage->readToStream($source, $output);
            self::assertTrue(is_resource($output));
            rewind($output);
            self::assertSame('prefix:'.$content, stream_get_contents($output));
            self::assertTrue($this->storage->exists($source));
            self::assertSame(strlen($content), $this->storage->metadata($source)->size);
            self::assertNotNull($this->storage->metadata($source)->lastModified);
            $copy = $this->allocate($this->tenantA);
            $move = $this->allocate($this->tenantA);
            $this->storage->copy($source, $copy);
            $this->storage->move($copy, $move);
            self::assertFalse($this->storage->exists($copy));
            self::assertSame($content, $this->storage->read($move));
            self::assertSame($content, $this->storage->read($source));
            $foreignLocation = $this->allocate($this->tenantA, $this->facade());
            $this->rejected(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->copy($source, $foreignLocation));
            $this->rejected(ObjectStorageError::UNSUPPORTED_OPERATION, fn () => $this->storage->move($source, $foreignLocation));
            $this->context->setTenant($this->tenantB);
            $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->storage->writeFromStream($source, $input));
            $this->rejected(ObjectStorageError::FOREIGN_REFERENCE, fn () => $this->storage->readToStream($source, $output));
            $this->context->setTenant($this->tenantA);
            $this->storage->delete($move);
            $this->storage->delete($move);
            self::assertFalse($this->storage->exists($move));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function testUnknownSelectionLocationAndInconsistentBindingNeverFallback(): void
    {
        $reference = $this->allocate($this->tenantB);
        $this->storage->write($reference, 'retained payload');
        $unknown = $this->facade(provider: 'missing');
        $this->rejected(ObjectStorageError::UNKNOWN_PROVIDER, fn () => $unknown->allocate());
        // Current allocation policy cannot affect an existing reference.
        self::assertSame('retained payload', $unknown->read($reference));
        $invalid = new StoredObjectReference('removed_v1', $reference->locationBinding, $reference->tenantNamespace, $reference->key);
        $this->rejected(ObjectStorageError::UNKNOWN_LOCATION, fn () => $this->storage->read($invalid));
        $invalid = new StoredObjectReference($reference->locationId, str_repeat('0', 64), $reference->tenantNamespace, $reference->key);
        $this->rejected(ObjectStorageError::BINDING_MISMATCH, fn () => $this->storage->read($invalid));

        $config = self::getContainer()->get('app.object_storage.shared_v1.configuration');
        self::assertTrue($config instanceof S3LocationConfiguration);
        $changed = new S3LocationConfiguration($config->endpoint, $config->bucket, 'changed-root', signingEndpoint: $config->signingEndpoint, maxTtl: 60);
        $backend = new StorageIoProbe($this->backend($changed));
        $registry = new ObjectStorageRegistry([new StorageLocation('shared_v1', $backend, $backend, ['*'])], ['shared' => 'shared_v1']);
        $facade = $this->facade(registry: $registry);
        $this->rejected(ObjectStorageError::BINDING_MISMATCH, fn () => $facade->read($reference));
        self::assertSame(0, $backend->calls);
    }

    public function testCredentialRotationRetainsThePhysicalBinding(): void
    {
        $reference = $this->allocate($this->tenantB);
        $this->storage->write($reference, 'before rotation');
        $config = self::getContainer()->get('app.object_storage.shared_v1.configuration');
        self::assertTrue($config instanceof S3LocationConfiguration);
        $rotated = $this->backend($config, true);
        self::assertTrue($reference->locationBinding === $rotated->identity($rotated)->fingerprint());
        $registry = new ObjectStorageRegistry([new StorageLocation('shared_v1', $rotated, $rotated, ['*'])], ['shared' => 'shared_v1']);
        $facade = $this->facade(registry: $registry);
        self::assertSame('before rotation', $facade->read($reference));
        $facade->write($reference, 'after rotation');
        self::assertSame('after rotation', $this->storage->read($reference));
    }

    public function testPersistedReferenceSurvivesProviderGenerationChange(): void
    {
        $reference = $this->allocate($this->tenantB);
        $this->storage->write($reference, 'historical object');
        $persistence = $this->persistence();
        $persistence->save('historical', $reference);
        unset($reference, $persistence);
        $restored = $this->persistence(false)->load('historical');
        $generationTwo = $this->facade('shared_v2');
        $new = $this->allocate($this->tenantB, $generationTwo);
        self::assertSame('shared_v2', $new->locationId);
        $generationTwo->write($new, 'new generation');
        $newBackend = self::getContainer()->get('app.object_storage.probe.shared_v2');
        self::assertTrue($newBackend instanceof StorageIoProbe);
        $calls = $newBackend->calls;
        self::assertSame('shared_v1', $restored->locationId);
        self::assertSame('historical object', $generationTwo->read($restored));
        self::assertSame($calls, $newBackend->calls);
        $this->context->setTenant($this->tenantA);
        $this->assertFixtureDenied(fn () => $this->persistence(false)->load('historical'));
        $this->assertFixtureDenied(fn () => $this->persistence(false)->save('foreign', $restored));
        $this->context->clear();
        $this->assertFixtureDenied(fn () => $this->persistence(false)->load('historical'));
    }

    public function testPrivateDownloadRequiresAuthorizationAndExpiresWithoutUrlRewriting(): void
    {
        $reference = $this->allocate($this->tenantA);
        $this->storage->write($reference, 'authorized download');
        $references = $this->persistence();
        $references->save('download', $reference);
        $users = self::service(EntityManagerInterface::class)->getRepository(User::class);
        $alice = $users->findOneBy(['email' => 'alice@tenant-a.example.test']);
        $bob = $users->findOneBy(['email' => 'bob@tenant-b.example.test']);
        self::assertTrue($alice instanceof User);
        self::assertTrue($bob instanceof User);
        $authorization = new DownloadAuthorizationProbe($this->context, $references, $this->storage);
        $this->assertFixtureDenied(fn () => $authorization->download($bob, 'download', 2));
        $this->rejected(ObjectStorageError::INVALID_ARGUMENT, fn () => $authorization->download($alice, 'download', 61));
        $url = $authorization->download($alice, 'download', 5);
        self::assertTrue(str_starts_with($url->url, (string) getenv('OBJECT_SIGNING_ENDPOINT').'/'));
        [$status, $content] = $this->download($url->url);
        self::assertSame(200, $status);
        self::assertSame('authorized download', $content);
        [$anonymousStatus] = $this->download(explode('?', $url->url, 2)[0]);
        self::assertSame(403, $anonymousStatus, 'The unsigned object must remain private.');
        $this->context->clear();
        [$status] = $this->download($url->url);
        self::assertSame(200, $status, 'A issued capability has its own short lifetime.');
        sleep(6);
        [$expiredStatus] = $this->download($url->url);
        self::assertSame(403, $expiredStatus, 'The server must enforce expiration.');
    }

    public function testUnavailableBucketIsAnErrorNotAbsence(): void
    {
        $this->context->setTenant($this->tenantB);
        $config = self::getContainer()->get('app.object_storage.shared_v1.configuration');
        self::assertTrue($config instanceof S3LocationConfiguration);
        $missing = new S3LocationConfiguration($config->endpoint, 'demo-missing-'.bin2hex(random_bytes(8)), signingEndpoint: $config->signingEndpoint);
        $backend = new StorageIoProbe($this->backend($missing));
        $facade = $this->facade(registry: new ObjectStorageRegistry([new StorageLocation('missing_v1', $backend, $backend, ['*'])], ['shared' => 'missing_v1']));
        $reference = $facade->allocate();
        $calls = $this->ioCalls();
        $this->rejected(ObjectStorageError::BACKEND_FAILURE, fn () => $facade->exists($reference), false);
        self::assertSame(1, $backend->calls);
        self::assertSame($calls, $this->ioCalls(), 'An unavailable target must not fall back.');
    }

    private function backend(S3LocationConfiguration $config, bool $rotated = false): SigningFlysystemBackend
    {
        $prefix = $rotated ? 'OBJECT_ROTATED_' : 'OBJECT_';
        $backend = S3CompatibleStorageFactory::create($config, (string) getenv($prefix.'ACCESS_KEY'), (string) getenv($prefix.'SECRET_KEY'), true, (string) getenv('OBJECT_CA_BUNDLE'));
        self::assertTrue($backend instanceof SigningFlysystemBackend);

        return $backend;
    }

    /** @param \Closure(): mixed $operation */
    private function assertFixtureDenied(\Closure $operation): void
    {
        $calls = $this->ioCalls();
        try {
            $operation();
            self::fail('Application fixture access must be denied.');
        } catch (\LogicException $exception) {
            self::assertNull($exception->getPrevious());
        }
        self::assertSame($calls, $this->ioCalls());
    }

    /** @return array{int, string} */
    private function download(#[\SensitiveParameter] string $url): array
    {
        $handle = curl_init($url);
        if (false === $handle) {
            throw new \RuntimeException('Download fixture initialization failed.');
        }
        try {
            $caBundle = getenv('OBJECT_CA_BUNDLE');
            if (!is_string($caBundle) || '' === $caBundle) {
                throw new \RuntimeException('Download fixture requires a CA bundle.');
            }
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CAINFO => $caBundle, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($handle);
            if (!is_string($body)) {
                throw new \RuntimeException('Download fixture transport failed.');
            }

            return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body];
        } finally {
            unset($handle);
        }
    }
}
