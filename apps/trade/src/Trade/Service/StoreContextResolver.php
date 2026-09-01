<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Trade\DTO\StoreContext;
use App\Trade\Repository\TradeStoreDirectoryRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class StoreContextResolver implements StoreContextResolverInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private TradeStoreDirectoryRepository $directory,
    ) {
    }

    public function resolve(): ?StoreContext
    {
        $request = $this->requestStack->getCurrentRequest();
        $code = $request?->headers->get('X-Store-Code');
        if ($code === null || trim($code) === '') {
            return null;
        }

        $store = $this->directory->findActiveByCode($code);
        if ($store === null) {
            throw new \RuntimeException('Store is not available.');
        }

        return new StoreContext(
            $store->getStoreUuid(),
            $store->getCode(),
            $store->getName(),
            $request->headers->get('X-Store-Channel', 'api'),
        );
    }
}
