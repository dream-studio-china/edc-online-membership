<?php

namespace App\Tests\Common\Entity;

use App\Common\Entity\Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $entity = new Content('hello-title', 'hello-body');

        self::assertSame('hello-title', $entity->getTitle());
        self::assertSame('hello-body', $entity->getBody());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('hello-title', (string) $entity);
    }

    public function testSettersAreFluentAndTouchUpdatesTimestamp(): void
    {
        $entity = new Content('before');

        $updated = $entity->setTitle('after')->setBody('new-body');

        self::assertSame($entity, $updated);
        self::assertSame('after', $entity->getTitle());
        self::assertSame('new-body', $entity->getBody());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    #[DataProvider('bodyProvider')]
    public function testSetBodySupportsNullableValues(?string $body): void
    {
        $entity = new Content('title', 'initial');

        $entity->setBody($body);

        self::assertSame($body, $entity->getBody());
        self::assertNotNull($entity->getUpdatedAt());
    }

    public static function bodyProvider(): array
    {
        return [
            'string body' => ['text-body'],
            'null body' => [null],
        ];
    }

    public function testPrePersistInitializesCreatedAtWhenConstructorWasSkipped(): void
    {
        $reflection = new \ReflectionClass(Content::class);
        /** @var Content $entity */
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistKeepsCreatedAtWhenAlreadySet(): void
    {
        $entity = new Content('t');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();

        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
