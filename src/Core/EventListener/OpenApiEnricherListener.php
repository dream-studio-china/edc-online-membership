<?php
declare(strict_types=1);
namespace App\Core\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Post-processes /api/doc.json response to enrich tags, descriptions, and request bodies.
 * Single file — no controller changes needed.
 */
class OpenApiEnricherListener
{
    // META provides optional summaries/descriptions for key endpoints.
    // Tags are auto-detected from URL patterns — no need to list paths here just for tags.
    private const META = [
        '/api/auth/login' => ['summary' => ['post' => 'Login — identifier + password'], 'desc' => ['post' => 'Authenticate with email, username, or verified phone. Returns RS256 JWT access_token (7200s) and refresh_token (1yr).']],
        '/api/auth/otp/request' => ['summary' => ['post' => 'Request OTP via SMS'], 'desc' => ['post' => 'Sends 6-digit OTP via Alibaba Cloud SMS. Rate limit 60s. Dry-run in dev.']],
        '/api/auth/otp/verify' => ['summary' => ['post' => 'Verify OTP code'], 'desc' => ['post' => 'Verifies 6-digit code. login→tokens, verify_phone→marks verified. Max 5 attempts.']],
        '/api/auth/token/refresh' => ['summary' => ['post' => 'Refresh access token'], 'desc' => ['post' => 'Rotates refresh token. Reuse detection revokes ALL user tokens.']],
        '/api/auth/logout' => ['summary' => ['post' => 'Logout — revoke tokens']],
        '/api/wechat/miniapp/login' => ['tag' => 'Wechat', 'summary' => ['post' => 'WeChat Mini Program login'], 'desc' => ['post' => 'Exchange WeChat Mini Program js_code for openid/unionid, create or find the local User, and return JWT access and refresh tokens.']],
        '/api/wechat/miniapp/phone' => ['tag' => 'Wechat', 'summary' => ['post' => 'Bind WeChat Mini Program phone'], 'desc' => ['post' => 'Authenticated endpoint. Exchange WeChat getPhoneNumber code for the current user phone number and mark it verified.']],
        '/api/payment/notify/{payment}' => ['tag' => 'Payment', 'summary' => ['post' => 'Payment gateway notify callback'], 'desc' => ['post' => 'Public payment provider webhook endpoint. The {payment} path selects the registered payment gateway (for example wechat, wallet, mock). The gateway verifies the callback signature/payload and InvoiceService applies the notify result.']],

        '/api/v1/manage/products' => ['summary' => ['get' => 'List all products', 'post' => 'Create product(s)'], 'desc' => ['get' => 'Paginated. Supports @filter, @dql, @order, @select, @sort, @expands, @display.', 'post' => 'Single object or array for batch. ROLE_ADMIN.']],
        '/api/v1/manage/products/batch-update' => ['summary' => ['post' => 'Batch update/upsert products']],
        '/api/v1/manage/products/{id}' => ['summary' => ['get' => 'Get product detail', 'put' => 'Update product', 'delete' => 'Delete product']],
        '/api/v1/manage/products/{productId}/specifications' => ['summary' => ['get' => 'List specifications', 'post' => 'Create specification (SKU)'], 'desc' => ['post' => 'Price in cents (e.g. 699900 = ¥6999).']],
        '/api/v1/manage/products/{productId}/specifications/batch-update' => ['summary' => ['post' => 'Batch update specifications']],
        '/api/v1/manage/products/{productId}/specifications/{id}' => ['summary' => ['put' => 'Update specification', 'delete' => 'Delete specification']],

        '/api/v1/manage/orders' => ['summary' => ['get' => 'List all orders', 'post' => 'Create order (price calc)'], 'desc' => ['get' => 'Paginated. Filter: @filter=filter_entity.status=="paid"', 'post' => 'Pipeline: resolve specs → validate → compute prices → aggregate total. Cents.']],
        '/api/v1/manage/orders/batch-update' => ['summary' => ['post' => 'Batch update orders']],
        '/api/v1/manage/orders/{id}' => ['summary' => ['get' => 'Get order detail', 'put' => 'Update draft order', 'delete' => 'Delete draft order'], 'desc' => ['put' => 'Only draft orders. Non-draft → 400.', 'delete' => 'Only draft orders.']],
        '/api/v1/manage/orders/todo' => ['summary' => ['get' => 'Orders with pending actions']],
        '/api/v1/manage/orders/{id}/items' => ['summary' => ['get' => 'Get order line items']],
        '/api/v1/manage/orders/{id}/transitions' => ['summary' => ['get' => 'Available workflow transitions']],
        '/api/v1/manage/orders/{id}/do/{transition}' => ['summary' => ['post' => 'Execute workflow transition'], 'desc' => ['post' => 'State machine: draft→pending→confirmed→paid→fulfilled→completed. Cancel from draft/pending/confirmed.']],
        '/api/v1/manage/orders/{id}/pay' => ['summary' => ['post' => 'Pay for order (wallet)'], 'desc' => ['post' => 'User wallet → system wallet. Sets paidAt + paymentMethod. Applies pay transition. Order must be confirmed.']],
        '/api/v1/manage/orders/{id}/fulfill' => ['summary' => ['post' => 'Fulfill order (ship)'], 'desc' => ['post' => 'Sets tracking + address + fulfilledAt. Applies fulfill transition. Order must be paid.']],
        '/api/v1/manage/orders/{id}/refund' => ['summary' => ['post' => 'Refund order (wallet)'], 'desc' => ['post' => 'System wallet → user wallet. Sets refundedAt + reason. Applies refund transition. Order must be completed.']],
        '/api/v1/manage/orders/quote' => ['summary' => ['post' => 'Quote order price (manage)'], 'desc' => ['post' => 'Price preview without creating order. Same pipeline as create: resolve specs → validate → compute prices → aggregate total. Body: items[], currency, notes, metadata, meta.']],
        '/api/v1/manage/orders/{id}/payment' => ['summary' => ['post' => 'Pay order via gateway (manage)'], 'desc' => ['post' => 'Creates Invoice and pays via gateway (wallet|mock|wechat) with payment adjustments. Body: payment (string).']],

        '/api/v1/app/orders' => ['summary' => ['get' => 'List my orders', 'post' => 'Create order (self)'], 'desc' => ['post' => 'Auto-assigns current user.']],
        '/api/v1/app/orders/quote' => ['summary' => ['post' => 'Quote order price (app)'], 'desc' => ['post' => 'Price preview without creating order. Body: items[], currency, metadata, meta. Returns subtotal/discount.']],
        '/api/v1/app/orders/{id}/submit' => ['summary' => ['post' => 'Submit own order'], 'desc' => ['post' => 'Workflow submit: draft → pending.']],
        '/api/v1/app/orders/{id}/confirm' => ['summary' => ['post' => 'Confirm own order'], 'desc' => ['post' => 'Workflow confirm: pending → confirmed.']],
        '/api/v1/app/orders/{id}/payment' => ['summary' => ['post' => 'Pay order via gateway (app)'], 'desc' => ['post' => 'Pay own order via gateway (wallet|mock|wechat). Body: payment. Creates Invoice → PaymentGateway.']],
        '/api/v1/app/specifications/by-product/{productId}' => ['summary' => ['get' => 'List specifications by product'], 'desc' => ['get' => 'Returns active specifications for given product.']],
        '/api/v1/app/orders/{id}' => ['summary' => ['get' => 'Get order detail (own)'], 'desc' => ['get' => '404 if not authenticated user\'s order.']],
        '/api/v1/app/orders/{id}/items' => ['summary' => ['get' => 'Get order items (own)']],
        '/api/v1/app/orders/{id}/cancel' => ['summary' => ['post' => 'Cancel own order'], 'desc' => ['post' => 'Allowed: draft, pending, confirmed. Not paid+.']],
        '/api/v1/app/products' => ['summary' => ['get' => 'List active products (public)'], 'desc' => ['get' => 'Only active, non-deleted. No auth.']],
        '/api/v1/app/products/{id}' => ['summary' => ['get' => 'Get product detail (public)']],

        '/api/v1/manage/categories' => ['summary' => ['get' => 'List categories', 'post' => 'Create category']],
        '/api/v1/manage/categories/batch-update' => ['summary' => ['post' => 'Batch update categories']],
        '/api/v1/manage/categories/{id}' => ['summary' => ['get' => 'Get category', 'put' => 'Update category', 'delete' => 'Delete category']],
        '/api/v1/manage/tags' => ['summary' => ['get' => 'List tags', 'post' => 'Create tag']],
        '/api/v1/manage/tags/batch-update' => ['summary' => ['post' => 'Batch update tags']],
        '/api/v1/manage/tags/{id}' => ['summary' => ['get' => 'Get tag', 'put' => 'Update tag', 'delete' => 'Delete tag']],
        '/api/v1/manage/contents' => ['summary' => ['get' => 'List contents', 'post' => 'Create content']],
        '/api/v1/manage/contents/batch-update' => ['summary' => ['post' => 'Batch update contents']],
        '/api/v1/manage/contents/{id}' => ['summary' => ['get' => 'Get content', 'put' => 'Update content', 'delete' => 'Delete content']],
        '/api/v1/manage/comments' => ['summary' => ['get' => 'List comments', 'post' => 'Create comment']],
        '/api/v1/manage/comments/batch-update' => ['summary' => ['post' => 'Batch update comments']],
        '/api/v1/manage/comments/{id}' => ['summary' => ['get' => 'Get comment', 'put' => 'Update comment', 'delete' => 'Delete comment']],
        '/api/v1/manage/pages' => ['summary' => ['get' => 'List pages', 'post' => 'Create page']],
        '/api/v1/manage/pages/batch-update' => ['summary' => ['post' => 'Batch update pages']],
        '/api/v1/manage/pages/{id}' => ['summary' => ['get' => 'Get page', 'put' => 'Update page', 'delete' => 'Delete page']],
        '/api/v1/manage/media' => ['summary' => ['get' => 'List media', 'post' => 'Create media']],
        '/api/v1/manage/media/upload' => ['summary' => ['post' => 'Upload media file'], 'desc' => ['post' => 'Admin multipart upload endpoint. Reuses the same storage flow as the App endpoint, but Manage media listing/detail is not user-scoped. Use form field storage to select local or qiniu.']],
        '/api/v1/manage/media/batch-update' => ['summary' => ['post' => 'Batch update media']],
        '/api/v1/manage/media/{id}' => ['summary' => ['get' => 'Get media', 'put' => 'Update media', 'delete' => 'Delete media']],
        '/api/v1/manage/settings' => ['summary' => ['get' => 'List settings', 'post' => 'Create setting']],
        '/api/v1/manage/settings/batch-update' => ['summary' => ['post' => 'Batch update settings']],
        '/api/v1/manage/settings/{id}' => ['summary' => ['get' => 'Get setting', 'put' => 'Update setting', 'delete' => 'Delete setting']],

        '/api/v1/app/categories' => ['summary' => ['get' => 'List enabled categories (public)']],
        '/api/v1/app/categories/{id}' => ['summary' => ['get' => 'Get category (public)']],
        '/api/v1/app/tags' => ['summary' => ['get' => 'List tags (public)']],
        '/api/v1/app/tags/{id}' => ['summary' => ['get' => 'Get tag (public)']],
        '/api/v1/app/contents' => ['summary' => ['get' => 'List contents (public)']],
        '/api/v1/app/contents/{id}' => ['summary' => ['get' => 'Get content (public)']],
        '/api/v1/app/comments' => ['summary' => ['get' => 'List approved comments (public)', 'post' => 'Create comment (pending)']],
        '/api/v1/app/comments/{id}' => ['summary' => ['get' => 'Get comment (public)']],
        '/api/v1/app/pages' => ['summary' => ['get' => 'List published pages (public)']],
        '/api/v1/app/pages/{id}' => ['summary' => ['get' => 'Get page (public)']],
        '/api/v1/app/media' => ['summary' => ['get' => 'List my media'], 'desc' => ['get' => 'User-scoped media list. Returns only files owned by the authenticated user.']],
        '/api/v1/app/media/upload' => ['summary' => ['post' => 'Upload my media file'], 'desc' => ['post' => 'Authenticated multipart upload endpoint for the current user. Send the binary file in form field file. Optionally send storage=local or storage=qiniu to select the storage driver; if omitted, media.storage.default / MEDIA_STORAGE_DEFAULT is used. Optional metadata fields alt, title, width, and height are persisted on the Media entity. Local uploads are stored under public/uploads/{YYYYMM}/ and return a root-relative /uploads/... URL. Qiniu uploads require qiniu.* settings and qiniu/php-sdk to be installed on the server. Invalid files are rejected before any storage driver call.']],
        '/api/v1/app/media/{id}' => ['summary' => ['get' => 'Get my media'], 'desc' => ['get' => 'User-scoped media detail. Returns 404 when the media does not belong to the authenticated user.']],
        '/api/v1/public/media' => ['summary' => ['get' => 'List public media'], 'desc' => ['get' => 'Anonymous read-only media list. Returns only ownerless media where user IS NULL.']],
        '/api/v1/public/media/{id}' => ['summary' => ['get' => 'Get public media'], 'desc' => ['get' => 'Anonymous read-only media detail. Returns 404 for user-owned media because public media is limited to user IS NULL.']],
        '/api/v1/app/settings' => ['summary' => ['get' => 'List settings (public)']],
        '/api/v1/app/settings/{id}' => ['summary' => ['get' => 'Get setting (public)']],

        '/api/v1/manage/wallets' => ['summary' => ['get' => 'List wallets', 'post' => 'Create wallet'], 'desc' => ['post' => 'One wallet per user per currency. Balance starts at 0.']],
        '/api/v1/manage/wallets/batch-update' => ['summary' => ['post' => 'Batch update wallets']],
        '/api/v1/manage/wallets/{id}' => ['summary' => ['get' => 'Get wallet', 'put' => 'Update wallet (freeze/unfreeze)', 'delete' => 'Delete wallet']],
        '/api/v1/manage/wallets/balance' => ['summary' => ['get' => 'Global wallet balance audit'], 'desc' => ['get' => 'Sums all wallets. Returns totalBalance, totalDeposited, discrepancy. ROLE_ADMIN.']],
        '/api/v1/manage/wallets/reconcile' => ['summary' => ['post' => 'Reconcile wallet balances'], 'desc' => ['post' => 'Fixes per-wallet gaps with TYPE_ADJUSTMENT. ROLE_ADMIN.']],
        '/api/v1/app/wallets/balance' => ['summary' => ['get' => 'My wallet balance audit'], 'desc' => ['get' => 'Audits only current user wallets. Returns totalBalance, discrepancy.']],
        '/api/v1/manage/transactions' => ['summary' => ['get' => 'List wallet transactions', 'post' => 'Atomic wallet transfer'], 'desc' => ['post' => 'Atomic, deadlock-safe, idempotent (referenceId), currency match enforced. Cents.']],
        '/api/v1/manage/transactions/{id}' => ['summary' => ['get' => 'Get transaction detail']],
        '/api/v1/manage/vouchers/deposit' => ['summary' => ['post' => 'Voucher-backed deposit (manage)'], 'desc' => ['post' => 'Single-sided credit: fromWallet=null, voucher_type defaults to manual. Idempotent via referenceId. ROLE_ADMIN.']],
        '/api/v1/manage/vouchers/withdraw' => ['summary' => ['post' => 'Voucher-backed withdrawal (manage)'], 'desc' => ['post' => 'Single-sided debit: toWallet=null. Idempotent via referenceId. ROLE_ADMIN.']],
        '/api/v1/manage/vouchers/{uuid}/reverse' => ['summary' => ['post' => 'Reverse voucher (manage)'], 'desc' => ['post' => 'Returns funds to source wallet. credit_reversal / debit_reversal.']],
        '/api/v1/app/vouchers/deposit' => ['summary' => ['post' => 'Self-service deposit'], 'desc' => ['post' => 'Voucher-backed deposit into own wallet. voucherType required, provider-permissioned.']],
        '/api/v1/app/vouchers/withdraw' => ['summary' => ['post' => 'Self-service withdrawal'], 'desc' => ['post' => 'Voucher-backed withdrawal from own wallet. voucherType required.']],
        '/api/v1/app/vouchers/{uuid}/reverse' => ['summary' => ['post' => 'Reverse own voucher'], 'desc' => ['post' => 'Reverses own voucher by direction.']],
        '/api/v1/manage/invoices' => ['summary' => ['get' => 'List invoices', 'post' => 'Create invoice'], 'desc' => ['post' => 'Creates pending invoice for order/deposit/topup. amount in cents.']],
        '/api/v1/manage/invoices/{id}' => ['summary' => ['get' => 'Get invoice detail']],
        '/api/v1/manage/invoices/{id}/pay/{payment}' => ['summary' => ['post' => 'Pay invoice (manage)'], 'desc' => ['post' => 'Applies payment adjustments (wallet_balance) then calls gateway pay(payment, amount). payment in [mock,wallet,wechat].']],
        '/api/v1/manage/invoices/{id}/cancel' => ['summary' => ['post' => 'Cancel invoice'], 'desc' => ['post' => 'Only pending/paying invoices.']],
        '/api/v1/manage/invoices/{id}/refund' => ['summary' => ['post' => 'Refund invoice'], 'desc' => ['post' => 'Partial or full refund with reason. Updates refundedAmount.']],
        '/api/v1/manage/invoices/{id}/transitions' => ['summary' => ['get' => 'Invoice available transitions']],
        '/api/v1/app/invoices/{id}/pay/{payment}' => ['summary' => ['post' => 'Pay invoice (self)'], 'desc' => ['post' => 'User pays own invoice via gateway. Same adjustment pipeline as manage.']],
        '/api/v1/manage/stores' => ['summary' => ['get' => 'List stores', 'post' => 'Create store'], 'desc' => ['post' => 'Code must be unique. ROLE_ADMIN.']],
        '/api/v1/manage/stores/{uuid}/status/{status}' => ['summary' => ['post' => 'Change store status'], 'desc' => ['post' => 'Transitions: activate|suspend|close. ROLE_ADMIN.']],
        '/api/v1/manage/stores/{uuid}/members' => ['summary' => ['get' => 'List store members', 'post' => 'Grant store member'], 'desc' => ['get' => 'Returns membership list for store.', 'post' => 'Body: userUuid, role in [owner,manager,clerk,fulfillment]. ROLE_ADMIN.']],
        '/api/v1/app/stores' => ['summary' => ['get' => 'List my stores']],
        '/api/v1/app/stores/{id}' => ['summary' => ['get' => 'Get store detail']],
        '/api/v1/manage/store-orders' => ['summary' => ['get' => 'List store orders']],
        '/api/v1/app/store-orders' => ['summary' => ['get' => 'List my store orders']],
        '/api/v1/store/stores/{scopeId}/orders' => ['summary' => ['get' => 'List scoped store orders'], 'desc' => ['get' => 'Staff scoped list via store uuid. Requires store membership.']],
        '/api/v1/store/stores/{scopeId}/orders/{orderUuid}/accept' => ['summary' => ['post' => 'Accept store order'], 'desc' => ['post' => 'Staff accept pending order. Requires owner|manager|clerk.']],
        '/api/v1/store/stores/{scopeId}/orders/{orderUuid}/reject' => ['summary' => ['post' => 'Reject store order'], 'desc' => ['post' => 'Body: code, reason. Requires owner|manager|clerk.']],
        '/api/v1/store/stores/{scopeId}/orders/{orderUuid}/fulfill' => ['summary' => ['post' => 'Fulfill store order'], 'desc' => ['post' => 'Staff fulfillment step. Requires owner|manager|fulfillment.']],
        '/api/v1/manage/inventory/materials' => ['summary' => ['get' => 'List materials', 'post' => 'Create material'], 'desc' => ['post' => 'code unique, immutably frozen after stock mutation.']],
        '/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}' => ['summary' => ['get' => 'Get stock (virtual zero if absent)'], 'desc' => ['get' => 'Per-store per-material balance: onHand, reserved, allowNegativeStock.']],
        '/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/adjust' => ['summary' => ['post' => 'Adjust stock'], 'desc' => ['post' => 'Body: quantityDelta (string bcmath), reason, referenceId, allowNegativeStock. Append-only ledger.']],
        '/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy' => ['summary' => ['put' => 'Update stock policy'], 'desc' => ['put' => 'Body: allowNegativeStock bool. Per-store-material flag.']],
        '/api/v1/manage/inventory/recipes' => ['summary' => ['get' => 'List recipes', 'post' => 'Create recipe'], 'desc' => ['post' => 'One active recipe per specification UUID. Lines: materialUuid + quantityPerUnit.']],
        '/api/v1/manage/promotion-templates/{id}/validate' => ['summary' => ['post' => 'Validate promotion template DSL'], 'desc' => ['post' => 'Lexes/parses DSL, returns AST or errors.']],
        '/api/v1/manage/promotion-templates/{id}/dry-run' => ['summary' => ['post' => 'Dry-run promotion template'], 'desc' => ['post' => 'Evaluates DSL against sample order total and meta.']],
        '/api/v1/manage/settlement-rules/configuration' => ['summary' => ['get' => 'Get settlement rules configuration']],
        '/api/v1/manage/settlement-rule-versions/{uuid}/publish' => ['summary' => ['post' => 'Publish settlement rule version'], 'desc' => ['post' => 'Transitions draft → published.']],
        '/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/post' => ['summary' => ['post' => 'Post settlement allocation']],
        '/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/reverse' => ['summary' => ['post' => 'Reverse settlement allocation'], 'desc' => ['post' => 'Body: reason.']],
        '/api/v1/app/users/me' => ['summary' => ['get' => 'Get current user profile', 'put' => 'Update current user profile'], 'desc' => ['put' => 'Update email/username/phone, optional password.']],
        '/api/v1/app/users/change-password' => ['summary' => ['post' => 'Change own password'], 'desc' => ['post' => 'Requires current password.']],
        '/api/v1/manage/users/{id}/change-password' => ['summary' => ['post' => 'Admin change user password'], 'desc' => ['post' => 'ROLE_ADMIN. No current password required.']],
    ];

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();

        if ($pathInfo !== '/api/doc.json' && $pathInfo !== '/api/doc') {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return;
        }

        // /api/doc.json — raw JSON
        if ($pathInfo === '/api/doc.json' || str_starts_with(trim($content), '{')) {
            $spec = json_decode($content, true);
            if (!is_array($spec) || !isset($spec['paths'])) {
                return;
            }
            $spec = $this->enrich($spec);
            $encoded = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $response->setContent($encoded);
            }
            return;
        }

        // /api/doc — HTML with embedded <script id="swagger-data" type="application/json">...</script>
        $pattern = '#<script id="swagger-data" type="application/json">(.*?)</script>#s';
        if (preg_match($pattern, $content, $matches)) {
            $wrapper = json_decode($matches[1], true);
            if (is_array($wrapper) && isset($wrapper['spec'])) {
                $wrapper['spec'] = $this->enrich($wrapper['spec']);
                $newJson = json_encode($wrapper, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($newJson)) {
                $content = str_replace($matches[1], $newJson, $content);
                $response->setContent($content);
            }
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function enrich(array $spec): array
    {
        // Start with known tags; dynamically detected ones will be appended
        $spec['tags'] = $spec['tags'] ?? [];
        foreach ([
            ['name' => 'Auth', 'description' => 'Login, OTP, token refresh, logout'],
            ['name' => 'Products', 'description' => 'Product and Specification CRUD + public listing'],
            ['name' => 'Orders', 'description' => 'Order lifecycle: draft→pending→confirmed→paid→fulfilled→completed→refunded'],
            ['name' => 'Categories', 'description' => 'Hierarchical category management'],
            ['name' => 'Tags', 'description' => 'Flat tag/label system'],
            ['name' => 'Contents', 'description' => 'Article-like content'],
            ['name' => 'Comments', 'description' => 'Polymorphic comment system'],
            ['name' => 'Pages', 'description' => 'Standalone page management'],
            ['name' => 'Media', 'description' => 'File metadata management'],
            ['name' => 'Settings', 'description' => 'Key-value configuration'],
            ['name' => 'Pictures', 'description' => 'Picture management with category binding'],
            ['name' => 'Payment', 'description' => 'Payment invoices, gateways, refunds, and provider callbacks'],
            ['name' => 'Wallet', 'description' => 'Balance, transactions, atomic transfers, vouchers'],
            ['name' => 'Store', 'description' => 'Multi-store operations, membership, and store orders'],
            ['name' => 'Inventory', 'description' => 'Materials, stock, recipes, reservations, ledger'],
            ['name' => 'Promotions', 'description' => 'Promotion DSL and order discounts'],
            ['name' => 'PromotionTemplates', 'description' => 'Promotion template CRUD, validation, and dry-run'],
            ['name' => 'Settlement', 'description' => 'Settlement rules, plans, allocations, and outbox'],
            ['name' => 'System', 'description' => 'Entity metadata introspection and route listing'],
            ['name' => 'Wechat', 'description' => 'WeChat Mini Program / Official Account login and WeChat Pay'],
        ] as $t) {
            $this->ensureTag($spec['tags'], $t['name']);
        }

        foreach ($spec['paths'] as $path => &$methods) {
            // Pick the first operation to get the operationId (same route for all methods)
            $firstOp = null;
            foreach ($methods as $op) { if (is_array($op)) { $firstOp = $op; break; } }
            // Apply explicit overrides from META map (for custom summaries/descriptions)
            $meta = self::META[$path] ?? null;
            $tag = $meta['tag'] ?? $this->detectTag($firstOp ?? []);
            if ($tag === null) continue;

            foreach ($methods as $method => &$op) {
                if (!is_array($op)) continue;
                $op['tags'] = [$tag];
                $this->ensureTag($spec['tags'], $tag);
                if ($meta && isset($meta['summary'][$method])) $op['summary'] = $meta['summary'][$method];
                if ($meta && isset($meta['desc'][$method])) $op['description'] = $meta['desc'][$method];
                // Central requestBody injection — single place for all custom endpoints (no controller OA needed)
                if (!isset($op['requestBody'])) {
                    $body = $this->centralRequestBody($path, $method);
                    if ($body !== null) {
                        $op['requestBody'] = $body;
                    } elseif ($method === 'post' && in_array($path, ['/api/v1/app/media/upload', '/api/v1/manage/media/upload'], true)) {
                        $op['requestBody'] = $this->mediaUploadRequestBody();
                    }
                }
                // Ensure path parameters from URL template are documented
                $this->ensurePathParameters($op, $path);
                // Ensure at least a default success response
                if (!isset($op['responses']) || $op['responses'] === []) {
                    $op['responses'] = ['200' => ['description' => 'Success']];
                }
            }
            unset($op);
        }
        unset($methods);

        // Remove generic operation-type tags (List, Detail, Create, Update, Delete)
        // that come from View mixin OA attributes — we use module tags instead.
        $genericTags = ['List', 'Detail', 'Create', 'Update', 'Delete', 'Workflow'];
        $spec['tags'] = array_values(array_filter($spec['tags'], fn($t) => !in_array($t['name'], $genericTags, true)));

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaUploadRequestBody(): array
    {
        return [
            'required' => true,
            'content' => [
                'multipart/form-data' => [
                    'schema' => [
                        'type' => 'object',
                        'required' => ['file'],
                        'properties' => [
                            'file' => [
                                'type' => 'string',
                                'format' => 'binary',
                                'description' => 'File to upload. Default allowlist: image/jpeg, image/png, image/gif, image/webp, application/pdf. Default max size: 10 MB.',
                            ],
                            'storage' => [
                                'type' => 'string',
                                'enum' => ['local', 'qiniu'],
                                'default' => 'local',
                                'description' => 'Storage driver name. Omit to use media.storage.default / MEDIA_STORAGE_DEFAULT.',
                            ],
                            'category' => [
                                'type' => 'integer',
                                'description' => 'Optional common_category id to bind to the media. Invalid ids return Category is not found.',
                            ],
                            'alt' => ['type' => 'string', 'description' => 'Alternative text for images.'],
                            'title' => ['type' => 'string', 'description' => 'Display title.'],
                            'width' => ['type' => 'integer', 'description' => 'Optional explicit width. Images are auto-detected when omitted.'],
                            'height' => ['type' => 'integer', 'description' => 'Optional explicit height. Images are auto-detected when omitted.'],
                        ],
                    ],
                    'encoding' => [
                        'file' => ['contentType' => 'application/octet-stream'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Central requestBody definitions — single place for all custom endpoints.
     * No controller OA needed. References schemas defined in nelmio_api_doc.yaml.
     * @return array<string, mixed>|null
     */
    private function centralRequestBody(string $path, string $method): ?array
    {
        $key = $method.':'.$path;
        // Helper to wrap a schema or inline properties into a JSON requestBody
        $ref = fn(string $schema) => [
            'required' => true,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
        ];
        $inline = fn(array $props, array $req = [], bool $required = true) => [
            'required' => $required,
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props, 'required' => $req]]],
        ];
        return match ($key) {
            // ---- Trade: orders ----
            'post:/api/v1/manage/orders' => $ref('OrderCreate'),
            'post:/api/v1/manage/orders/quote' => $inline([
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['specificationId','quantity'], 'properties' => ['specificationId'=>['type'=>'integer'],'quantity'=>['type'=>'integer','minimum'=>1]]]],
                'currency' => ['type'=>'string','example'=>'CNY'],
                'notes' => ['type'=>'string'],
                'metadata' => ['type'=>'object'],
                'meta' => ['type'=>'object','description'=>'Opaque channel for calculators'],
                'user' => ['type'=>'integer','description'=>'User ID (admin only)'],
            ], ['items']),
            'put:/api/v1/manage/orders/{id}' => $ref('OrderUpdate'),
            'post:/api/v1/manage/orders/{id}/pay' => $inline(['systemWalletId'=>['type'=>'integer','example'=>1], 'paymentMethod'=>['type'=>'string','example'=>'wallet']], ['systemWalletId']),
            'post:/api/v1/manage/orders/{id}/payment' => $inline(['payment'=>['type'=>'string','enum'=>['wallet','mock','wechat'],'example'=>'wallet']], ['payment']),
            'post:/api/v1/manage/orders/{id}/fulfill' => $ref('OrderFulfill'),
            'post:/api/v1/manage/orders/{id}/refund' => $ref('OrderRefund'),
            'post:/api/v1/manage/orders/{id}/do/{transition}' => $inline(['data'=>['type'=>'object','description'=>'Optional transition payload']], [], false),
            'post:/api/v1/app/orders' => $inline([
                'items' => ['type'=>'array','items'=>['type'=>'object','required'=>['specificationId','quantity'],'properties'=>['specificationId'=>['type'=>'integer'],'quantity'=>['type'=>'integer']]]],
                'currency'=>['type'=>'string','example'=>'CNY'],
                'notes'=>['type'=>'string'],
                'metadata'=>['type'=>'object'],
                'meta'=>['type'=>'object'],
            ], ['items']),
            'post:/api/v1/app/orders/quote' => $inline([
                'items' => ['type'=>'array','items'=>['type'=>'object','required'=>['specificationId','quantity'],'properties'=>['specificationId'=>['type'=>'integer'],'quantity'=>['type'=>'integer']]]],
                'currency'=>['type'=>'string','example'=>'CNY'],
                'metadata'=>['type'=>'object'],
                'meta'=>['type'=>'object'],
            ], ['items']),
            'post:/api/v1/app/orders/{id}/payment' => $inline(['payment'=>['type'=>'string','enum'=>['wallet','mock','wechat'],'example'=>'wallet']], ['payment']),
            // submit/confirm/cancel have no body
            // ---- Payment: invoices ----
            'post:/api/v1/manage/invoices' => $ref('InvoiceCreate'),
            'post:/api/v1/manage/invoices/{id}/pay/{payment}' => $inline(['walletId'=>['type'=>'integer','description'=>'Wallet id for wallet_balance deduction'], 'amount'=>['type'=>'integer','description'=>'Optional deduction amount in cents']], [], false),
            'post:/api/v1/manage/invoices/{id}/cancel' => $inline(['reason'=>['type'=>'string','example'=>'User request']], [], false),
            'post:/api/v1/manage/invoices/{id}/refund' => $ref('InvoiceRefundRequest'),
            'post:/api/v1/app/invoices/{id}/pay/{payment}' => $inline(['walletId'=>['type'=>'integer'], 'amount'=>['type'=>'integer']], [], false),
            'post:/api/payment/notify/{payment}' => $inline(['payload'=>['type'=>'object','description'=>'Gateway-specific callback payload (e.g. WeChat Pay V3)']], [], false),
            // ---- Wallet: vouchers ----
            'post:/api/v1/manage/vouchers/deposit' => $ref('VoucherDepositRequest'),
            'post:/api/v1/manage/vouchers/withdraw' => $ref('VoucherWithdrawRequest'),
            'post:/api/v1/manage/vouchers/{uuid}/reverse' => $inline(['reason'=>['type'=>'string','example'=>'Reversal reason']], [], false),
            'post:/api/v1/app/vouchers/deposit' => $inline(['walletId'=>['type'=>'integer'],'amount'=>['type'=>'integer','minimum'=>1],'currency'=>['type'=>'string','example'=>'CNY'],'voucherType'=>['type'=>'string','example'=>'manual'],'voucherId'=>['type'=>'string'],'referenceId'=>['type'=>'string'],'reason'=>['type'=>'string']], ['walletId','amount','voucherType','voucherId','referenceId']),
            'post:/api/v1/app/vouchers/withdraw' => $inline(['walletId'=>['type'=>'integer'],'amount'=>['type'=>'integer'],'voucherType'=>['type'=>'string'],'voucherId'=>['type'=>'string'],'referenceId'=>['type'=>'string'],'reason'=>['type'=>'string']], ['walletId','amount','voucherType','voucherId','referenceId']),
            'post:/api/v1/app/vouchers/{uuid}/reverse' => $inline(['reason'=>['type'=>'string']], [], false),
            'post:/api/v1/manage/wallets/reconcile' => $inline([], [], false),
            // ---- Store ----
            'post:/api/v1/manage/stores/{uuid}/status/{status}' => $inline([], [], false),
            'post:/api/v1/manage/stores/{uuid}/members' => $inline(['userUuid'=>['type'=>'string','format'=>'uuid','example'=>'550e8400-e29b-41d4-a716-446655440000'],'role'=>['type'=>'string','enum'=>['owner','manager','clerk','fulfillment'],'example'=>'manager']], ['userUuid','role']),
            'post:/api/v1/store/stores/{scopeId}/orders/{orderUuid}/accept' => $inline(['reservationId'=>['type'=>'string','description'=>'Optional reservation id']], [], false),
            'post:/api/v1/store/stores/{scopeId}/orders/{orderUuid}/reject' => $inline(['code'=>['type'=>'string','example'=>'out_of_stock'],'reason'=>['type'=>'string','example'=>'Insufficient stock']], ['code','reason']),
            'post:/api/v1/store/stores/{scopeId}/orders/{orderUuid}/fulfill' => $inline(['fulfillmentData'=>['type'=>'object','description'=>'Optional fulfillment payload']], [], false),
            // ---- Inventory ----
            'post:/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/adjust' => $inline(['quantityDelta'=>['type'=>'string','example'=>'10.000','description'=>'BCMath string'],'reason'=>['type'=>'string'],'referenceId'=>['type'=>'string'],'allowNegativeStock'=>['type'=>'boolean']], ['quantityDelta','reason']),
            'put:/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy' => $inline(['allowNegativeStock'=>['type'=>'boolean']], ['allowNegativeStock']),
            'post:/api/v1/manage/inventory/recipes' => $inline(['specificationUuid'=>['type'=>'string','format'=>'uuid'],'lines'=>['type'=>'array','items'=>['type'=>'object','required'=>['materialUuid','quantityPerUnit'],'properties'=>['materialUuid'=>['type'=>'string','format'=>'uuid'],'quantityPerUnit'=>['type'=>'string','example'=>'2.500'],'sort'=>['type'=>'integer']]]]], ['specificationUuid','lines']),
            // ---- Promotion ----
            'post:/api/v1/manage/promotion-templates/{id}/dry-run' => $inline(['order'=>['type'=>'object','description'=>'Sample order'],'meta'=>['type'=>'object']], [], false),
            // validate has no body
            // ---- Settlement ----
            'post:/api/v1/manage/settlement-rule-versions/{uuid}/publish' => $inline([], [], false),
            'post:/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/post' => $inline([], [], false),
            'post:/api/v1/manage/settlement-plans/{uuid}/allocations/{allocationUuid}/reverse' => $inline(['reversalId'=>['type'=>'string'],'reasonCode'=>['type'=>'string'],'reasonDetail'=>['type'=>'string'],'reason'=>['type'=>'string']], ['reason']),
            // ---- Identity ----
            'put:/api/v1/app/users/me' => $inline(['email'=>['type'=>'string','format'=>'email'],'username'=>['type'=>'string'],'phone'=>['type'=>'string','nullable'=>true],'password'=>['type'=>'string','format'=>'password','nullable'=>true]], [], false),
            'post:/api/v1/app/users/change-password' => $inline(['currentPassword'=>['type'=>'string','format'=>'password'],'newPassword'=>['type'=>'string','format'=>'password']], ['currentPassword','newPassword']),
            'post:/api/v1/manage/users/{id}/change-password' => $inline(['newPassword'=>['type'=>'string','format'=>'password']], ['newPassword']),
            // ---- Media upload handled separately ----
            default => null,
        };
    }

    /**
     * Ensure every {placeholder} in the path has a corresponding path parameter documentation.
     * @param array<string, mixed> $operation
     */
    private function ensurePathParameters(array &$operation, string $path): void
    {
        if (!preg_match_all('/\{(\w+)\}/', $path, $m)) return;
        $placeholders = $m[1];
        $existing = [];
        foreach ($operation['parameters'] ?? [] as $p) {
            if (($p['name'] ?? null) && ($p['in'] ?? null) === 'path') $existing[] = $p['name'];
        }
        foreach ($placeholders as $name) {
            if (in_array($name, $existing, true)) continue;
            $operation['parameters'][] = ['name'=>$name,'in'=>'path','required'=>true,'schema'=>['type'=>'string']];
        }
    }

    /**
     * Auto-detect module tag from the route name (operationId).
     * Route naming convention: {scope}-{resource}-{action}
     *   e.g. manage-products-list → Products, app-orders-create → Orders
     *
     * Known resources are matched explicitly. Unknown resources are
     * title-cased from the route prefix automatically.
     * @param array<string, mixed> $operation
     */
    private function detectTag(array $operation): ?string
    {
        $opId = $operation['operationId'] ?? '';
        if ($opId === '') return null;

        // Auth routes use a special prefix
        if (str_contains($opId, 'sys-auth')) return 'Auth';

        // System routes: system-entity-*, system-router-* (operationId is "{method}_system-..." )
        if (str_contains($opId, 'system-')) return 'System';

        // Wechat routes: wechat-* (operationId is "{method}_wechat-..." )
        if (str_contains($opId, 'wechat-')) return 'Wechat';

        if (str_contains($opId, 'store-')) return 'Store';

        // Extract resource name: {scope}-{resource} or {scope}-{resource}-{action}
        if (preg_match('/(?:manage|app|public)-([a-z][a-z0-9_]*)(?:-|$)/', $opId, $m)) {
            $resource = $m[1];

            // Map resource names to display names (supports both singular and plural forms)
            $known = [
                'product' => 'Products', 'products' => 'Products',
                'specification' => 'Products', 'specifications' => 'Products',
                'order' => 'Orders', 'orders' => 'Orders',
                'category' => 'Categories', 'categories' => 'Categories',
                'tag' => 'Tags', 'tags' => 'Tags',
                'content' => 'Contents', 'contents' => 'Contents',
                'comment' => 'Comments', 'comments' => 'Comments',
                'page' => 'Pages', 'pages' => 'Pages',
                'media' => 'Media',
                'setting' => 'Settings', 'settings' => 'Settings',
                'picture' => 'Pictures', 'pictures' => 'Pictures',
                'wallet' => 'Wallet', 'wallets' => 'Wallet',
                'transaction' => 'Wallet', 'transactions' => 'Wallet',
                'transfer' => 'Wallet', 'transfers' => 'Wallet',
                'voucher' => 'Wallet', 'vouchers' => 'Wallet',
                'voucher_comment' => 'Wallet', 'voucher_comments' => 'Wallet',
                'payment_deduction' => 'Wallet', 'payment_deductions' => 'Wallet',
                'invoice' => 'Payment', 'invoices' => 'Payment',
                'store' => 'Store', 'stores' => 'Store',
                'store_order' => 'Store', 'store_orders' => 'Store',
                'material' => 'Inventory', 'materials' => 'Inventory',
                'stock' => 'Inventory', 'stocks' => 'Inventory',
                'recipe' => 'Inventory', 'recipes' => 'Inventory',
                'promotion' => 'Promotions', 'promotions' => 'Promotions',
                'promotion_template' => 'PromotionTemplates', 'promotion_templates' => 'PromotionTemplates',
                'settlement_rule' => 'Settlement', 'settlement_rules' => 'Settlement',
                'settlement_rule_version' => 'Settlement', 'settlement_rule_versions' => 'Settlement',
                'settlement_plan' => 'Settlement', 'settlement_plans' => 'Settlement',
                'settlement_allocation' => 'Settlement', 'settlement_allocations' => 'Settlement',
                'settlement_outbox_message' => 'Settlement', 'settlement_outbox_messages' => 'Settlement',
                'settlement_consumed_event' => 'Settlement', 'settlement_consumed_events' => 'Settlement',
                'user' => 'Auth', 'users' => 'Auth',
                'profile' => 'Auth', 'profiles' => 'Auth',
                'wechat_user' => 'Wechat', 'wechat_users' => 'Wechat',
            ];
            if (isset($known[$resource])) return $known[$resource];

            // Unknown resource — auto-title-case
            return str_replace('_', ' ', ucfirst($resource));
        }

        return null;
    }

    /**
     * Ensure dynamically detected tags appear in the spec's tag list.
     * @param array<mixed, array<string, string>> $tags
     */
    private function ensureTag(array &$tags, string $name): void
    {
        foreach ($tags as $t) {
            if ($t['name'] === $name) return;
        }
        $tags[] = ['name' => $name, 'description' => ''];
    }
}
