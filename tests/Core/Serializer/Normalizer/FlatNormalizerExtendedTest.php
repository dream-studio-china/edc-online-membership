<?php

namespace App\Tests\Core\Serializer\Normalizer;

use App\Core\Serializer\Normalizer\FlatNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final class FlatNormalizerExtendedTest extends TestCase
{
    private function createNormalizer(): FlatNormalizer
    {
        $objectNormalizer = new ObjectNormalizer();
        $accessor = PropertyAccess::createPropertyAccessor();
        return new FlatNormalizer($objectNormalizer, $accessor);
    }

    public function testNormalizeAddsToString(): void
    {
        $normalizer = $this->createNormalizer();
        $obj = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test'; }
            public function __toString(): string { return 'TheTest'; }
        };

        $result = $normalizer->normalize($obj, 'json');

        self::assertIsArray($result);
        self::assertArrayHasKey('id', $result);
        self::assertSame(1, $result['id']);
        self::assertArrayHasKey('__toString', $result);
        self::assertSame('TheTest', $result['__toString']);
    }

    public function testSupportsNormalizationForObjects(): void
    {
        $normalizer = $this->createNormalizer();

        self::assertTrue($normalizer->supportsNormalization(new \stdClass()));
    }

    public function testGetSupportedTypes(): void
    {
        $normalizer = $this->createNormalizer();
        $types = $normalizer->getSupportedTypes('json');

        self::assertIsArray($types);
        self::assertArrayHasKey('object', $types);
        self::assertTrue($types['object']);
    }

    public function testSupportsDenormalization(): void
    {
        $normalizer = $this->createNormalizer();
        self::assertTrue($normalizer->supportsDenormalization([], \stdClass::class, 'json'));
    }

    public function testDenormalizeProducesObject(): void
    {
        $normalizer = $this->createNormalizer();
        $result = $normalizer->denormalize(['name' => 'test'], \stdClass::class);
        self::assertInstanceOf(\stdClass::class, $result);
    }

    public function testSetSerializerDoesNotThrow(): void
    {
        $normalizer = $this->createNormalizer();
        $serializer = $this->createMock(\Symfony\Component\Serializer\SerializerInterface::class);
        $normalizer->setSerializer($serializer);
        self::assertTrue(true);
    }

    public function testSetNormalizerDoesNotThrow(): void
    {
        $normalizer = $this->createNormalizer();
        $inner = $this->createMock(\Symfony\Component\Serializer\Normalizer\NormalizerInterface::class);
        $normalizer->setNormalizer($inner);
        self::assertTrue(true);
    }

    public function testNormalizeNullReturnsNull(): void
    {
        $normalizer = $this->createNormalizer();
        // Test that null (not an object) is not treated as a normalizable object
        self::assertFalse($normalizer->supportsNormalization(null));
        self::assertFalse($normalizer->supportsNormalization(123));
        self::assertFalse($normalizer->supportsNormalization('string'));
    }

    public function testNormalizeFlattensRelationsCollectionsAndJsonValues(): void
    {
        $related = new class {
            public function getId(): int { return 7; }
            public function __toString(): string { return 'related'; }
            public function __metadata(): array { return ['source' => 'test']; }
        };
        $object = new class($related) {
            public function __construct(private object $related) {}
            public function getOwner(): object { return $this->related; }
            public function getMembers(): \Traversable { return new \ArrayIterator([$this->related, new \stdClass()]); }
            public function getSettings(): string { return '{"enabled":true}'; }
            public function getCode(): string { return '007'; }
            public function __toString(): string { return 'parent'; }
        };
        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willReturn([
            'owner' => ['unexpected' => true],
            'members' => [],
            'settings' => 'raw',
            'code' => 'unchanged',
        ]);

        $result = (new FlatNormalizer($decorated, PropertyAccess::createPropertyAccessor()))->normalize($object);

        self::assertSame(['id' => 7, '__toString' => 'related', '__metadata' => ['source' => 'test']], $result['owner']);
        self::assertSame([['id' => 7, '__toString' => 'related', '__metadata' => ['source' => 'test']]], $result['members']);
        self::assertSame(['enabled' => true], $result['settings']);
        self::assertSame('unchanged', $result['code']);
        self::assertSame('parent', $result['__toString']);
    }

    public function testNormalizeKeepsScalarOutputAndRecoversFromDecoratorFailures(): void
    {
        $scalar = $this->createMock(NormalizerInterface::class);
        $scalar->method('normalize')->willReturn('already-normalized');
        self::assertSame('already-normalized', (new FlatNormalizer($scalar, PropertyAccess::createPropertyAccessor()))->normalize(new \stdClass()));

        $failing = $this->createMock(NormalizerInterface::class);
        $failing->method('normalize')->willThrowException(new \RuntimeException('broken relation'));
        $normalizer = new FlatNormalizer($failing, $this->createMock(PropertyAccessorInterface::class));
        $identified = new class {
            public function getId(): int { return 3; }
            public function __toString(): string { return 'recoverable'; }
        };

        self::assertSame(['id' => 3, '__toString' => 'recoverable'], $normalizer->normalize($identified));
        self::assertSame(['__class' => \stdClass::class], $normalizer->normalize(new \stdClass()));
        self::assertNull($normalizer->normalize('not an object'));
    }

    public function testDenormalizerAndAwareDecoratorsReceiveDelegatedCalls(): void
    {
        $decorated = new class implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, SerializerAwareInterface {
            public ?SerializerInterface $serializer = null;
            public ?NormalizerInterface $normalizer = null;
            public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null { return []; }
            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool { return true; }
            public function getSupportedTypes(?string $format): array { return ['object' => true]; }
            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed { return (object) $data; }
            public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool { return true; }
            public function setSerializer(SerializerInterface $serializer): void { $this->serializer = $serializer; }
            public function setNormalizer(NormalizerInterface $normalizer): void { $this->normalizer = $normalizer; }
        };
        $normalizer = new FlatNormalizer($decorated, PropertyAccess::createPropertyAccessor());
        $serializer = new Serializer([]);

        self::assertTrue($normalizer->supportsDenormalization(['id' => 5], \stdClass::class));
        self::assertSame(5, $normalizer->denormalize(['id' => 5], \stdClass::class)->id);
        $normalizer->setSerializer($serializer);
        $normalizer->setNormalizer($serializer);
        self::assertSame($serializer, $decorated->serializer);
        self::assertSame($serializer, $decorated->normalizer);
    }

    public function testDenormalizeRejectsDecoratorsWithoutDenormalizationSupport(): void
    {
        $normalizer = new FlatNormalizer($this->createMock(NormalizerInterface::class), PropertyAccess::createPropertyAccessor());

        self::assertFalse($normalizer->supportsDenormalization([], \stdClass::class));
        $this->expectException(\LogicException::class);
        $normalizer->denormalize([], \stdClass::class);
    }
}
