<?php

declare(strict_types=1);

namespace App\Tests\Identity\Integration;

use App\Identity\Main\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testFullAuthFlow(): void
    {
        // ------------------------------------------------------------------- //
        // 1. Register a test user (via EM directly)
        // ------------------------------------------------------------------- //
        $client = static::createClient();
        $container = $client->getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('test@example.com');
        $user->setUsername('testuser');
        $user->setPhone('+8613912345678');
        $user->setPhoneVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        // ------------------------------------------------------------------- //
        // 2. Login with email + password (identifier login)
        // ------------------------------------------------------------------- //
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'test@example.com',
            'password' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(200);
        $loginData = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('access_token', $loginData);
        self::assertArrayHasKey('refresh_token', $loginData);
        self::assertArrayHasKey('expires_in', $loginData);
        self::assertNotEmpty($loginData['access_token']);
        self::assertNotEmpty($loginData['refresh_token']);
        self::assertSame(7200, $loginData['expires_in']);

        $accessToken = $loginData['access_token'];
        $refreshToken = $loginData['refresh_token'];

        // ------------------------------------------------------------------- //
        // 3. Token refresh (rotation)
        // ------------------------------------------------------------------- //
        $client->jsonRequest('POST', '/api/auth/token/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        self::assertResponseStatusCodeSame(200);
        $refreshData = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('access_token', $refreshData);
        self::assertArrayHasKey('refresh_token', $refreshData);
        self::assertNotEmpty($refreshData['access_token']);
        self::assertNotEmpty($refreshData['refresh_token']);
        // Old refresh token should now be revoked
        self::assertNotSame($refreshToken, $refreshData['refresh_token']);

        $newRefreshToken = $refreshData['refresh_token'];

        // ------------------------------------------------------------------- //
        // 4. Reuse detection: using the old refresh token should fail
        // ------------------------------------------------------------------- //
        $client->jsonRequest('POST', '/api/auth/token/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        self::assertResponseStatusCodeSame(401);

        // ------------------------------------------------------------------- //
        // 5. Logout (revokes the new refresh token)
        // ------------------------------------------------------------------- //
        $client->jsonRequest('POST', '/api/auth/logout', [
            'refresh_token' => $newRefreshToken,
        ]);

        self::assertResponseStatusCodeSame(204);

        // ------------------------------------------------------------------- //
        // 6. Attempting to use revoked refresh token should fail
        // ------------------------------------------------------------------- //
        $client->jsonRequest('POST', '/api/auth/token/refresh', [
            'refresh_token' => $newRefreshToken,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginWithUsername(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('user2@example.com');
        $user->setUsername('johnsmith');
        $user->setPassword($hasher->hashPassword($user, 'secret99'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'johnsmith',
            'password' => 'secret99',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testLoginWithVerifiedPhone(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('phoneuser@example.com');
        $user->setUsername('phoneuser');
        $user->setPhone('+8613800000000');
        $user->setPhoneVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'phone123'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => '+8613800000000',
            'password' => 'phone123',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testLoginFailsWithUnverifiedPhone(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('unverified@example.com');
        $user->setUsername('unverified');
        $user->setPhone('+8613900000000');
        $user->setPhoneVerified(false);
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => '+8613900000000',
            'password' => 'pw',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testLoginFailsWithInvalidPassword(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('wrongpw@example.com');
        $user->setUsername('wrongpw');
        $user->setPassword($hasher->hashPassword($user, 'correct'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'wrongpw@example.com',
            'password' => 'wrong-password',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginFailsWithNonexistentUser(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testEmptyCredentialsReturnBadRequest(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => '',
            'password' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}
