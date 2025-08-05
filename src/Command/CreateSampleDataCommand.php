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

/**
 * Command to create sample data for the multi-tenant demo application.
 * 
 * This command creates sample tenants, users, and products to demonstrate
 * the multi-tenant functionality and data isolation.
 */
#[AsCommand(
    name: 'app:create-sample-data',
    description: 'Create sample data for the multi-tenant demo application'
)]
class CreateSampleDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Creating Sample Data for Multi-Tenant Demo');

        // Check if data already exists
        $existingTenants = $this->entityManager->getRepository(Tenant::class)->count([]);
        if ($existingTenants > 0) {
            $io->warning('Sample data already exists. Skipping creation.');
            return Command::SUCCESS;
        }

        $io->section('Creating Tenants');

        // Create sample tenants
        $tenantsData = [
            [
                'name' => 'Acme Corporation',
                'slug' => 'acme-corp',
                'description' => 'A leading technology company specializing in innovative solutions.',
                'domain' => null,
            ],
            [
                'name' => 'Global Retail Inc',
                'slug' => 'global-retail',
                'description' => 'International retail chain with stores worldwide.',
                'domain' => null,
            ],
            [
                'name' => 'TechStart Solutions',
                'slug' => 'techstart',
                'description' => 'Startup focused on cutting-edge software development.',
                'domain' => null,
            ],
        ];

        $tenants = [];
        foreach ($tenantsData as $tenantData) {
            $tenant = new Tenant();
            $tenant->setName($tenantData['name']);
            $tenant->setSlug($tenantData['slug']);
            $tenant->setDescription($tenantData['description']);
            $tenant->setDomain($tenantData['domain']);

            $this->entityManager->persist($tenant);
            $tenants[] = $tenant;

            $io->writeln(sprintf('✓ Created tenant: %s', $tenant->getName()));
        }

        $this->entityManager->flush();

        $io->section('Creating Users');

        // Create sample users for each tenant
        $usersData = [
            ['firstName' => 'John', 'lastName' => 'Doe', 'email' => 'john.doe@example.com'],
            ['firstName' => 'Jane', 'lastName' => 'Smith', 'email' => 'jane.smith@example.com'],
            ['firstName' => 'Bob', 'lastName' => 'Johnson', 'email' => 'bob.johnson@example.com'],
        ];

        foreach ($tenants as $tenant) {
            foreach ($usersData as $userData) {
                $user = new User();
                $user->setFirstName($userData['firstName']);
                $user->setLastName($userData['lastName']);
                $user->setEmail($userData['email']);
                $user->setTenant($tenant);
                
                // Set a default password (in real app, this would be handled differently)
                $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
                $user->setPassword($hashedPassword);

                $this->entityManager->persist($user);

                $io->writeln(sprintf('✓ Created user: %s for tenant %s', 
                    $user->getFullName(), 
                    $tenant->getName()
                ));
            }
        }

        $this->entityManager->flush();

        $io->section('Creating Products');

        // Create sample products for each tenant
        $productsData = [
            // Acme Corporation products
            'acme-corp' => [
                ['name' => 'Smart Widget Pro', 'sku' => 'SWP-001', 'price' => '299.99', 'stock' => 50, 'description' => 'Advanced smart widget with AI capabilities.'],
                ['name' => 'Digital Connector', 'sku' => 'DC-002', 'price' => '149.99', 'stock' => 100, 'description' => 'High-speed digital connector for modern devices.'],
                ['name' => 'Cloud Adapter', 'sku' => 'CA-003', 'price' => '89.99', 'stock' => 75, 'description' => 'Seamless cloud integration adapter.'],
                ['name' => 'Security Module', 'sku' => 'SM-004', 'price' => '199.99', 'stock' => 25, 'description' => 'Enterprise-grade security module.'],
                ['name' => 'Data Processor', 'sku' => 'DP-005', 'price' => '399.99', 'stock' => 30, 'description' => 'High-performance data processing unit.'],
            ],
            // Global Retail products
            'global-retail' => [
                ['name' => 'Premium T-Shirt', 'sku' => 'TS-001', 'price' => '29.99', 'stock' => 200, 'description' => 'Comfortable cotton t-shirt in various colors.'],
                ['name' => 'Designer Jeans', 'sku' => 'DJ-002', 'price' => '79.99', 'stock' => 150, 'description' => 'Stylish designer jeans for everyday wear.'],
                ['name' => 'Running Shoes', 'sku' => 'RS-003', 'price' => '129.99', 'stock' => 80, 'description' => 'Lightweight running shoes for athletes.'],
                ['name' => 'Leather Jacket', 'sku' => 'LJ-004', 'price' => '249.99', 'stock' => 40, 'description' => 'Genuine leather jacket with modern styling.'],
                ['name' => 'Casual Sneakers', 'sku' => 'CS-005', 'price' => '89.99', 'stock' => 120, 'description' => 'Comfortable sneakers for daily use.'],
            ],
            // TechStart products
            'techstart' => [
                ['name' => 'Code Editor Pro', 'sku' => 'CEP-001', 'price' => '99.99', 'stock' => 1000, 'description' => 'Professional code editor with advanced features.'],
                ['name' => 'API Gateway', 'sku' => 'AG-002', 'price' => '199.99', 'stock' => 500, 'description' => 'Scalable API gateway solution.'],
                ['name' => 'Database Manager', 'sku' => 'DM-003', 'price' => '149.99', 'stock' => 300, 'description' => 'Comprehensive database management tool.'],
                ['name' => 'Monitoring Suite', 'sku' => 'MS-004', 'price' => '299.99', 'stock' => 200, 'description' => 'Complete application monitoring solution.'],
                ['name' => 'Deployment Tool', 'sku' => 'DT-005', 'price' => '179.99', 'stock' => 250, 'description' => 'Automated deployment and CI/CD tool.'],
            ],
        ];

        foreach ($tenants as $tenant) {
            $tenantProducts = $productsData[$tenant->getSlug()] ?? [];
            
            foreach ($tenantProducts as $productData) {
                $product = new Product();
                $product->setName($productData['name']);
                $product->setSku($productData['sku']);
                $product->setPrice($productData['price']);
                $product->setStockQuantity($productData['stock']);
                $product->setDescription($productData['description']);
                $product->setTenant($tenant);

                $this->entityManager->persist($product);

                $io->writeln(sprintf('✓ Created product: %s for tenant %s', 
                    $product->getName(), 
                    $tenant->getName()
                ));
            }
        }

        $this->entityManager->flush();

        $io->success('Sample data created successfully!');
        
        $io->section('Summary');
        $io->writeln(sprintf('Created %d tenants', count($tenants)));
        $io->writeln(sprintf('Created %d users per tenant', count($usersData)));
        $io->writeln(sprintf('Created %d products per tenant', 5));
        
        $io->note('You can now explore the multi-tenant functionality by visiting different tenant URLs.');

        return Command::SUCCESS;
    }
}