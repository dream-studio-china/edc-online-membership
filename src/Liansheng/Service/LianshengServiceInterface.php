<?php

declare(strict_types=1);

namespace App\Liansheng\Service;

interface LianshengServiceInterface
{
    /**
     * @return array<string, mixed> 0102 store token data, including storeId/storeCode/storeName.
     */
    public function getStore(): array;

    /**
     * @return array<string, mixed> 0504 business revenue report.
     */
    public function getBusinessRevenueReport(\DateTimeInterface $beginDate, \DateTimeInterface $endDate): array;

    /**
     * @return array<int|string, mixed> 0701 member profile or profile list.
     */
    public function getMemberByMobile(string $mobile): array;

    /**
     * Registers a member through API 0702.
     *
     * @return array<string, mixed> Raw vendor response envelope, or an empty array.
     */
    public function registerMemberByMobile(string $mobile): array;

    /**
     * @return array<int|string, mixed> Available member card types from API getvipcardtype.
     */
    public function getMemberCardTypes(): array;

    /**
     * @return array<string, mixed> 0704 paginated member point ledger.
     */
    public function getMemberScoreBook(?string $mobile = null, ?string $vipId = null): array;

    /**
     * Deduct member points once through API 0703.
     *
     * 0703 only deducts via the `score` field; crediting is not supported by the
     * vendor, so there is no credit method and no refund path. The adjustment is
     * write-verified against the member balance; a mismatch throws instead of
     * reporting success.
     *
     * @return array<string, mixed> Raw vendor response envelope, or an empty array.
     */
    public function deductMemberPoints(
        string $mobile,
        int $points,
        string $reference,
        string $remarks = '',
        ?\DateTimeInterface $accountDate = null,
        string $roomTable = 'ONLINE',
    ): array;
}
