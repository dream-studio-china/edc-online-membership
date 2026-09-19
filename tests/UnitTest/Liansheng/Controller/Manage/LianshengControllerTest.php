<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Controller\Manage;

use App\Liansheng\Controller\Manage\LianshengController;
use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class LianshengControllerTest extends TestCase
{
    public function testReturnsBusinessRevenueReport(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('getBusinessRevenueReport')
            ->with(
                self::callback(static fn (\DateTimeInterface $date): bool => $date->format('Y-m-d') === '2025-02-10'),
                self::callback(static fn (\DateTimeInterface $date): bool => $date->format('Y-m-d') === '2025-02-11'),
            )
            ->willReturn(['billSum' => ['billincome' => 100]]);
        $controller = $this->controller($service);

        $response = $controller->businessRevenue($this->request('2025-02-10', '2025-02-11'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(100, $this->decode($response->getContent())['data']['billSum']['billincome']);
    }

    /** @param array{0?: string, 1?: string} $dates */
    #[DataProvider('invalidDateProvider')]
    public function testRejectsInvalidDateRange(array $dates, string $message): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('getBusinessRevenueReport');
        $controller = $this->controller($service);

        $response = $controller->businessRevenue(Request::create(
            '/api/v1/manage/liansheng/business-revenue',
            'GET',
            array_filter(['beginDate' => $dates[0] ?? null, 'endDate' => $dates[1] ?? null]),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($message, $this->decode($response->getContent())['message']);
    }

    /** @return iterable<string, array{array{0?: string, 1?: string}, string}> */
    public static function invalidDateProvider(): iterable
    {
        yield 'missing date' => [['2025-02-10'], 'beginDate and endDate must use YYYY-MM-DD.'];
        yield 'invalid calendar date' => [['2025-02-30', '2025-03-01'], 'beginDate and endDate must use YYYY-MM-DD.'];
        yield 'reversed range' => [['2025-02-11', '2025-02-10'], 'beginDate must not be after endDate.'];
    }

    public function testMapsProviderFailureToBadGateway(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->method('getBusinessRevenueReport')->willThrowException(new LianshengApiException('provider unavailable'));
        $controller = $this->controller($service);

        $response = $controller->businessRevenue($this->request('2025-02-10', '2025-02-10'));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('provider unavailable', $this->decode($response->getContent())['message']);
    }

    private function request(string $beginDate, string $endDate): Request
    {
        return Request::create('/api/v1/manage/liansheng/business-revenue', 'GET', [
            'beginDate' => $beginDate,
            'endDate' => $endDate,
        ]);
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
