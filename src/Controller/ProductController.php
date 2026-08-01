<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\TenantNotificationTrait;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Controller for managing products in a tenant-aware context.
 * 
 * This controller demonstrates how to work with tenant-aware entities
 * and how the multi-tenant bundle automatically filters data based
 * on the current tenant context.
 */
#[Route('/{tenantSlug}/products', name: 'tenant_product_')]
class ProductController extends AbstractController
{
    use TenantNotificationTrait;
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContextInterface $tenantContext,
        private readonly ProductRepository $products
    ) {
    }

    /**
     * List all products for the current tenant.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $tenantSlug): Response
    {
        $tenant = $this->getTenantBySlug($tenantSlug);
        if (!$tenant) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $products = $this->products->findActiveForTenant($tenant);

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('product/index.html.twig', [
            'tenant' => $tenant,
            'products' => $products,
            ...$notificationData,
        ]);
    }

    /**
     * Show product creation form and handle product creation.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $tenantSlug): Response
    {
        $tenant = $this->getTenantBySlug($tenantSlug);
        if (!$tenant) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $description = trim($request->request->get('description', ''));
            $price = trim($request->request->get('price', '0.00'));
            $sku = trim($request->request->get('sku', ''));
            $stockQuantity = (int) $request->request->get('stock_quantity', 0);

            if (empty($name) || empty($sku)) {
                $this->addFlash('error', 'Product name and SKU are required.');
                $notificationData = $this->getNotificationDataForNavigation($tenant);
                return $this->render('product/new.html.twig', [
                    'tenant' => $tenant,
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'sku' => $sku,
                    'stock_quantity' => $stockQuantity,
                    ...$notificationData,
                ]);
            }

            // Check if SKU already exists for this tenant
            $existingProduct = $this->products->findOneBySkuForTenant($sku, $tenant);

            if ($existingProduct) {
                $this->addFlash('error', 'A product with this SKU already exists.');
                $notificationData = $this->getNotificationDataForNavigation($tenant);
                return $this->render('product/new.html.twig', [
                    'tenant' => $tenant,
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'sku' => $sku,
                    'stock_quantity' => $stockQuantity,
                    ...$notificationData,
                ]);
            }

            $product = new Product();
            $product->setName($name);
            $product->setDescription($description);
            $product->setPrice($price);
            $product->setSku($sku);
            $product->setStockQuantity($stockQuantity);
            $product->setTenant($tenant);

            $this->entityManager->persist($product);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Product "%s" has been created successfully.', $name));

            return $this->redirectToRoute('tenant_product_index', ['tenantSlug' => $tenantSlug]);
        }

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('product/new.html.twig', [
            'tenant' => $tenant,
            ...$notificationData,
        ]);
    }

    /**
     * Show product details.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $tenantSlug, int $id): Response
    {
        $tenant = $this->getTenantBySlug($tenantSlug);
        if (!$tenant) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $product = $this->products->findOneForTenant($id, $tenant);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('product/show.html.twig', [
            'tenant' => $tenant,
            'product' => $product,
            ...$notificationData,
        ]);
    }

    /**
     * Show product edit form and handle product updates.
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, string $tenantSlug, int $id): Response
    {
        $tenant = $this->getTenantBySlug($tenantSlug);
        if (!$tenant) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $product = $this->products->findOneForTenant($id, $tenant);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $description = trim($request->request->get('description', ''));
            $price = trim($request->request->get('price', '0.00'));
            $sku = trim($request->request->get('sku', ''));
            $stockQuantity = (int) $request->request->get('stock_quantity', 0);
            $active = $request->request->getBoolean('active');

            if (empty($name) || empty($sku)) {
                $this->addFlash('error', 'Product name and SKU are required.');
                $notificationData = $this->getNotificationDataForNavigation($tenant);
                return $this->render('product/edit.html.twig', [
                    'tenant' => $tenant,
                    'product' => $product,
                    ...$notificationData,
                ]);
            }

            // Check if SKU already exists for this tenant (excluding current product)
            if ($sku !== $product->getSku()) {
                $existingProduct = $this->products->findOneBySkuForTenant($sku, $tenant);

                if ($existingProduct && $existingProduct->getId() !== $product->getId()) {
                    $this->addFlash('error', 'A product with this SKU already exists.');
                    $notificationData = $this->getNotificationDataForNavigation($tenant);
                    return $this->render('product/edit.html.twig', [
                        'tenant' => $tenant,
                        'product' => $product,
                        ...$notificationData,
                    ]);
                }
            }

            $product->setName($name);
            $product->setDescription($description);
            $product->setPrice($price);
            $product->setSku($sku);
            $product->setStockQuantity($stockQuantity);
            $product->setActive($active);

            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Product "%s" has been updated successfully.', $name));

            return $this->redirectToRoute('tenant_product_show', [
                'tenantSlug' => $tenantSlug,
                'id' => $product->getId(),
            ]);
        }

        $notificationData = $this->getNotificationDataForNavigation($tenant);

        return $this->render('product/edit.html.twig', [
            'tenant' => $tenant,
            'product' => $product,
            ...$notificationData,
        ]);
    }

    /**
     * Delete a product.
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, string $tenantSlug, int $id): Response
    {
        $tenant = $this->getTenantBySlug($tenantSlug);
        if (!$tenant) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $product = $this->products->findOneForTenant($id, $tenant);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        if ($this->isCsrfTokenValid('delete_product_' . $product->getId(), $request->request->get('_token'))) {
            $productName = $product->getName();
            
            $this->entityManager->remove($product);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Product "%s" has been deleted successfully.', $productName));
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('tenant_product_index', ['tenantSlug' => $tenantSlug]);
    }

    /**
     * Get tenant by slug.
     */
    private function getTenantBySlug(string $slug): ?Tenant
    {
        return $this->entityManager
            ->getRepository(Tenant::class)
            ->findOneBy(['slug' => $slug, 'active' => true]);
    }
}