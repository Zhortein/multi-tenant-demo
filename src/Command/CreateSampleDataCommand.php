<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-sample-data', description: 'Create or update deterministic demo accounts and tenant data')]
final class CreateSampleDataCommand extends Command
{
    public const DEMO_PASSWORD = 'demo-password';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $definitions = [
            ['slug' => 'tenant-a', 'name' => 'Tenant A', 'email' => 'alice@tenant-a.example.test', 'first' => 'Alice', 'last' => 'Tenant A', 'roles' => ['ROLE_USER'], 'sku' => 'TENANT-A-PRODUCT'],
            ['slug' => 'tenant-b', 'name' => 'Tenant B', 'email' => 'bob@tenant-b.example.test', 'first' => 'Bob', 'last' => 'Tenant B', 'roles' => ['ROLE_USER'], 'sku' => 'TENANT-B-PRODUCT'],
            ['slug' => 'platform', 'name' => 'Platform Administration', 'email' => 'admin@example.test', 'first' => 'Demo', 'last' => 'Administrator', 'roles' => ['ROLE_ADMIN'], 'sku' => null],
        ];

        foreach ($definitions as $definition) {
            $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => $definition['slug']]) ?? new Tenant();
            $tenant->setSlug($definition['slug'])->setName($definition['name'])->setDescription('Deterministic CI fixture')->setActive(true);
            $this->entityManager->persist($tenant);

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $definition['email']]) ?? new User();
            $user->setEmail($definition['email'])
                ->setFirstName($definition['first'])
                ->setLastName($definition['last'])
                ->setRoles($definition['roles'])
                ->setTenant($tenant)
                ->setActive(true);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
            $this->entityManager->persist($user);

            if ($definition['sku'] !== null) {
                $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                    'tenant' => $tenant,
                    'sku' => $definition['sku'],
                ]) ?? new Product();
                $product->setTenant($tenant)
                    ->setSku($definition['sku'])
                    ->setName($definition['name'].' Product')
                    ->setDescription('Deterministic tenant isolation fixture')
                    ->setPrice('10.00')
                    ->setStockQuantity(10)
                    ->setActive(true);
                $this->entityManager->persist($product);
            }
        }

        $this->entityManager->flush();
        $io->success('Deterministic demo data is ready.');
        $io->table(['Account', 'Tenant', 'Role'], [
            ['alice@tenant-a.example.test', 'tenant-a', 'ROLE_USER'],
            ['bob@tenant-b.example.test', 'tenant-b', 'ROLE_USER'],
            ['admin@example.test', 'platform', 'ROLE_ADMIN'],
        ]);

        return Command::SUCCESS;
    }
}
