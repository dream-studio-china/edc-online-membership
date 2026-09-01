<?php

namespace App\Tests\Common\Entity;

use App\Common\Entity\Media;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $entity = new Media('photo.jpg', 'original.jpg', 'image/jpeg', 1024, '/uploads/photo.jpg');

        self::assertSame('photo.jpg', $entity->getFilename());
        self::assertSame('original.jpg', $entity->getOriginalFilename());
        self::assertSame('image/jpeg', $entity->getMimeType());
        self::assertSame(1024, $entity->getSize());
        self::assertSame('/uploads/photo.jpg', $entity->getPath());
        self::assertSame('local', $entity->getStorage());
        self::assertNull($entity->getOwnerUuid());
        self::assertNull($entity->getAlt());
        self::assertNull($entity->getTitle());
        self::assertNull($entity->getWidth());
        self::assertNull($entity->getHeight());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('photo.jpg', (string) $entity);
    }

    public function testSettersAreFluent(): void
    {
        $entity = new Media('a', 'a', 'image/png', 1, '/a');

        $ownerUuid = 'a4e8c3d0-3f6c-4e96-9f10-bdb0a91ebc7a';

        $entity->setFilename('b.jpg')->setOriginalFilename('orig.jpg')
            ->setMimeType('image/webp')->setSize(2048)->setPath('/uploads/b.jpg')
            ->setStorage('qiniu')->setOwnerUuid($ownerUuid)->setAlt('alt text')->setTitle('Image Title')->setWidth(800)->setHeight(600);

        self::assertSame('b.jpg', $entity->getFilename());
        self::assertSame('orig.jpg', $entity->getOriginalFilename());
        self::assertSame('image/webp', $entity->getMimeType());
        self::assertSame(2048, $entity->getSize());
        self::assertSame('/uploads/b.jpg', $entity->getPath());
        self::assertSame('qiniu', $entity->getStorage());
        self::assertSame($ownerUuid, $entity->getOwnerUuid());
        self::assertSame('alt text', $entity->getAlt());
        self::assertSame('Image Title', $entity->getTitle());
        self::assertSame(800, $entity->getWidth());
        self::assertSame(600, $entity->getHeight());
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $entity = new Media('a', 'a', 'image/png', 1, '/a');
        self::assertNull($entity->getUpdatedAt());

        $entity->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    public function testNullableFieldsAcceptNull(): void
    {
        $entity = new Media('a', 'a', 'image/png', 1, '/a');
        $entity->setAlt('some alt');
        $entity->setTitle('some title');
        $entity->setWidth(100);
        $entity->setHeight(100);

        $entity->setAlt(null)->setTitle(null)->setWidth(null)->setHeight(null);
        $entity->setOwnerUuid(null);

        self::assertNull($entity->getAlt());
        self::assertNull($entity->getTitle());
        self::assertNull($entity->getWidth());
        self::assertNull($entity->getHeight());
        self::assertNull($entity->getOwnerUuid());
    }

    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Media::class);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistPreservesExistingCreatedAt(): void
    {
        $entity = new Media('preserve', 'a', 'image/png', 1, '/a');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();
        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
