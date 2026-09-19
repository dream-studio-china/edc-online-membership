<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Service;

use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LianshengServiceTest extends TestCase
{
    public function testGetsMemberUsingCachedStoreToken(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'ok',
            'data' => ['id' => 'store-token', 'storeCode' => 'S001', 'storeName' => 'Test Store', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $memberResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => [[
                'id' => 'member-id',
                'code' => '1260735',
                'name' => 'Member',
                'mobile' => '13802542123',
                'score' => 12.0,
            ]],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $memberResponse]);

        self::assertSame('S001', $service->getStore()['storeCode']);
        self::assertSame('1260735', $service->getMemberByMobile('13802542123')[0]['code']);
        self::assertSame('POST', $tokenResponse->getRequestMethod());
        self::assertSame('https://example.test/web/api/open/getapptoken', $tokenResponse->getRequestUrl());
        self::assertSame('GET', $memberResponse->getRequestMethod());
        self::assertSame(
            'https://example.test/web/api/vip.api?method=getvipmember',
            $memberResponse->getRequestUrl(),
        );
        self::assertContains('Token: store-token', $memberResponse->getRequestOptions()['headers']);
        self::assertSame(
            ['mobile' => '13802542123'],
            json_decode($memberResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testGetsBusinessRevenueReport(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $reportResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['billSum' => ['billincome' => 100], 'billPayment' => [], 'billVoucher' => []],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $reportResponse]);

        $report = $service->getBusinessRevenueReport(new \DateTimeImmutable('2025-02-10'), new \DateTimeImmutable('2025-02-10'));

        self::assertSame(100, $report['billSum']['billincome']);
        self::assertSame('POST', $reportResponse->getRequestMethod());
        self::assertSame(
            ['beginDate' => '2025-02-10', 'endDate' => '2025-02-10'],
            json_decode($reportResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertContains('Token: store-token', $reportResponse->getRequestOptions()['headers']);
    }

    public function testRejectsApiErrors(): void
    {
        $service = $this->service([new MockResponse(json_encode([
            'code' => 1001,
            'msg' => 'invalid app credentials',
            'data' => null,
        ], JSON_THROW_ON_ERROR))]);

        $this->expectException(LianshengApiException::class);
        $this->expectExceptionMessage('invalid app credentials');

        $service->getStore();
    }

    public function testDeductsMemberPointsOnceWithStableReference(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $deductionResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $deductionResponse]);

        $result = $service->deductMemberPoints(
            '13802542123',
            120,
            'PAY-REFERENCE-1',
            'Order payment',
            new \DateTimeImmutable('2026-09-20'),
        );

        self::assertSame(0, $result['code']);
        self::assertSame('POST', $deductionResponse->getRequestMethod());
        self::assertSame(
            'https://example.test/web/api/vip.api?method=vipsubscore',
            $deductionResponse->getRequestUrl(),
        );
        self::assertContains('Token: store-token', $deductionResponse->getRequestOptions()['headers']);
        self::assertSame([
            'mobile' => '13802542123',
            'accountdate' => '2026-09-20',
            'dirflag' => '-',
            'creditscore' => 0,
            'debitscore' => 120,
            'expirydate' => '2026-09-20',
            'accno' => 'PAY-REFERENCE-1',
            'billno' => 'PAY-REFERENCE-1',
            'roomtable' => '',
            'remarks' => 'Order payment',
        ], json_decode($deductionResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRejectsFailedPointsDeduction(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $deductionResponse = new MockResponse(json_encode([
            'code' => 501,
            'msg' => 'insufficient points',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $deductionResponse]);

        $this->expectException(LianshengApiException::class);
        $this->expectExceptionMessage('insufficient points');

        $service->deductMemberPoints('13802542123', 120, 'PAY-REFERENCE-1');
    }

    public function testCreditsMemberPointsWithCreditFieldsAndExpiryDate(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $creditResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $creditResponse]);

        $result = $service->creditMemberPoints(
            '13802542123',
            120,
            'PAY-REFERENCE-1-R120',
            'Order refund',
            new \DateTimeImmutable('2026-09-20'),
            new \DateTimeImmutable('2099-12-31'),
        );

        self::assertSame(0, $result['code']);
        self::assertSame([
            'mobile' => '13802542123',
            'accountdate' => '2026-09-20',
            'dirflag' => '+',
            'creditscore' => 120,
            'debitscore' => 0,
            'expirydate' => '2099-12-31',
            'accno' => 'PAY-REFERENCE-1-R120',
            'billno' => 'PAY-REFERENCE-1-R120',
            'roomtable' => '',
            'remarks' => 'Order refund',
        ], json_decode($creditResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses): LianshengService
    {
        return new LianshengService(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            'https://example.test/web',
            'app-code',
            'app-secret',
            '1',
        );
    }
}
