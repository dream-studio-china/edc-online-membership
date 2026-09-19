<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Controller\App;

use App\Liansheng\Controller\App\LianshengController;
use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class LianshengControllerTest extends TestCase
{
    public function testReturnsStoreWithoutChangingItsShape(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('getStore')->willReturn([
            'storeCode' => 'S001',
            'storeName' => 'Test Store',
        ]);
        $controller = $this->controller($service);

        $response = $controller->store();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('S001', $this->decode($response->getContent())['data']['storeCode']);
    }

    public function testRequiresMemberMobile(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('getMemberByMobile');
        $controller = $this->controller($service);

        $response = $controller->member(Request::create('/api/v1/app/liansheng/member'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('mobile is required.', $this->decode($response->getContent())['message']);
    }

    public function testReturnsMemberAndMapsProviderFailure(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::exactly(2))->method('getMemberByMobile')
            ->with('13802542123')
            ->willReturnOnConsecutiveCalls(
                ['code' => '51728662', 'mobile' => '13802542123'],
                self::throwException(new LianshengApiException('provider unavailable')),
            );
        $controller = $this->controller($service);
        $request = Request::create('/api/v1/app/liansheng/member', 'GET', ['mobile' => '13802542123']);

        $success = $controller->member($request);
        $failure = $controller->member($request);

        self::assertSame('51728662', $this->decode($success->getContent())['data']['code']);
        self::assertSame(502, $failure->getStatusCode());
        self::assertSame('provider unavailable', $this->decode($failure->getContent())['message']);
    }

    private function controller(LianshengServiceInterface $service): LianshengController
    {
        $controller = new LianshengController($service);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR),
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setSerializer($serializer);
        $controller->setTranslator($translator);

        return $controller;
    }

    /** @return array<string, mixed> */
    private function decode(string $content): array
    {
        /** @var array<string, mixed> */
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
