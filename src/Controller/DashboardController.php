<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Main dashboard controller for the multi-tenant demo application.
 * 
 * This controller provides the main entry points and demonstrates
 * how to handle both global admin functionality and tenant-specific
 * functionality in a multi-tenant environment.
 */
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Main application homepage - shows available tenants.
     */
    #[Route('/', name: 'app_homepage')]
    public function homepage(): Response
    {
        $tenants = $this->entityManager
            ->getRepository(Tenant::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $this->render('dashboard/homepage.html.twig', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * Global admin dashboard.
     */
    #[Route('/admin', name: 'admin_dashboard')]
    public function adminDashboard(): Response
    {
        $tenantRepository = $this->entityManager->getRepository(Tenant::class);
        
        $stats = [
            'total_tenants' => $tenantRepository->count([]),
            'active_tenants' => $tenantRepository->count(['active' => true]),
            'inactive_tenants' => $tenantRepository->count(['active' => false]),
        ];

        $recentTenants = $tenantRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            5
        );

        return $this->render('dashboard/admin.html.twig', [
            'stats' => $stats,
            'recent_tenants' => $recentTenants,
        ]);
    }

    /**
     * Tenant-specific dashboard.
     */
    #[Route('/{tenantSlug}', name: 'tenant_dashboard')]
    public function tenantDashboard(string $tenantSlug): Response
    {
        $tenant = $this->entityManager
            ->getRepository(Tenant::class)
            ->findOneBy(['slug' => $tenantSlug, 'active' => true]);

        if (!$tenant) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $stats = [
            'total_products' => $tenant->getProducts()->count(),
            'active_products' => $tenant->getProducts()->filter(fn($p) => $p->isActive())->count(),
            'total_users' => $tenant->getUsers()->count(),
            'active_users' => $tenant->getUsers()->filter(fn($u) => $u->isActive())->count(),
        ];

        return $this->render('dashboard/tenant.html.twig', [
            'tenant' => $tenant,
            'stats' => $stats,
        ]);
    }
}