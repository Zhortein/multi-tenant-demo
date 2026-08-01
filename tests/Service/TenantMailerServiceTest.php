<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\TenantMailerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class TenantMailerServiceTest extends TestCase
{
    public function testTenantMetadataHeadersAreNotEmitted(): void
    {
        $tenant = (new Tenant())->setName('Default Tenant')->setSlug('default');
        $context = $this->createStub(TenantContextInterface::class);
        $context->method('getTenant')->willReturn($tenant);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                $headers = $email->getHeaders();

                return !$headers->has('X-Tenant-ID')
                    && !$headers->has('X-Tenant-Name')
                    && !$headers->has('X-Tenant-Slug');
            }));

        $service = new TenantMailerService(
            $mailer,
            $context,
            $this->createStub(LoggerInterface::class),
        );

        $service->sendSimpleEmail('recipient@example.com', 'Subject', 'Content');
    }
}
