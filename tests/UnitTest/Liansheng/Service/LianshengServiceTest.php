<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Service;

use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengService;
use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
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
        $cardTypesResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => [['id' => 'card-type-id', 'name' => 'VIP会员']],
        ], JSON_THROW_ON_ERROR));
        $registrationResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $cardTypesResponse, $registrationResponse], [
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
        $body = json_decode($registrationResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'id' => '',
            'code' => '',
            'cardtypeId' => 'card-type-id',
            'cardtypeName' => 'VIP会员',
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
            'expirydate' => '2099-12-31',
            'available' => 'T',
        ], $body);
    }

    public function testRegistersMemberWithEmptyCardTypeNameWhenCardTypeLookupFails(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $cardTypesFailure = new MockResponse(json_encode([
            'code' => 1001,
            'msg' => 'card types unavailable',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $registrationResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $cardTypesFailure, $registrationResponse], [
            'baseUrl' => 'https://example.test/web',
            'appCode' => 'app-code',
            'appSecret' => 'app-secret',
            'userId' => '1',
            'memberCardTypeId' => 'card-type-id',
        ]);

        self::assertSame(0, $service->registerMemberByMobile('13802542123')['code']);
        self::assertSame(
            '',
            json_decode($registrationResponse->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR)['cardtypeName'],
        );
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
        $beforeResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => ['mobile' => '13802542123', 'score' => 200],
        ], JSON_THROW_ON_ERROR));
        $deductionResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $afterResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => ['mobile' => '13802542123', 'score' => 80],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $beforeResponse, $deductionResponse, $afterResponse]);

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
        $beforeResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => ['mobile' => '13802542123', 'score' => 200],
        ], JSON_THROW_ON_ERROR));
        $deductionResponse = new MockResponse(json_encode([
            'code' => 501,
            'msg' => 'insufficient points',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $beforeResponse, $deductionResponse]);

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
        $beforeResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => [['mobile' => '13802542123', 'score' => 0]],
        ], JSON_THROW_ON_ERROR));
        $creditResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $afterResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => [['mobile' => '13802542123', 'score' => 120]],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $beforeResponse, $creditResponse, $afterResponse]);

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

    public function testThrowsWhenPointsAdjustmentIsNotApplied(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $beforeResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => ['mobile' => '13802542123', 'score' => 100],
        ], JSON_THROW_ON_ERROR));
        $creditResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $afterResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => ['mobile' => '13802542123', 'score' => 100],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $beforeResponse, $creditResponse, $afterResponse]);

        $this->expectException(LianshengApiException::class);
        $this->expectExceptionMessage('was not applied');

        $service->creditMemberPoints('13802542123', 120, 'PAY-REFERENCE-1');
    }

    public function testThrowsWhenAdjustingMissingMember(): void
    {
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $notFoundResponse = new MockResponse(json_encode([
            'code' => 501,
            'msg' => '没有匹配到会员资料！',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $notFoundResponse]);

        $this->expectException(LianshengApiException::class);
        $this->expectExceptionMessage('does not exist');

        $service->deductMemberPoints('13802542123', 120, 'PAY-REFERENCE-1');
    }

    public function testLogsRequestParametersAndResponse(): void
    {
        $logger = new RecordingLogger();
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $memberResponse = new MockResponse(json_encode([
            'code' => 0,
            'msg' => 'OK',
            'data' => [['code' => '1260735', 'mobile' => '13802542123']],
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $memberResponse], null, $logger);

        $service->getMemberByMobile('13802542123');

        self::assertSame([
            [
                'method' => 'POST',
                'url' => 'https://example.test/web/api/open/getapptoken',
                'query' => [],
                'json' => ['appCode' => 'app-code', 'appSecret' => '[redacted]', 'userId' => '1'],
                'headers' => [],
            ],
            [
                'method' => 'POST',
                'url' => 'https://example.test/web/api/open/getapptoken',
                'status' => 200,
                'body' => ['code' => 0, 'data' => ['id' => 'store-token', 'expiremins' => 60]],
            ],
            [
                'method' => 'GET',
                'url' => 'https://example.test/web/api/vip.api',
                'query' => ['method' => 'getvipmember'],
                'json' => ['mobile' => '13802542123'],
                'headers' => ['Token' => 'store-token'],
            ],
            [
                'method' => 'GET',
                'url' => 'https://example.test/web/api/vip.api',
                'status' => 200,
                'body' => ['code' => 0, 'msg' => 'OK', 'data' => [['code' => '1260735', 'mobile' => '13802542123']]],
            ],
        ], array_map(static fn (array $record): array => $record['context'], $logger->records));
    }

    public function testLogsVendorErrorResponse(): void
    {
        $logger = new RecordingLogger();
        $tokenResponse = new MockResponse(json_encode([
            'code' => 0,
            'data' => ['id' => 'store-token', 'expiremins' => 60],
        ], JSON_THROW_ON_ERROR));
        $notFoundResponse = new MockResponse(json_encode([
            'code' => 501,
            'msg' => '没有匹配到会员资料！',
            'data' => null,
        ], JSON_THROW_ON_ERROR));
        $service = $this->service([$tokenResponse, $notFoundResponse], null, $logger);

        self::assertSame([], $service->getMemberByMobile('13802542123'));

        self::assertTrue($this->hasError($logger, 'Liansheng API returned an error'));
    }

    public function testLogsTransportFailure(): void
    {
        $logger = new RecordingLogger();
        $service = $this->service([new MockResponse('', ['error' => 'connection refused'])], null, $logger);

        try {
            $service->getStore();
            self::fail('Expected a LianshengApiException.');
        } catch (LianshengApiException $exception) {
            self::assertStringContainsString('Liansheng API request failed', $exception->getMessage());
        }

        self::assertTrue($this->hasError($logger, 'Liansheng API request failed'));
    }

    private function hasError(RecordingLogger $logger, string $message): bool
    {
        foreach ($logger->records as $record) {
            if ($record['level'] === LogLevel::ERROR && str_contains($record['message'], $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses, ?array $lianshengSettings = null, ?LoggerInterface $logger = null): LianshengService
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
            $logger ?? new NullLogger(),
        );
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
