<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Service;

use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengService;
use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

    public function testTreatsVendorMemberNotFoundResponseAsAnEmptyList(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $memberResponse = new MockResponse(json_encode([
            'code' => 501,
            'msg' => '没有匹配到会员资料！',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $memberResponse]);

        self::assertSame([], $service->getMemberByMobile('13937124718'));
    }

    public function testRegistersMemberUsing0702(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $registrationResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $registrationResponse], [
            'baseUrl' => 'https://example.test/web',
            'appCode' => 'app-code',
            'appSecret' => 'app-secret',
            'userId' => '1',
            'memberCardTypeId' => 'card-type-id',
        ]);

        self::assertSame(0, $service->registerMemberByMobile('13802542123')['code']);
        self::assertSame('POST', $registrationResponse->getRequestMethod());
        self::assertSame('https://example.test/web/api/vip.api?method=addvip', $registrationResponse->getRequestUrl());
        self::assertContains('Token: store-token', $registrationResponse->getRequestOptions()['headers']);
        self::assertSame([
            'id' => '',
            'code' => '',
            'cardtypeId' => 'card-type-id',
            'cardtypeName' => '',
            'name' => '13802542123',
            'alias' => '13802542123',
            'sex' => '',
            'mobile' => '13802542123',
            'birthtype' => '',
            'birthday' => '',
            'score' => '0',
            'balance' => '0',
            'extbalance' => '0',
            'totalbalance' => '0',
            'salesman' => '',
            'expirydate' => '',
            'available' => '',
        ], json_decode($registrationResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRequiresConfiguredCardTypeToRegisterMember(): void
    {
        $service = $this->service([]);

        $this->expectException(LianshengApiException::class);
        $this->expectExceptionMessage('settings.liansheng.memberCardTypeId must be configured');

        $service->registerMemberByMobile('13937124718');
    }

    public function testGetsMemberCardTypes(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $cardTypesResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => [['id' => '3032191', 'name' => 'VIP会员']],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $cardTypesResponse]);

        self::assertSame('3032191', $service->getMemberCardTypes()[0]['id']);
        self::assertSame(
            'https://example.test/web/api/wx.api?method=getvipcardtype&isamount=F',
            $cardTypesResponse->getRequestUrl(),
        );
        self::assertContains('Token: store-token', $cardTypesResponse->getRequestOptions()['headers']);
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

    public function testGetsMemberScoreBookByMobile(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $scoreBookResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => [
                'totalCount' => 0,
                'pageSize' => 10,
                'totalPage' => 0,
                'currPage' => 1,
                'list' => [],
                'subData' => ['debitscore' => 0, 'creditscore' => 0],
            ],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $scoreBookResponse]);

        $result = $service->getMemberScoreBook(mobile: '13802542123');

        self::assertSame(0, $result['totalCount']);
        self::assertSame(
            'https://example.test/web/api/wx.api?method=getscorebook&mobile=13802542123',
            $scoreBookResponse->getRequestUrl(),
        );
        self::assertContains('Token: store-token', $scoreBookResponse->getRequestOptions()['headers']);
    }

    public function testRequiresExactlyOneMemberScoreBookIdentifier(): void
    {
        $service = $this->service([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one');

        $service->getMemberScoreBook('13802542123', '1260735');
    }

    public function testGetsRefundExpiryDateFromStoreSettings(): void
    {
        $service = $this->service([], [
            'baseUrl' => 'https://example.test/web',
            'appCode' => 'app-code',
            'appSecret' => 'app-secret',
            'userId' => '1',
            'pointRefundExpiryDate' => '2030-12-31',
        ]);

        self::assertSame('2030-12-31', $service->getPointRefundExpiryDate()->format('Y-m-d'));
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
            'roomtable' => 'ONLINE',
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
            'roomtable' => 'ONLINE',
            'remarks' => 'Order refund',
        ], json_decode($creditResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses, ?array $lianshengSettings = null): LianshengService
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET', server: ['HTTP_X_STORE_CODE' => 'store-1']));
        $store = (new Store('store-1', 'Store'))->setSettings([
            'liansheng' => $lianshengSettings ?? [
                'baseUrl' => 'https://example.test/web',
                'appCode' => 'app-code',
                'appSecret' => 'app-secret',
                'userId' => '1',
            ],
        ]);
        $storeRepository = $this->createMock(StoreRepository::class);
        $storeRepository->method('findOneByCode')->with('store-1')->willReturn($store);

        return new LianshengService(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            $requestStack,
            $storeRepository,
        );
    }
}
