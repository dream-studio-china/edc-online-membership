<?php

declare(strict_types=1);

namespace App\Tests\Common\Integration;

use App\Common\Entity\Comment;
use App\Identity\Main\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CommentApiExtraTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
    }

    private function json(string $method, string $uri, array $data = []): array
    {
        $this->client->request($method, $uri, [], [], [], json_encode($data, JSON_THROW_ON_ERROR));
        return [$this->client->getResponse(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    public function testCommentCreateAndList(): void
    {
        [$r1, $create] = $this->json('POST', '/api/v1/app/comments', [
            'body' => 'Test comment body',
            'entityType' => 'content',
            'entityId' => 1,
        ]);
        self::assertSame(Response::HTTP_CREATED, $r1->getStatusCode());

        [$r2, $list] = $this->json('GET', '/api/v1/app/comments');
        self::assertSame(Response::HTTP_OK, $r2->getStatusCode());
        self::assertSame(0, $list['code']);
    }

    public function testManageCreateResolvesLegacyNumericAuthor(): void
    {
        $user = new User();
        $user->setEmail('manage-comment-author@example.com');
        $user->setUsername('manage-comment-author');
        $user->setPassword('test-password');

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->persist($user);
        $em->flush();

        [$response, $payload] = $this->json('POST', '/api/v1/manage/comments', [
            'body' => 'Managed comment',
            'entityType' => 'content',
            'entityId' => 1,
            'author' => $user->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame($user->getUuid(), $payload['data']['authorUuid']);
        self::assertSame($user->getUuid(), $em->getRepository(Comment::class)->find($payload['data']['id'])?->getAuthorUuid());
    }
}
