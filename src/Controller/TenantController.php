<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Controller for managing tenants in the multi-tenant demo application.
 * 
 * This controller provides basic CRUD operations for tenants and demonstrates
 * how to work with the tenant entity in a multi-tenant environment.
 */
#[Route('/admin/tenants', name: 'admin_tenant_')]
class TenantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger
    ) {
    }

    /**
     * List all tenants.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenants = $this->entityManager
            ->getRepository(Tenant::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('tenant/index.html.twig', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * Show tenant creation form and handle tenant creation.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $description = trim($request->request->get('description', ''));
            $domain = trim($request->request->get('domain', '')) ?: null;

            if (empty($name)) {
                $this->addFlash('error', 'Tenant name is required.');
                return $this->render('tenant/new.html.twig');
            }

            // Generate slug from name
            $slug = $this->slugger->slug($name)->lower()->toString();

            // Check if slug already exists
            $existingTenant = $this->entityManager
                ->getRepository(Tenant::class)
                ->findOneBy(['slug' => $slug]);

            if ($existingTenant) {
                $this->addFlash('error', 'A tenant with this name already exists.');
                return $this->render('tenant/new.html.twig', [
                    'name' => $name,
                    'description' => $description,
                    'domain' => $domain,
                ]);
            }

            $tenant = new Tenant();
            $tenant->setName($name);
            $tenant->setSlug($slug);
            $tenant->setDescription($description);
            $tenant->setDomain($domain);

            $this->entityManager->persist($tenant);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Tenant "%s" has been created successfully.', $name));

            return $this->redirectToRoute('admin_tenant_index');
        }

        return $this->render('tenant/new.html.twig');
    }

    /**
     * Show tenant details.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Tenant $tenant): Response
    {
        return $this->render('tenant/show.html.twig', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Show tenant edit form and handle tenant updates.
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Tenant $tenant): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $description = trim($request->request->get('description', ''));
            $domain = trim($request->request->get('domain', '')) ?: null;
            $active = $request->request->getBoolean('active');

            if (empty($name)) {
                $this->addFlash('error', 'Tenant name is required.');
                return $this->render('tenant/edit.html.twig', ['tenant' => $tenant]);
            }

            // Generate new slug if name changed
            $newSlug = $this->slugger->slug($name)->lower()->toString();
            if ($newSlug !== $tenant->getSlug()) {
                // Check if new slug already exists
                $existingTenant = $this->entityManager
                    ->getRepository(Tenant::class)
                    ->findOneBy(['slug' => $newSlug]);

                if ($existingTenant && $existingTenant->getId() !== $tenant->getId()) {
                    $this->addFlash('error', 'A tenant with this name already exists.');
                    return $this->render('tenant/edit.html.twig', ['tenant' => $tenant]);
                }

                $tenant->setSlug($newSlug);
            }

            $tenant->setName($name);
            $tenant->setDescription($description);
            $tenant->setDomain($domain);
            $tenant->setActive($active);

            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Tenant "%s" has been updated successfully.', $name));

            return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
        }

        return $this->render('tenant/edit.html.twig', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Delete a tenant.
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Tenant $tenant): Response
    {
        if ($this->isCsrfTokenValid('delete_tenant_' . $tenant->getId(), $request->request->get('_token'))) {
            $tenantName = $tenant->getName();
            
            // Check if tenant has users or products
            if ($tenant->getMemberships()->count() > 0 || $tenant->getProducts()->count() > 0) {
                $this->addFlash('error', 'Cannot delete tenant with existing users or products.');
                return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
            }

            $this->entityManager->remove($tenant);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Tenant "%s" has been deleted successfully.', $tenantName));
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('admin_tenant_index');
    }
}
