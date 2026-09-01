<?php

namespace App\Tests\Common\Entity;

use App\Common\Entity\Category;
use App\Common\Entity\Picture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PictureTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $category = new Category('Gallery', 'gallery');
        $entity = new Picture('https://cdn.example.com/a.png', $category);

        self::assertSame('https://cdn.example.com/a.png', $entity->getImage());
        self::assertSame($category, $entity->getCategory());
        self::assertNull($entity->getOwnerUuid());
        self::assertNull($entity->getTitle());
        self::assertNull($entity->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
    }

    public function testToStringFallsBackToImageWhenTitleMissing(): void
    {
        $entity = new Picture('https://cdn.example.com/a.png');

        self::assertSame('https://cdn.example.com/a.png', (string) $entity);

        $entity->setTitle('Sunset');
        self::assertSame('Sunset', (string) $entity);
    }

    public function testSettersAreFluentAndTouchUpdatesTimestamp(): void
    {
        $entity = new Picture('https://cdn.example.com/a.png');

        $updated = $entity
            ->setImage('https://cdn.example.com/b.png')
            ->setTitle('Title');

        self::assertSame($entity, $updated);
        self::assertSame('https://cdn.example.com/b.png', $entity->getImage());
        self::assertSame('Title', $entity->getTitle());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    public function testOwnerUuidIsNullable(): void
    {
        $entity = new Picture('https://cdn.example.com/a.png');
        $ownerUuid = 'a4e8c3d0-3f6c-4e96-9f10-bdb0a91ebc7a';

        $entity->setOwnerUuid($ownerUuid);
        self::assertSame($ownerUuid, $entity->getOwnerUuid());

        $entity->setOwnerUuid(null);
        self::assertNull($entity->getOwnerUuid());
    }

    public function testCategoryRelationship(): void
    {
        $entity = new Picture('https://cdn.example.com/a.png');
        $category = new Category('Gallery', 'gallery');

        $entity->setCategory($category);
        self::assertSame($category, $entity->getCategory());
    }

    #[DataProvider('metadataProvider')]
    public function testSetMetadataSupportsNullableValues(?array $metadata): void
    {
        $entity = new Picture('https://cdn.example.com/a.png');

        $entity->setMetadata($metadata);

        self::assertSame($metadata, $entity->getMetadata());
        self::assertNotNull($entity->getUpdatedAt());
    }

    /** @return array<string, array{0: array<string, mixed>|null}> */
    public static function metadataProvider(): array
    {
        return [
            'array metadata' => [['exif' => ['iso' => 100], 'source' => 'camera']],
            'null metadata' => [null],
        ];
    }

    public function testPrePersistInitializesCreatedAtWhenConstructorWasSkipped(): void
    {
        $reflection = new \ReflectionClass(Picture::class);
        /** @var Picture $entity */
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistKeepsCreatedAtWhenAlreadySet(): void
    {
        $entity = new Picture('https://cdn.example.com/a.png');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();

        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
