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

    public function testDeductsMemberPoints(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('deductMemberPoints')
            ->with(
                '13802542123',
                10,
                'MANUAL-POINTS-001',
                'Manual adjustment',
                self::callback(static fn (\DateTimeInterface $date): bool => $date->format('Y-m-d') === '2026-09-20'),
                'ONLINE',
            )
            ->willReturn(['code' => 0]);
        $controller = $this->controller($service);

        $response = $controller->memberPoints($this->memberPointsRequest([
            'dirflag' => '-',
            'accountDate' => '2026-09-20',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 0], $this->decode($response->getContent())['data']);
    }

    public function testCreditsMemberPoints(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('creditMemberPoints')
            ->with(
                '13802542123',
                10,
                'MANUAL-POINTS-001',
                'Manual adjustment',
                null,
                self::callback(static fn (\DateTimeInterface $date): bool => $date->format('Y-m-d') === '2099-12-31'),
                'ONLINE',
            )
            ->willReturn(['code' => 0]);
        $controller = $this->controller($service);

        $response = $controller->memberPoints($this->memberPointsRequest([
            'dirflag' => '+',
            'expiryDate' => '2099-12-31',
        ]));

        self::assertSame(200, $response->getStatusCode());
    }

    #[DataProvider('invalidMemberPointsProvider')]
    public function testRejectsInvalidMemberPoints(string $body, string $message): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('deductMemberPoints');
        $service->expects(self::never())->method('creditMemberPoints');
        $controller = $this->controller($service);

        $response = $controller->memberPoints(Request::create(
            '/api/v1/manage/liansheng/member-points',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($message, $this->decode($response->getContent())['message']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMemberPointsProvider(): iterable
    {
        yield 'malformed JSON' => ['{', 'Invalid JSON.'];
        yield 'invalid direction' => [json_encode(['mobile' => '13802542123', 'dirflag' => 'x', 'points' => 10, 'reference' => 'REF', 'roomtable' => 'ONLINE', 'remarks' => 'Manual adjustment'], JSON_THROW_ON_ERROR), 'mobile, dirflag, reference, roomtable, and remarks are required.'];
        yield 'invalid field type' => [json_encode(['mobile' => [], 'dirflag' => '-', 'points' => 10, 'reference' => 'REF', 'roomtable' => 'ONLINE', 'remarks' => 'Manual adjustment'], JSON_THROW_ON_ERROR), 'mobile, dirflag, reference, roomtable, and remarks are required.'];
        yield 'zero points' => [json_encode(['mobile' => '13802542123', 'dirflag' => '-', 'points' => 0, 'reference' => 'REF', 'roomtable' => 'ONLINE', 'remarks' => 'Manual adjustment'], JSON_THROW_ON_ERROR), 'points must be a positive integer.'];
        yield 'invalid expiry date' => [json_encode(['mobile' => '13802542123', 'dirflag' => '+', 'points' => 10, 'reference' => 'REF', 'roomtable' => 'ONLINE', 'remarks' => 'Manual adjustment', 'expiryDate' => '2026-02-30'], JSON_THROW_ON_ERROR), 'accountDate and expiryDate must use YYYY-MM-DD.'];
    }

    public function testMapsMemberPointsProviderFailureToBadGateway(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->method('deductMemberPoints')->willThrowException(new LianshengApiException('provider unavailable'));
        $controller = $this->controller($service);

        $response = $controller->memberPoints($this->memberPointsRequest(['dirflag' => '-']));

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

    /** @param array<string, mixed> $overrides */
    private function memberPointsRequest(array $overrides): Request
    {
        return Request::create(
            '/api/v1/manage/liansheng/member-points',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(array_merge([
                'mobile' => '13802542123',
                'dirflag' => '+',
                'points' => 10,
                'reference' => 'MANUAL-POINTS-001',
                'roomtable' => 'ONLINE',
                'remarks' => 'Manual adjustment',
            ], $overrides), JSON_THROW_ON_ERROR),
        );
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
