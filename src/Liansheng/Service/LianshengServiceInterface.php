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
}
