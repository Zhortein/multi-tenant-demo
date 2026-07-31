<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TenantAuthorizationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);
        $command = $application->find('app:create-sample-data');
        self::assertSame(0, (new CommandTester($command))->execute([]));

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnonymousTenantRequestRequiresAuthentication(): void
    {
        $this->client->request('GET', '/tenant-a/products');

        self::assertResponseRedirects('/login');
    }

    public function testFormLoginAuthenticatesWithoutSelectingTenant(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'alice@tenant-a.example.test',
            '_password' => 'demo-password',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testTenantUserCanAccessOnlyTheirOwnTenantAndCannotAdminister(): void
    {
        $tenantUser = $this->user('alice@tenant-a.example.test');
        self::assertNotContains('ROLE_ADMIN', $tenantUser->getRoles());
        $this->client->loginUser($tenantUser);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/tenant-a"]');
        self::assertSelectorNotExists('a[href="/tenant-b"]');

        $this->client->request('GET', '/tenant-a/products');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/tenant-b/products');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExplicitAdministratorCanAccessAdministrationAndTenantRoutes(): void
    {
        $admin = $this->user('admin@example.test');
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/tenant-a/products');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/tenant-b/products');
        self::assertResponseIsSuccessful();
    }

    private function user(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
