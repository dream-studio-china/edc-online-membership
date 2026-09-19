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
     * @return array<string, mixed> 0704 paginated member point ledger.
     */
    public function getMemberScoreBook(?string $mobile = null, ?string $vipId = null): array;

    /**
     * Deduct member points once through API 0703.
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

    /**
     * Credit member points once through API 0703.
     *
     * @return array<string, mixed> Raw vendor response envelope, or an empty array.
     */
    public function creditMemberPoints(
        string $mobile,
        int $points,
        string $reference,
        string $remarks = '',
        ?\DateTimeInterface $accountDate = null,
        ?\DateTimeInterface $expiryDate = null,
        string $roomTable = 'ONLINE',
    ): array;

    public function getPointRefundExpiryDate(): \DateTimeImmutable;
}
