<?php

declare(strict_types=1);

namespace App\Liansheng\Service;

use App\Liansheng\Exception\LianshengApiException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LianshengService implements LianshengServiceInterface
{
    private const TOKEN_CACHE_KEY = 'liansheng.store_token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $baseUrl,
        private readonly string $appCode,
        private readonly string $appSecret,
        private readonly string $userId,
    ) {
    }

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

        return $this->requestEnvelopeData('GET', '/api/vip.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => [
                'method' => 'getvipmember',
            ],
            'json' => ['mobile' => $mobile],
        ]);
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
    ): array {
        return $this->changeMemberPoints($mobile, $points, $reference, '-', $remarks, $accountDate, $accountDate);
    }

    public function creditMemberPoints(
        string $mobile,
        int $points,
        string $reference,
        string $remarks = '',
        ?\DateTimeInterface $accountDate = null,
        ?\DateTimeInterface $expiryDate = null,
    ): array {
        return $this->changeMemberPoints($mobile, $points, $reference, '+', $remarks, $accountDate, $expiryDate);
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
    ): array {
        $mobile = trim($mobile);
        $reference = trim($reference);
        if ($mobile === '') {
            throw new \InvalidArgumentException('Liansheng member mobile must not be empty.');
        }
        if ($points <= 0) {
            throw new \InvalidArgumentException('Liansheng points adjustment must be positive.');
        }
        if ($reference === '') {
            throw new \InvalidArgumentException('Liansheng points adjustment reference must not be empty.');
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
                'roomtable' => '',
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
        /** @var array<string, mixed> $data */
        $data = $this->cache->get(self::TOKEN_CACHE_KEY, function (ItemInterface $item): array {
            if ($this->appCode === '' || $this->appSecret === '' || $this->userId === '') {
                throw new LianshengApiException('Liansheng credentials are not configured.');
            }

            $tokenData = $this->requestEnvelope('POST', '/api/open/getapptoken', [
                'json' => [
                    'appCode' => $this->appCode,
                    'appSecret' => $this->appSecret,
                    'userId' => $this->userId,
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
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/') . $path, $options + [
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
