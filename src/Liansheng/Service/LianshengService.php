<?php

declare(strict_types=1);

namespace App\Liansheng\Service;

use App\Liansheng\Exception\LianshengApiException;
use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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

        // id/code are vendor-generated (proven 2026-09-23: sending "" returns a UUID
        // id and an 8-digit code). Never synthesize them; generated keys may collide
        // or be rejected once the vendor endpoint works.
        $response = $this->request('POST', '/api/vip.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => ['method' => 'addvip'],
            // Payload mirrors the documented "021 会员资料数据结构" exactly: the 16
            // required string fields, plus cardtypeId which the implementation
            // demands ("必须提供 cardtypeId") although the document omits it.
            'json' => [
                'id' => '',
                'code' => '',
                'cardtypeId' => $cardTypeId,
                'cardtypeName' => $this->resolveMemberCardTypeName($cardTypeId),
                'name' => $mobile,
                'alias' => $mobile,
                // The app owns no authoritative sex data; leave empty and let the
                // vendor store null rather than persisting a false default.
                'sex' => '',
                'mobile' => $mobile,
                'birthtype' => '',
                'birthday' => '',
                'score' => '0',
                'balance' => '0',
                'extbalance' => '0',
                'totalbalance' => '0',
                'salesman' => '',
                // Far-future expiry per operator instruction (vendor expects yyyy-MM-dd).
                'expirydate' => '2099-12-31',
                // Matches an active member record (real records carry "T").
                'available' => 'T',
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

    /**
     * Resolve the vendor display name of the configured member card type.
     *
     * Best effort: an empty cardtypeName is accepted by 0702, so a temporary
     * card-type lookup failure must not break member registration.
     */
    private function resolveMemberCardTypeName(string $cardTypeId): string
    {
        try {
            foreach ($this->getMemberCardTypes() as $cardType) {
                if (!is_array($cardType) || ($cardType['id'] ?? null) !== $cardTypeId) {
                    continue;
                }
                $name = $cardType['name'] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        } catch (\Throwable) {
            // Fall through to the empty name below.
        }

        return '';
    }

    public function getMemberCardTypes(): array
    {
        return $this->requestEnvelopeData('GET', '/api/wx.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => [
                'method' => 'getvipcardtype',
                'isamount' => 'F',
            ],
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
        string $roomTable = 'ONLINE',
    ): array {
        $mobile = trim($mobile);
        $reference = trim($reference);
        $roomTable = trim($roomTable);
        $remarks = trim($remarks);
        if ($mobile === '') {
            throw new \InvalidArgumentException('Liansheng member mobile must not be empty.');
        }
        if ($points <= 0) {
            throw new \InvalidArgumentException('Liansheng points deduction must be positive.');
        }
        if ($reference === '') {
            throw new \InvalidArgumentException('Liansheng points deduction reference must not be empty.');
        }
        if ($roomTable === '') {
            throw new \InvalidArgumentException('Liansheng points deduction room table must not be empty.');
        }
        if ($remarks === '') {
            $remarks = 'Liansheng points deduction';
        }

        // The vendor has returned successful responses without applying the
        // adjustment. Read the balance first so the result can be verified.
        $beforeScore = $this->getMemberScoreValue($mobile);

        $effectiveAccountDate = $accountDate ?? new \DateTimeImmutable();
        // 0703 only deducts, and only through the `score` field. `creditscore` /
        // `debitscore` are ignored, `dirflag` has no effect (a "+" call still
        // subtracts), and crediting is impossible — so there is no credit or
        // refund path. Verified live 2026-09-23.
        $response = $this->request('POST', '/api/vip.api', [
            'headers' => ['Token' => $this->getToken()],
            'query' => ['method' => 'vipsubscore'],
            'json' => [
                'mobile' => $mobile,
                'accountdate' => $effectiveAccountDate->format('Y-m-d'),
                'dirflag' => '-',
                'score' => $points,
                'expirydate' => $effectiveAccountDate->format('Y-m-d'),
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

        // Verify the deduction landed; fail closed so callers (payment gateway,
        // manage adjustments) never record an unapplied change as successful.
        $afterScore = $this->getMemberScoreValue($mobile);
        if (abs(($afterScore - $beforeScore) + $points) > 0.001) {
            throw new LianshengApiException(sprintf(
                'Liansheng points deduction was not applied (mobile %s, expected -%d, balance %.2f -> %.2f).',
                $mobile,
                $points,
                $beforeScore,
                $afterScore,
            ));
        }

        return $response;
    }

    /**
     * Read the member's current points balance for write verification.
     *
     * 0701 returns a single object or a list; a missing score counts as zero.
     */
    private function getMemberScoreValue(string $mobile): float
    {
        $member = $this->getMemberByMobile($mobile);
        if ($member === []) {
            throw new LianshengApiException(sprintf('Liansheng member %s does not exist.', $mobile));
        }

        $record = $member;
        if (array_is_list($member)) {
            $record = [];
            foreach ($member as $item) {
                if (is_array($item) && ($item['mobile'] ?? null) === $mobile) {
                    $record = $item;
                    break;
                }
            }
            if ($record === []) {
                $record = $member[0] ?? [];
            }
        }
        if (!is_array($record)) {
            throw new LianshengApiException(sprintf('Liansheng member %s profile is not readable.', $mobile));
        }

        $score = $record['score'] ?? null;
        if ($score === null || $score === '') {
            return 0.0;
        }
        if (!is_numeric($score)) {
            throw new LianshengApiException(sprintf('Liansheng member %s score is not numeric.', $mobile));
        }

        return (float) $score;
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
     * @return array{store: Store, baseUrl: string, appCode: string, appSecret: string, userId: string, memberCardTypeId?: string}
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

        if (isset($liansheng['memberCardTypeId'])) {
            if (!is_string($liansheng['memberCardTypeId'])) {
                throw new LianshengApiException('settings.liansheng.memberCardTypeId must be a string.');
            }
            $configuration['memberCardTypeId'] = trim($liansheng['memberCardTypeId']);
        }

        /** @var array{store: Store, baseUrl: string, appCode: string, appSecret: string, userId: string, memberCardTypeId?: string} $configuration */
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
        $url = $path;
        try {
            $url = rtrim($this->configuration()['baseUrl'], '/') . $path;
            $this->logger->info('Liansheng API request', [
                'method' => $method,
                'url' => $url,
                'query' => $options['query'] ?? [],
                'json' => $this->redactPayload($options['json'] ?? []),
                'headers' => $options['headers'] ?? [],
            ]);

            $response = $this->httpClient->request($method, $url, $options + [
                'timeout' => 10.0,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->logger->error('Liansheng API request failed', [
                'method' => $method,
                'url' => $url,
                'exception' => $exception->getMessage(),
            ]);

            throw new LianshengApiException('Liansheng API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $this->logger->info('Liansheng API response', [
            'method' => $method,
            'url' => $url,
            'status' => $statusCode,
            'body' => $data,
        ]);

        if (!is_array($data)) {
            $this->logger->error('Liansheng API response is not a JSON object', [
                'method' => $method,
                'url' => $url,
                'status' => $statusCode,
                'body' => $content,
            ]);

            throw new LianshengApiException('Liansheng API response is not a JSON object.');
        }

        $code = $data['code'] ?? null;
        if ($code !== null && $code !== 0 && $code !== '0') {
            $this->logger->error('Liansheng API returned an error', [
                'method' => $method,
                'url' => $url,
                'status' => $statusCode,
                'code' => $code,
                'message' => is_string($data['msg'] ?? null) ? $data['msg'] : null,
                'body' => $data,
            ]);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new LianshengApiException(sprintf('Liansheng API returned HTTP %d.', $statusCode));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload): array
    {
        if (array_key_exists('appSecret', $payload)) {
            $payload['appSecret'] = '[redacted]';
        }

        return $payload;
    }
}
