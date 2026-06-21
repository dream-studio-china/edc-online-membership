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
    private const META = [
        '/api/auth/login' => ['tags' => ['Auth'], 'summary' => ['post' => 'Login — identifier + password'], 'desc' => ['post' => 'Authenticate with email, username, or verified phone. Returns RS256 JWT access_token (7200s) and refresh_token (1yr).']],
        '/api/auth/otp/request' => ['tags' => ['Auth'], 'summary' => ['post' => 'Request OTP via SMS'], 'desc' => ['post' => 'Sends 6-digit OTP via Alibaba Cloud SMS. Rate limit 60s. Dry-run in dev.']],
        '/api/auth/otp/verify' => ['tags' => ['Auth'], 'summary' => ['post' => 'Verify OTP code'], 'desc' => ['post' => 'Verifies 6-digit code. login→tokens, verify_phone→marks verified. Max 5 attempts.']],
        '/api/auth/token/refresh' => ['tags' => ['Auth'], 'summary' => ['post' => 'Refresh access token'], 'desc' => ['post' => 'Rotates refresh token. Reuse detection revokes ALL user tokens.']],
        '/api/auth/logout' => ['tags' => ['Auth'], 'summary' => ['post' => 'Logout — revoke tokens']],

        '/api/v1/manage/products' => ['tags' => ['Products'], 'summary' => ['get' => 'List all products', 'post' => 'Create product(s)'], 'desc' => ['get' => 'Paginated. Supports @filter, @dql, @order, @select, @sort, @expands, @display.', 'post' => 'Single object or array for batch. ROLE_ADMIN.']],
        '/api/v1/manage/products/batch-update' => ['tags' => ['Products'], 'summary' => ['post' => 'Batch update/upsert products']],
        '/api/v1/manage/products/{id}' => ['tags' => ['Products'], 'summary' => ['get' => 'Get product detail', 'put' => 'Update product', 'delete' => 'Delete product']],
        '/api/v1/manage/products/{productId}/specifications' => ['tags' => ['Products'], 'summary' => ['get' => 'List specifications', 'post' => 'Create specification (SKU)'], 'desc' => ['post' => 'Price in cents (e.g. 699900 = ¥6999).']],
        '/api/v1/manage/products/{productId}/specifications/batch-update' => ['tags' => ['Products'], 'summary' => ['post' => 'Batch update specifications']],
        '/api/v1/manage/products/{productId}/specifications/{id}' => ['tags' => ['Products'], 'summary' => ['put' => 'Update specification', 'delete' => 'Delete specification']],

        '/api/v1/manage/orders' => ['tags' => ['Orders'], 'summary' => ['get' => 'List all orders', 'post' => 'Create order (price calc)'], 'desc' => ['get' => 'Paginated. Filter: @filter=filter_entity.status=="paid"', 'post' => 'Pipeline: resolve specs → validate → compute prices → aggregate total. Cents.']],
        '/api/v1/manage/orders/batch-update' => ['tags' => ['Orders'], 'summary' => ['post' => 'Batch update orders']],
        '/api/v1/manage/orders/{id}' => ['tags' => ['Orders'], 'summary' => ['get' => 'Get order detail', 'put' => 'Update draft order', 'delete' => 'Delete draft order'], 'desc' => ['put' => 'Only draft orders. Non-draft → 400.', 'delete' => 'Only draft orders.']],
        '/api/v1/manage/orders/todo' => ['tags' => ['Orders'], 'summary' => ['get' => 'Orders with pending actions']],
        '/api/v1/manage/orders/{id}/items' => ['tags' => ['Orders'], 'summary' => ['get' => 'Get order line items']],
        '/api/v1/manage/orders/{id}/transitions' => ['tags' => ['Orders'], 'summary' => ['get' => 'Available workflow transitions']],
        '/api/v1/manage/orders/{id}/do/{transition}' => ['tags' => ['Orders'], 'summary' => ['post' => 'Execute workflow transition'], 'desc' => ['post' => 'State machine: draft→pending→confirmed→paid→fulfilled→completed. Cancel from draft/pending/confirmed.']],
        '/api/v1/manage/orders/{id}/pay' => ['tags' => ['Orders'], 'summary' => ['post' => 'Pay for order (wallet)'], 'desc' => ['post' => 'User wallet → system wallet. Sets paidAt + paymentMethod. Applies pay transition. Order must be confirmed.']],
        '/api/v1/manage/orders/{id}/fulfill' => ['tags' => ['Orders'], 'summary' => ['post' => 'Fulfill order (ship)'], 'desc' => ['post' => 'Sets tracking + address + fulfilledAt. Applies fulfill transition. Order must be paid.']],
        '/api/v1/manage/orders/{id}/refund' => ['tags' => ['Orders'], 'summary' => ['post' => 'Refund order (wallet)'], 'desc' => ['post' => 'System wallet → user wallet. Sets refundedAt + reason. Applies refund transition. Order must be completed.']],

        '/api/v1/app/orders' => ['tags' => ['Orders'], 'summary' => ['get' => 'List my orders', 'post' => 'Create order (self)'], 'desc' => ['post' => 'Auto-assigns current user.']],
        '/api/v1/app/orders/{id}' => ['tags' => ['Orders'], 'summary' => ['get' => 'Get order detail (own)'], 'desc' => ['get' => '404 if not authenticated user\'s order.']],
        '/api/v1/app/orders/{id}/items' => ['tags' => ['Orders'], 'summary' => ['get' => 'Get order items (own)']],
        '/api/v1/app/orders/{id}/cancel' => ['tags' => ['Orders'], 'summary' => ['post' => 'Cancel own order'], 'desc' => ['post' => 'Allowed: draft, pending, confirmed. Not paid+.']],
        '/api/v1/app/products' => ['tags' => ['Products'], 'summary' => ['get' => 'List active products (public)'], 'desc' => ['get' => 'Only active, non-deleted. No auth.']],
        '/api/v1/app/products/{id}' => ['tags' => ['Products'], 'summary' => ['get' => 'Get product detail (public)']],

        '/api/v1/manage/categories' => ['tags' => ['Categories'], 'summary' => ['get' => 'List categories', 'post' => 'Create category']],
        '/api/v1/manage/categories/batch-update' => ['tags' => ['Categories'], 'summary' => ['post' => 'Batch update categories']],
        '/api/v1/manage/categories/{id}' => ['tags' => ['Categories'], 'summary' => ['get' => 'Get category', 'put' => 'Update category', 'delete' => 'Delete category']],
        '/api/v1/manage/tags' => ['tags' => ['Tags'], 'summary' => ['get' => 'List tags', 'post' => 'Create tag']],
        '/api/v1/manage/tags/batch-update' => ['tags' => ['Tags'], 'summary' => ['post' => 'Batch update tags']],
        '/api/v1/manage/tags/{id}' => ['tags' => ['Tags'], 'summary' => ['get' => 'Get tag', 'put' => 'Update tag', 'delete' => 'Delete tag']],
        '/api/v1/manage/contents' => ['tags' => ['Contents'], 'summary' => ['get' => 'List contents', 'post' => 'Create content']],
        '/api/v1/manage/contents/batch-update' => ['tags' => ['Contents'], 'summary' => ['post' => 'Batch update contents']],
        '/api/v1/manage/contents/{id}' => ['tags' => ['Contents'], 'summary' => ['get' => 'Get content', 'put' => 'Update content', 'delete' => 'Delete content']],
        '/api/v1/manage/comments' => ['tags' => ['Comments'], 'summary' => ['get' => 'List comments', 'post' => 'Create comment']],
        '/api/v1/manage/comments/batch-update' => ['tags' => ['Comments'], 'summary' => ['post' => 'Batch update comments']],
        '/api/v1/manage/comments/{id}' => ['tags' => ['Comments'], 'summary' => ['get' => 'Get comment', 'put' => 'Update comment', 'delete' => 'Delete comment']],
        '/api/v1/manage/pages' => ['tags' => ['Pages'], 'summary' => ['get' => 'List pages', 'post' => 'Create page']],
        '/api/v1/manage/pages/batch-update' => ['tags' => ['Pages'], 'summary' => ['post' => 'Batch update pages']],
        '/api/v1/manage/pages/{id}' => ['tags' => ['Pages'], 'summary' => ['get' => 'Get page', 'put' => 'Update page', 'delete' => 'Delete page']],
        '/api/v1/manage/media' => ['tags' => ['Media'], 'summary' => ['get' => 'List media', 'post' => 'Create media']],
        '/api/v1/manage/media/batch-update' => ['tags' => ['Media'], 'summary' => ['post' => 'Batch update media']],
        '/api/v1/manage/media/{id}' => ['tags' => ['Media'], 'summary' => ['get' => 'Get media', 'put' => 'Update media', 'delete' => 'Delete media']],
        '/api/v1/manage/settings' => ['tags' => ['Settings'], 'summary' => ['get' => 'List settings', 'post' => 'Create setting']],
        '/api/v1/manage/settings/batch-update' => ['tags' => ['Settings'], 'summary' => ['post' => 'Batch update settings']],
        '/api/v1/manage/settings/{id}' => ['tags' => ['Settings'], 'summary' => ['get' => 'Get setting', 'put' => 'Update setting', 'delete' => 'Delete setting']],

        '/api/v1/app/categories' => ['tags' => ['Categories'], 'summary' => ['get' => 'List enabled categories (public)']],
        '/api/v1/app/categories/{id}' => ['tags' => ['Categories'], 'summary' => ['get' => 'Get category (public)']],
        '/api/v1/app/tags' => ['tags' => ['Tags'], 'summary' => ['get' => 'List tags (public)']],
        '/api/v1/app/tags/{id}' => ['tags' => ['Tags'], 'summary' => ['get' => 'Get tag (public)']],
        '/api/v1/app/contents' => ['tags' => ['Contents'], 'summary' => ['get' => 'List contents (public)']],
        '/api/v1/app/contents/{id}' => ['tags' => ['Contents'], 'summary' => ['get' => 'Get content (public)']],
        '/api/v1/app/comments' => ['tags' => ['Comments'], 'summary' => ['get' => 'List approved comments (public)', 'post' => 'Create comment (pending)']],
        '/api/v1/app/comments/{id}' => ['tags' => ['Comments'], 'summary' => ['get' => 'Get comment (public)']],
        '/api/v1/app/pages' => ['tags' => ['Pages'], 'summary' => ['get' => 'List published pages (public)']],
        '/api/v1/app/pages/{id}' => ['tags' => ['Pages'], 'summary' => ['get' => 'Get page (public)']],
        '/api/v1/app/media' => ['tags' => ['Media'], 'summary' => ['get' => 'List media (public)']],
        '/api/v1/app/media/{id}' => ['tags' => ['Media'], 'summary' => ['get' => 'Get media (public)']],
        '/api/v1/app/settings' => ['tags' => ['Settings'], 'summary' => ['get' => 'List settings (public)']],
        '/api/v1/app/settings/{id}' => ['tags' => ['Settings'], 'summary' => ['get' => 'Get setting (public)']],

        '/api/v1/manage/wallets' => ['tags' => ['Wallet'], 'summary' => ['get' => 'List wallets', 'post' => 'Create wallet'], 'desc' => ['post' => 'One wallet per user per currency. Balance starts at 0.']],
        '/api/v1/manage/wallets/batch-update' => ['tags' => ['Wallet'], 'summary' => ['post' => 'Batch update wallets']],
        '/api/v1/manage/wallets/{id}' => ['tags' => ['Wallet'], 'summary' => ['get' => 'Get wallet', 'put' => 'Update wallet (freeze/unfreeze)', 'delete' => 'Delete wallet']],
        '/api/v1/manage/transactions' => ['tags' => ['Wallet'], 'summary' => ['get' => 'List wallet transactions']],
        '/api/v1/manage/transactions/{id}' => ['tags' => ['Wallet'], 'summary' => ['get' => 'Get transaction detail']],
        '/api/v1/manage/transfer' => ['tags' => ['Wallet'], 'summary' => ['post' => 'Atomic wallet transfer'], 'desc' => ['post' => 'Atomic, deadlock-safe, idempotent (referenceId), currency match enforced. Cents.']],
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
            $response->setContent(json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        // /api/doc — HTML with embedded <script id="swagger-data" type="application/json">...</script>
        $pattern = '#<script id="swagger-data" type="application/json">(.*?)</script>#s';
        if (preg_match($pattern, $content, $matches)) {
            $wrapper = json_decode($matches[1], true);
            if (is_array($wrapper) && isset($wrapper['spec'])) {
                $wrapper['spec'] = $this->enrich($wrapper['spec']);
                $newJson = json_encode($wrapper, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $content = str_replace($matches[1], $newJson, $content);
                $response->setContent($content);
            }
        }
    }

    private function enrich(array $spec): array
    {
        foreach ($spec['paths'] as $path => &$methods) {
            $meta = self::META[$path] ?? null;
            if ($meta === null) continue;

            foreach ($methods as $method => &$op) {
                if (!is_array($op)) continue;
                if (isset($meta['tags'])) $op['tags'] = $meta['tags'];
                if (isset($meta['summary'][$method])) $op['summary'] = $meta['summary'][$method];
                if (isset($meta['desc'][$method])) $op['description'] = $meta['desc'][$method];
            }
            unset($op);
        }
        unset($methods);

        $spec['tags'] = [
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
            ['name' => 'Wallet', 'description' => 'Balance, transactions, atomic transfers'],
        ];

        return $spec;
    }
}
