<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Membership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScopeInterface;

final class TenantAccessVoter extends Voter
{
    public const ACCESS = 'TENANT_ACCESS';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GlobalDoctrineScopeInterface $globalScope,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return $this->globalScope->run(fn (): bool => 0 < (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Membership::class, 'm')
            ->join('m.tenant', 't')
            ->where('IDENTITY(m.user) = :user')
            ->andWhere('t.slug = :slug')
            ->andWhere('m.active = true')
            ->andWhere('t.active = true')
            ->setParameter('user', $user->getId())
            ->setParameter('slug', $subject)
            ->getQuery()
            ->getSingleScalarResult());
    }
}
