<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Decorator\TenantCacheException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;

final class PersistentTenantLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(
            new ArrayInput(['command' => 'app:create-sample-data']),
            new BufferedOutput(),
        ));

        $this->tenantContext = static::getContainer()->get(TenantContextInterface::class);
        self::assertNull($this->tenantContext->getTenant());

        $admin = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin);
    }

    public function testSameKernelRequestsAlwaysEndAtNoneAfterNullResolutionAndControllerFailure(): void
    {
        $this->client->request('GET', '/tenant-a');
        self::assertResponseIsSuccessful();
        self::assertNull($this->tenantContext->getTenant());

        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/');
        self::assertNull($this->tenantContext->getTenant());

        $this->client->request('GET', '/tenant-a');
        self::assertResponseIsSuccessful();
        self::assertNull($this->tenantContext->getTenant());

        $this->client->request('GET', '/tenant-b');
        self::assertResponseIsSuccessful();
        self::assertNull($this->tenantContext->getTenant());

        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/');
        self::assertNull($this->tenantContext->getTenant());

        $this->client->request('GET', '/tenant-a/products/999999999');
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->tenantContext->getTenant());

        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/');
        self::assertNull($this->tenantContext->getTenant());
    }

    public function testRealServicesResetterHandlesInitializedCacheAndIsIdempotent(): void
    {
        $cache = static::getContainer()->get(CacheItemPoolInterface::class);
        $this->tenantContext->setTenant($this->tenant('tenant-a'));
        self::assertTrue($cache->save($cache->getItem('persistent-boundary')->set('tenant-a')));

        $resetter = static::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        $resetter->reset();

        self::assertNull($this->tenantContext->getTenant());

        try {
            $cache->getItem('persistent-boundary');
            self::fail('The tenant-aware cache accepted a key after reset without a tenant.');
        } catch (TenantCacheException) {
        }

        $this->expectException(MissingTenantContextException::class);
        $this->entityManager()->getRepository(Product::class)->findAll();
    }

    public function testReusedConsoleApplicationStartsAndEndsEveryCommandAtNone(): void
    {
        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);

        $this->tenantContext->setTenant($this->tenant('tenant-a'));
        self::assertSame(0, $this->runCommand($application, ['command' => 'about']));
        self::assertNull($this->tenantContext->getTenant());

        self::assertSame(0, $this->runCommand($application, [
            'command' => 'app:test-tenant-features',
            'tenant-slug' => 'tenant-a',
            '--skip-storage' => true,
            '--skip-notifications' => true,
        ]));
        self::assertNull($this->tenantContext->getTenant());

        self::assertNotSame(0, $this->runCommand($application, ['command' => 'app:test-tenant-features']));
        self::assertNull($this->tenantContext->getTenant());

        self::assertSame(0, $this->runCommand($application, [
            'command' => 'app:test-tenant-features',
            'tenant-slug' => 'tenant-b',
            '--skip-storage' => true,
            '--skip-notifications' => true,
        ]));
        self::assertNull($this->tenantContext->getTenant());

        self::assertSame(0, $this->runCommand($application, ['command' => 'about']));
        self::assertNull($this->tenantContext->getTenant());
    }

    /** @param array<string, mixed> $input */
    private function runCommand(Application $application, array $input): int
    {
        return $application->run(new ArrayInput($input), new BufferedOutput());
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = $this->entityManager()->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
