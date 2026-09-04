<?php

declare(strict_types=1);

namespace App\Store\Controller\Concerns;

use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderDirectVerifyService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared direct-verify logic for Staff and Manage controllers.
 * Keeps business rule in StoreOrderDirectVerifyService, controller only handles HTTP.
 */
trait StoreOrderDirectVerifyTrait
{
    abstract protected function getDirectVerifyService(): StoreOrderDirectVerifyService;

    /**
     * Handles direct verification from any status after paid but before verified.
     * Uses order number (StoreOrder uuid) as verification, no code required.
     */
    protected function handleDirectVerify(Request $request, StoreOrder $storeOrder): Response
    {
        if ($storeOrder->getVerifiedAt() !== null) {
            return $this->warning('Store order already verified.', 400, '', 400);
        }

        if (!$this->getDirectVerifyService()->isAllowedStoreStatus($storeOrder)) {
            return $this->warning('Store order cannot be verified in its current status.', 400, '', 400);
        }

        $user = $this->getUser();
        $verifiedBy = null;
        if ($user !== null && method_exists($user, 'getUuid')) {
            $verifiedBy = $user->getUuid();
        }

        try {
            $this->getDirectVerifyService()->directVerify($storeOrder, $verifiedBy);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\LogicException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($storeOrder, 'Store order verified.');
    }
}
