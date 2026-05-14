<?php

namespace App\Tests\Core\EventListener;

use App\Core\EventListener\ExceptionInterceptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExceptionInterceptorTest extends TestCase
{
    public function testProdEnvironmentSetsResponseForApiPath(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return (string) $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $serializer = new class implements SerializerInterface {
            public function serialize(mixed $data, string $format, array $context = []): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }

            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
            {
                return null;
            }
        };

        $listener = new ExceptionInterceptor(
            $this->createStub(ContainerInterface::class),
            $translator,
            $serializer,
            new NullLogger(),
            'prod'
        );

        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom', 7)
        );

        $listener->onKernelException($event);

        self::assertNotNull($event->getResponse());
        self::assertStringContainsString('boom', (string) $event->getResponse()?->getContent());
    }
}
