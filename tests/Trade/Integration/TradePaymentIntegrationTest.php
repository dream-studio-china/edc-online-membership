<?php

declare(strict_types=1);

namespace App\Tests\Trade\Integration;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class TradePaymentIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    public function testOrderPaymentAndRefundThroughInvoiceEvents(): void
    {
        $productId = $this->createProduct();
        $specId = $this->createSpecification($productId);
        $user = $this->currentUser();

        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'user' => $user->getId(),
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
            'currency' => 'CNY',
        ]);
        self::assertSame(0, $content['code']);
        $orderId = (int) $content['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/confirm");

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/payment", [
            'payment' => Invoice::PAYMENT_MOCK,
            'autoPaid' => true,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::PAYMENT_MOCK, $order->getPaymentMethod());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
        self::assertNotNull($order->getInvoiceId());
        self::assertNotNull($order->getInvoiceNo());

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/fulfill", ['trackingNumber' => 'TRACK-1']);
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/complete");

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", ['reason' => 'invoice refund']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());
        self::assertSame(Invoice::STATUS_REFUNDED, $order->getPaymentStatus());
    }

    private function createProduct(): int
    {
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/products', ['name' => 'Payment Product', 'status' => 'active']);
        return (int) $content['data']['id'];
    }

    private function createSpecification(int $productId): int
    {
        [, $content] = $this->jsonRequest('POST', "/api/v1/manage/products/{$productId}/specifications", [
            'name' => 'Payment Spec',
            'price' => 1500,
            'status' => 'active',
        ]);
        return (int) $content['data']['id'];
    }

    private function jsonRequest(string $method, string $uri, array $data = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    private function currentUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }
}
