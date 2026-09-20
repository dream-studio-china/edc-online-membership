<?php

declare(strict_types=1);

namespace App\Liansheng\Service;

use App\Liansheng\Exception\LianshengApiException;
use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LianshengService implements LianshengServiceInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly RequestStack $requestStack,
        private readonly StoreRepository $storeRepository,
    ) {}

    public function getStore(): array
    {
        $data = $this->getStoreTokenData();
        unset($data['id']);

        return $data;
    }

    public function getBusinessRevenueReport(\DateTimeInterface $beginDate, \DateTimeInterface $endDate): array
    {
        return $this->requestEnvelope('POST', '/api/open/rptbusiness', [
            'headers' => ['Token' => $this->getToken()],
            'json' => [
                'beginDate' => $beginDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
            ],
        ]);
    }

    public function getMemberByMobile(string $mobile): array
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            throw new \InvalidArgumentException('Liansheng member mobile must not be empty.');
        }

        $response = $this->request('GET', '/api/vip.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => [
                'method' => 'getvipmember',
            ],
            'json' => ['mobile' => $mobile],
        ]);
        if (($response['code'] ?? null) === 501 && ($response['msg'] ?? null) === '没有匹配到会员资料！') {
            return [];
        }

        $code = $response['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            throw new LianshengApiException(sprintf(
                'Liansheng API request failed: %s',
                is_string($response['msg'] ?? null) ? $response['msg'] : 'unknown error',
            ));
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new LianshengApiException('Liansheng API response did not contain array data.');
        }

        return $data;
    }

    public function registerMemberByMobile(string $mobile): array
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            throw new \InvalidArgumentException('Liansheng member mobile must not be empty.');
        }
        $cardTypeId = $this->configuration()['memberCardTypeId'] ?? null;
        if (!is_string($cardTypeId) || $cardTypeId === '') {
            throw new LianshengApiException('settings.liansheng.memberCardTypeId must be configured to register members.');
        }

        $response = $this->request('POST', '/api/vip.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => ['method' => 'addvip'],
            'json' => [
                'id' => '',
                'code' => '',
                'cardtypeId' => $cardTypeId,
                'cardtypeName' => '',
                'name' => $mobile,
                'alias' => $mobile,
                'sex' => '',
                'mobile' => $mobile,
                'birthtype' => '',
                'birthday' => '',
                'score' => '0',
                'balance' => '0',
                'extbalance' => '0',
                'totalbalance' => '0',
                'salesman' => '',
                'expirydate' => '',
                'available' => '',
            ],
        ]);

        if ($response === []) {
            return [];
        }

        $code = $response['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            throw new LianshengApiException(sprintf(
                'Liansheng API request failed: %s',
                is_string($response['msg'] ?? null) ? $response['msg'] : 'unknown error',
            ));
        }

        return $response;
    }

    public function getMemberScoreBook(?string $mobile = null, ?string $vipId = null): array
    {
        $mobile = trim((string) $mobile);
        $vipId = trim((string) $vipId);
        if (($mobile === '') === ($vipId === '')) {
            throw new \InvalidArgumentException('Exactly one of Liansheng member mobile or vipId is required.');
        }

        return $this->requestEnvelope('GET', '/api/wx.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => array_filter([
                'method' => 'getscorebook',
                'mobile' => $mobile !== '' ? $mobile : null,
                'vipId' => $vipId !== '' ? $vipId : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function deductMemberPoints(
        string $mobile,
        int $points,
        string $reference,
        string $remarks = '',
        ?\DateTimeInterface $accountDate = null,
        string $roomTable = 'ONLINE',
    ): array {
        return $this->changeMemberPoints($mobile, $points, $reference, '-', $remarks, $accountDate, $accountDate, $roomTable);
    }

    public function creditMemberPoints(
        string $mobile,
        int $points,
        string $reference,
        string $remarks = '',
        ?\DateTimeInterface $accountDate = null,
        ?\DateTimeInterface $expiryDate = null,
        string $roomTable = 'ONLINE',
    ): array {
        return $this->changeMemberPoints(
            $mobile,
            $points,
            $reference,
            '+',
            $remarks,
            $accountDate,
            $expiryDate ?? $this->getPointRefundExpiryDate(),
            $roomTable,
        );
    }

    public function getPointRefundExpiryDate(): \DateTimeImmutable
    {
        $value = $this->configuration()['pointRefundExpiryDate'] ?? '2099-12-31';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new LianshengApiException('settings.liansheng.pointRefundExpiryDate must use YYYY-MM-DD.');
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function changeMemberPoints(
        string $mobile,
        int $points,
        string $reference,
        string $direction,
        string $remarks,
        ?\DateTimeInterface $accountDate,
        ?\DateTimeInterface $expiryDate,
        string $roomTable,
    ): array {
        $mobile = trim($mobile);
        $reference = trim($reference);
        $roomTable = trim($roomTable);
        $remarks = trim($remarks);
        if ($mobile === '') {
            throw new \InvalidArgumentException('Liansheng member mobile must not be empty.');
        }
        if ($points <= 0) {
            throw new \InvalidArgumentException('Liansheng points adjustment must be positive.');
        }
        if ($reference === '') {
            throw new \InvalidArgumentException('Liansheng points adjustment reference must not be empty.');
        }
        if ($roomTable === '') {
            throw new \InvalidArgumentException('Liansheng points adjustment room table must not be empty.');
        }
        if ($remarks === '') {
            $remarks = 'Liansheng points adjustment';
        }

        $effectiveAccountDate = $accountDate ?? new \DateTimeImmutable();
        $effectiveExpiryDate = $expiryDate ?? $effectiveAccountDate;
        $response = $this->request('POST', '/api/vip.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => ['method' => 'vipsubscore'],
            'json' => [
                'mobile' => $mobile,
                'accountdate' => $effectiveAccountDate->format('Y-m-d'),
                'dirflag' => $direction,
                'creditscore' => $direction === '+' ? $points : 0,
                'debitscore' => $direction === '-' ? $points : 0,
                'expirydate' => $effectiveExpiryDate->format('Y-m-d'),
                'accno' => $reference,
                'billno' => $reference,
                'roomtable' => $roomTable,
                'remarks' => $remarks,
            ],
        ]);

        if ($response === []) {
            return [];
        }

        $code = $response['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            throw new LianshengApiException(sprintf(
                'Liansheng API request failed: %s',
                is_string($response['msg'] ?? null) ? $response['msg'] : 'unknown error',
            ));
        }

        return $response;
    }

    private function getToken(): string
    {
        $token = $this->getStoreTokenData()['id'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new LianshengApiException('Liansheng token response did not contain an id.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function getStoreTokenData(): array
    {
        $configuration = $this->configuration();
        /** @var array<string, mixed> $data */
        $data = $this->cache->get('liansheng.store_token.' . $configuration['store']->getUuid(), function (ItemInterface $item) use ($configuration): array {
            $tokenData = $this->requestEnvelope('POST', '/api/open/getapptoken', [
                'json' => [
                    'appCode' => $configuration['appCode'],
                    'appSecret' => $configuration['appSecret'],
                    'userId' => $configuration['userId'],
                ],
            ]);

            $expireMinutes = $tokenData['expiremins'] ?? null;
            $ttl = is_int($expireMinutes) || ctype_digit((string) $expireMinutes)
                ? max(60, ((int) $expireMinutes * 60) - 60)
                : 300;
            $item->expiresAfter($ttl);

            return $tokenData;
        });

        return $data;
    }

    /**
     * @return array{store: Store, baseUrl: string, appCode: string, appSecret: string, userId: string, pointRefundExpiryDate?: string, memberCardTypeId?: string}
     */
    private function configuration(): array
    {
        $storeCode = trim((string) $this->requestStack->getCurrentRequest()?->headers->get('X-Store-Code', ''));
        if ($storeCode === '') {
            throw new LianshengApiException('X-Store-Code is required for Liansheng requests.');
        }

        $store = $this->storeRepository->findOneByCode($storeCode);
        if (!$store instanceof Store || !$store->isActive()) {
            throw new LianshengApiException('Liansheng store is not available.');
        }

        $liansheng = $store->getSettings()['liansheng'] ?? null;
        if (!is_array($liansheng)) {
            throw new LianshengApiException('settings.liansheng must be configured for this store.');
        }

        $configuration = ['store' => $store];
        foreach (['baseUrl', 'appCode', 'appSecret', 'userId'] as $key) {
            $value = $liansheng[$key] ?? null;
            if (!is_string($value) && !is_int($value)) {
                throw new LianshengApiException(sprintf('settings.liansheng.%s must be configured.', $key));
            }
            $value = trim((string) $value);
            if ($value === '') {
                throw new LianshengApiException(sprintf('settings.liansheng.%s must be configured.', $key));
            }
            $configuration[$key] = $value;
        }

        if (isset($liansheng['pointRefundExpiryDate'])) {
            if (!is_string($liansheng['pointRefundExpiryDate'])) {
                throw new LianshengApiException('settings.liansheng.pointRefundExpiryDate must be a string.');
            }
            $configuration['pointRefundExpiryDate'] = trim($liansheng['pointRefundExpiryDate']);
        }
        if (isset($liansheng['memberCardTypeId'])) {
            if (!is_string($liansheng['memberCardTypeId'])) {
                throw new LianshengApiException('settings.liansheng.memberCardTypeId must be a string.');
            }
            $configuration['memberCardTypeId'] = trim($liansheng['memberCardTypeId']);
        }

        /** @var array{store: Store, baseUrl: string, appCode: string, appSecret: string, userId: string, pointRefundExpiryDate?: string, memberCardTypeId?: string} $configuration */
        return $configuration;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestEnvelope(string $method, string $path, array $options): array
    {
        $data = $this->requestEnvelopeData($method, $path, $options);
        $objectData = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new LianshengApiException('Liansheng API response data is not an object.');
            }
            $objectData[$key] = $value;
        }

        return $objectData;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    private function requestEnvelopeData(string $method, string $path, array $options): array
    {
        $response = $this->request($method, $path, $options);
        $code = $response['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            throw new LianshengApiException(sprintf(
                'Liansheng API request failed: %s',
                is_string($response['msg'] ?? null) ? $response['msg'] : 'unknown error',
            ));
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new LianshengApiException('Liansheng API response did not contain array data.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, rtrim($this->configuration()['baseUrl'], '/') . $path, $options + [
                'timeout' => 10.0,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new LianshengApiException('Liansheng API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new LianshengApiException('Liansheng API response is not a JSON object.');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new LianshengApiException(sprintf('Liansheng API returned HTTP %d.', $statusCode));
        }

        return $data;
    }
}
