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

        '/api/v1/app/orders' => ['summary' => ['get' => 'List my orders', 'post' => 'Create order (self)'], 'desc' => ['post' => 'Auto-assigns current user.']],
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
        '/api/v1/manage/transactions' => ['summary' => ['get' => 'List wallet transactions']],
        '/api/v1/manage/transactions/{id}' => ['summary' => ['get' => 'Get transaction detail']],
        '/api/v1/manage/transfer' => ['summary' => ['post' => 'Atomic wallet transfer'], 'desc' => ['post' => 'Atomic, deadlock-safe, idempotent (referenceId), currency match enforced. Cents.']],
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
            ['name' => 'Payment', 'description' => 'Payment invoices, gateways, refunds, and provider callbacks'],
            ['name' => 'Wallet', 'description' => 'Balance, transactions, atomic transfers'],
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
                if ($method === 'post' && in_array($path, ['/api/v1/app/media/upload', '/api/v1/manage/media/upload'], true)) {
                    $op['requestBody'] = $this->mediaUploadRequestBody();
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

        // System routes: system-entity-*, system-router-*
        if (str_starts_with($opId, 'system-')) return 'System';

        // Wechat routes: wechat-*
        if (str_starts_with($opId, 'wechat-')) return 'Wechat';

        if (str_starts_with($opId, 'store-')) return 'Store';

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
                'wallet' => 'Wallet', 'wallets' => 'Wallet',
                'transaction' => 'Wallet', 'transactions' => 'Wallet',
                'transfer' => 'Wallet', 'transfers' => 'Wallet',
                'store' => 'Store', 'stores' => 'Store',
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
