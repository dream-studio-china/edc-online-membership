<?php

namespace App\Tests\Integration;

final class OpenApiIntegrationTest extends IntegrationWebTestCase
{
    public function testSwaggerUiAndJsonEndpointsAreAvailable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('content-type'));

        $client->request('GET', '/api/doc.json');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('content-type'));

        $doc = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('3.1.0', $doc['openapi'] ?? null);
        self::assertArrayHasKey('/api/v1/manage/contents', $doc['paths'] ?? []);
        self::assertArrayHasKey('/api/v1/manage/contents/{id}', $doc['paths'] ?? []);
    }
}
