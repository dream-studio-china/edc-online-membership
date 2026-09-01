<?php

namespace App\Tests\Core\Utils;

use App\Core\Utils\RsaClient;
use PHPUnit\Framework\TestCase;

final class RsaClientTest extends TestCase
{
    public function testBuildsCanonicalSigningContent(): void
    {
        $client = new RsaClient();

        self::assertSame('a=first&z=last', $client->getSignContent(['z' => 'last', 'empty' => '', 'a' => 'first', 'none' => null]));
    }

    public function testSignsAndVerifiesWithConfiguredPemKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $client = new RsaClient();
        $client->rsaPrivateKey = $privateKey;
        $client->rsaPublicKey = $details['key'];
        $parameters = ['order' => '42', 'amount' => '10.00'];

        $signature = $client->rsaSign($parameters);

        self::assertNotSame('', $signature);
        self::assertTrue($client->rsaVerifySign($parameters, $signature));
        self::assertFalse($client->rsaVerifySign(['order' => '42', 'amount' => '11.00'], $signature));
        self::assertSame(1024, $client->getPrivateKenLen());
        self::assertSame(1024, $client->getPublicKenLen());
    }
}
