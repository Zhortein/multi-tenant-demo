<?php

declare(strict_types=1);

namespace App\Tests\Isolation;

use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class ProductIsolationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ProductRepository $products;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->runCommand('app:create-sample-data');
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->products = static::getContainer()->get(ProductRepository::class);
        $this->client->loginUser($this->user('alice@tenant-a.example.test'));
    }

    public function testTenantScopedRepositoryListAndDirectAccessRemainIsolatedWithoutDoctrineFilter(): void
    {
        self::assertFalse($this->entityManager->getFilters()->isEnabled('tenant_filter'));

        $tenantA = $this->tenant('tenant-a');
        $tenantAProduct = $this->product('TENANT-A-PRODUCT');
        $tenantBProduct = $this->product('TENANT-B-PRODUCT');

        $tenantAProducts = $this->products->findActiveForTenant($tenantA);
        self::assertContains($tenantAProduct, $tenantAProducts);
        self::assertNotContains($tenantBProduct, $tenantAProducts);
        foreach ($tenantAProducts as $product) {
            self::assertSame('tenant-a', $product->getTenant()?->getSlug());
        }
        self::assertSame($tenantAProduct->getId(), $this->products->findOneForTenant((int) $tenantAProduct->getId(), $tenantA)?->getId());
        self::assertNull($this->products->findOneForTenant((int) $tenantBProduct->getId(), $tenantA));

        $this->client->request('GET', '/tenant-a/products');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $tenantAProduct->getName());
        self::assertSelectorTextNotContains('body', $tenantBProduct->getName());

        $this->client->request('GET', '/tenant-a/products/'.$tenantAProduct->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/tenant-a/products/'.$tenantBProduct->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCrossTenantEditUpdateAndDeleteAreRejected(): void
    {
        $tenantBProduct = $this->product('TENANT-B-PRODUCT');
        $id = $tenantBProduct->getId();

        $this->client->request('GET', '/tenant-a/products/'.$id.'/edit');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/tenant-a/products/'.$id.'/edit', [
            'name' => 'Compromised',
            'sku' => 'COMPROMISED',
            'price' => '1.00',
            'stock_quantity' => 1,
            'tenant_id' => $this->tenant('tenant-a')->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/tenant-a/products/'.$id.'/delete', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        self::assertSame('Tenant B Product', $this->product('TENANT-B-PRODUCT')->getName());
    }

    public function testSameTenantEditUpdateAndDeleteRemainAvailable(): void
    {
        $product = $this->freshProduct('TENANT-A-DELETE');

        $this->client->request('GET', '/tenant-a/products/'.$product->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/tenant-a/products/'.$product->getId().'/edit', [
            'name' => 'Updated Tenant A Product',
            'description' => 'Updated safely',
            'sku' => 'TENANT-A-DELETE',
            'price' => '7.50',
            'stock_quantity' => 3,
            'active' => '1',
        ]);
        self::assertResponseRedirects('/tenant-a/products/'.$product->getId());

        $this->entityManager->clear();
        $updated = $this->product('TENANT-A-DELETE');
        self::assertSame('Updated Tenant A Product', $updated->getName());

        $crawler = $this->client->request('GET', '/tenant-a/products/'.$updated->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Delete Product')->form());
        self::assertResponseRedirects('/tenant-a/products');

        $this->entityManager->clear();
        self::assertNull($this->products->findOneBySkuForTenant('TENANT-A-DELETE', $this->tenant('tenant-a')));
    }

    public function testCreateUsesAuthorizedTenantAndIgnoresInjectedTenantParameters(): void
    {
        $this->removeProduct('TENANT-A-CREATED');
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');

        $this->client->request('POST', '/tenant-a/products/new', [
            'name' => 'Tenant A Created Product',
            'sku' => 'TENANT-A-CREATED',
            'price' => '12.00',
            'stock_quantity' => 4,
            'tenant' => 'tenant-b',
            'tenantSlug' => 'tenant-b',
            'tenant_id' => $tenantB->getId(),
        ]);
        self::assertResponseRedirects('/tenant-a/products');

        $this->entityManager->clear();
        $created = $this->product('TENANT-A-CREATED');
        self::assertSame($tenantA->getSlug(), $created->getTenant()?->getSlug());
    }

    public function testEmailScreenDoesNotEnumerateOtherTenants(): void
    {
        $this->client->request('GET', '/tenant-a/email-test');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Tenant A');
        self::assertSelectorNotExists('a[href^="/tenant-b"]');
    }

    public function testCliRequiresOneExplicitExistingTenantAndNeverSelectsAllTenants(): void
    {
        self::assertNotSame(0, $this->runCommand('app:test-tenant-features', ['tenant-slug' => 'missing', '--skip-storage' => true, '--skip-notifications' => true]));
        self::assertSame(0, $this->runCommand('app:test-tenant-features', ['tenant-slug' => 'tenant-a', '--skip-storage' => true, '--skip-notifications' => true]));
        self::assertSame('tenant-a', static::getContainer()->get(TenantContextInterface::class)->getTenant()?->getSlug());
        self::assertSame('Tenant B Product', $this->product('TENANT-B-PRODUCT')->getName());
    }

    public function testCliFailsClosedWhenTenantArgumentIsAbsent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->runCommand('app:test-tenant-features');
    }

    private function freshProduct(string $sku): Product
    {
        $this->removeProduct($sku);
        $product = (new Product())
            ->setTenant($this->tenant('tenant-a'))
            ->setName('Disposable Tenant A Product')
            ->setSku($sku)
            ->setPrice('5.00')
            ->setStockQuantity(1);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function removeProduct(string $sku): void
    {
        $existing = $this->entityManager->getRepository(Product::class)->findOneBy(['sku' => $sku]);
        if ($existing instanceof Product) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }

    private function runCommand(string $name, array $input = []): int
    {
        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);

        return (new CommandTester($application->find($name)))->execute($input);
    }

    private function user(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function product(string $sku): Product
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['sku' => $sku]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }
}
