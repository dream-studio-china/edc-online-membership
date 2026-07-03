<?php

namespace App\Tests\Core\EventListener;

use App\Core\EventListener\ControllerListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ControllerListenerTest extends TestCase
{
    public function testLogsOnlyForWriteMethods(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'u1'; }
            public function getId(): int { return 1; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $logger = new class extends NullLogger {
            public int $count = 0;
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->count++;
            }
        };

        $listener = new ControllerListener(
            $this->createStub(ContainerInterface::class),
            $tokenStorage,
            $logger
        );

        $kernel = $this->createStub(HttpKernelInterface::class);

        $listener->onKernelController(new ControllerEvent(
            $kernel,
            static fn (): string => 'ok',
            Request::create('/api/test', 'POST'),
            HttpKernelInterface::MAIN_REQUEST
        ));

        $listener->onKernelController(new ControllerEvent(
            $kernel,
            static fn (): string => 'ok',
            Request::create('/api/test', 'GET'),
            HttpKernelInterface::MAIN_REQUEST
        ));

        self::assertSame(1, $logger->count);
    }

    public function testLoadClassMetadataQuotesSettingKeyAndValue(): void
    {
        $listener = new ControllerListener(
            $this->createStub(ContainerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            new NullLogger(),
        );

        $keyMapping = new FieldMapping(fieldName: 'key', columnName: 'key', type: 'string');
        $valueMapping = new FieldMapping(fieldName: 'value', columnName: 'value', type: 'text');
        $otherMapping = new FieldMapping(fieldName: 'label', columnName: 'label', type: 'string');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('getName')
            ->willReturn('App\\Common\\Entity\\Setting');
        $metadata->fieldMappings = [
            'key' => $keyMapping,
            'value' => $valueMapping,
            'label' => $otherMapping,
        ];

        $em = $this->createMock(EntityManagerInterface::class);
        $args = new LoadClassMetadataEventArgs($metadata, $em);

        $listener->loadClassMetadata($args);

        self::assertTrue($keyMapping->quoted);
        self::assertTrue($valueMapping->quoted);
        self::assertFalse(isset($otherMapping->quoted));
    }

    public function testLoadClassMetadataDoesNotAffectOtherEntities(): void
    {
        $listener = new ControllerListener(
            $this->createStub(ContainerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            new NullLogger(),
        );

        $keyMapping = new FieldMapping(fieldName: 'key', columnName: 'key', type: 'string');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('getName')
            ->willReturn('App\\Identity\\Entity\\User');
        $metadata->fieldMappings = ['key' => $keyMapping];

        $em = $this->createMock(EntityManagerInterface::class);
        $args = new LoadClassMetadataEventArgs($metadata, $em);

        $listener->loadClassMetadata($args);

        self::assertFalse(isset($keyMapping->quoted));
    }
}
