<?php

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiRegressionTest extends WebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Common\\Entity\\Content c')->execute();
        self::ensureKernelShutdown();
    }

    public function testContentCrudRegressionFlow(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/contents',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'regression-title', 'body' => 'regression-body'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsInt($created['id']);
        $id = $created['id'];

        $client->request('GET', '/api/contents/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('regression-title', $fetched['title']);

        $client->request(
            'PUT',
            '/api/contents/' . $id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'updated-title', 'body' => 'updated-body'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated-title', $updated['title']);

        $client->request('GET', '/api/contents?limit=10');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertNotEmpty($list);

        $client->request('DELETE', '/api/contents/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/contents/' . $id);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCreateValidationRegression(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/contents',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'missing-title'], JSON_THROW_ON_ERROR)
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Title is required', $data['message']);
    }

    public function testUpdateAndDeleteMissingEntityRegression(): void
    {
        $client = static::createClient();

        $client->request(
            'PUT',
            '/api/contents/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'x'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/contents/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
