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
     * Only requires verificationCode (defaults to StoreOrder uuid).
     */
    protected function handleDirectVerify(Request $request, StoreOrder $storeOrder): Response
    {
        if ($storeOrder->getVerifiedAt() !== null) {
            return $this->warning('Store order already verified.', 400, '', 400);
        }

        // Allow broader statuses: accepted, fulfillment_pending, fulfilling, fulfilled (after paid)
        // The service will enforce strict check, we give a friendly warning here
        if (!$this->getDirectVerifyService()->isAllowedStoreStatus($storeOrder)) {
            return $this->warning('Store order cannot be verified in its current status.', 400, '', 400);
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $verificationCode = $data['verificationCode'] ?? null;
        if ($verificationCode !== null && !is_string($verificationCode)) {
            return $this->warning('verificationCode must be a string.', 400, '', 400);
        }
        $verificationCode = is_string($verificationCode) ? trim($verificationCode) : null;
        if ($verificationCode !== null && $verificationCode !== '' && strlen($verificationCode) > 64) {
            return $this->warning('verificationCode must not exceed 64 characters.', 400, '', 400);
        }
        // Default to uuid if not provided - handled in service as well
        if ($verificationCode === null || $verificationCode === '') {
            $verificationCode = $storeOrder->getUuid();
        }

        $user = $this->getUser();
        $verifiedBy = null;
        if ($user !== null && method_exists($user, 'getUuid')) {
            $verifiedBy = $user->getUuid();
        }

        try {
            $this->getDirectVerifyService()->directVerify($storeOrder, $verificationCode, $verifiedBy);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\LogicException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($storeOrder, 'Store order verified.');
    }
}
