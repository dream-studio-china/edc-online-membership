<?php

declare(strict_types=1);

namespace App\Tests\IntegrationContracts;

use CrudPlatform\IntegrationContracts\IntegrationMessage;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class IntegrationContractTest extends TestCase
{
    private const string CONTRACTS = __DIR__ . '/../../contracts/integration';

    public function testManifestCarriersMatchTheirDeclaredTopics(): void
    {
        foreach ($this->manifest()->messages as $message) {
            self::assertTrue(is_a($message->carrier, IntegrationMessage::class, true));
            self::assertSame($message->type, $message->carrier::TYPE);
            self::assertSame($message->version, $message->carrier::VERSION);
            self::assertSame($message->topic, $message->carrier::TOPIC);
            self::assertSame($message->type . '.v' . $message->version, $message->topic);
        }
    }

    public function testCanonicalFixturesValidateAgainstTheirSchemas(): void
    {
        $validator = new Validator();
        $validator->validate($this->json('schema/envelope.schema.json'), $this->json('schema/envelope.schema.json'));

        foreach ($this->manifest()->messages as $message) {
            $fixture = $this->json('fixtures/valid/' . $message->topic . '.json');
            self::assertTrue(
                $validator->validate($fixture, $this->json($message->schema))->isValid(),
                $message->topic,
            );
        }
    }

    public function testInvalidFixtureIsRejectedByTheCanonicalEnvelopeSchema(): void
    {
        $validator = new Validator();
        self::assertFalse(
            $validator->validate(
                $this->json('fixtures/invalid/missing-event-id.json'),
                $this->json('schema/envelope.schema.json'),
            )->isValid(),
        );
    }

    public function testCarriersPreserveEnvelopeThroughPhpSerialization(): void
    {
        foreach ($this->manifest()->messages as $message) {
            $carrier = new $message->carrier(['eventId' => 'event']);
            $roundTrip = unserialize(serialize($carrier), ['allowed_classes' => true]);

            self::assertInstanceOf($message->carrier, $roundTrip);
            self::assertSame(['eventId' => 'event'], $roundTrip->envelope);
        }
    }

    /** @param array{class: class-string, body: string} $fixture */
    #[DataProvider('legacyNativePhpFixtures')]
    public function testLegacyNativeMessengerFixturesRemainDecodable(array $fixture): void
    {
        $envelope = (new PhpSerializer())->decode([
            'body' => base64_decode($fixture['body'], true),
            'headers' => [],
        ]);

        self::assertInstanceOf($fixture['class'], $envelope->getMessage());
        self::assertSame('legacy-event', $envelope->getMessage()->envelope['eventId']);
    }

    /** @return iterable<string, array{array{class: class-string, body: string}}> */
    public static function legacyNativePhpFixtures(): iterable
    {
        /** @var list<array{class: class-string, body: string}> $fixtures */
        $fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/legacy-native-php.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($fixtures as $fixture) {
            yield $fixture['class'] => [$fixture];
        }
    }

    private function manifest(): object
    {
        return $this->json('manifest.json');
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents(self::CONTRACTS . '/' . $path), false, 512, JSON_THROW_ON_ERROR);
    }
}
