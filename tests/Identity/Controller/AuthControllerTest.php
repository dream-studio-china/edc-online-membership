<?php

declare(strict_types=1);

namespace App\Tests\Identity\Controller;

use App\Identity\Controller\AuthController;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Security\TokenManager;
use App\Identity\Service\OtpService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthControllerTest extends TestCase
{
    private TokenManager $tokenManager;
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $hasher;
    private OtpService $otpService;
    private EntityManagerInterface $em;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->otpService = $this->createMock(OtpService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->controller = new AuthController(
            $this->tokenManager,
            $this->userRepository,
            $this->hasher,
            $this->otpService,
            $this->em,
            'TPL_LOGIN',
            'TPL_VERIFY',
        );
    }

    public function testLogoutRevokesBothAccessAndRefreshTokensWhenProvided(): void
    {
        $request = Request::create('/api/auth/logout', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
        ], JSON_THROW_ON_ERROR));

        $this->tokenManager->expects(self::once())->method('revokeAccessToken')->with('access-1');
        $this->tokenManager->expects(self::once())->method('revokeRefreshToken')->with('refresh-1');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testLogoutUsesAuthorizationHeaderAsAccessTokenFallback(): void
    {
        $request = Request::create('/api/auth/logout', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer bearer-token'], content: '');

        $this->tokenManager->expects(self::once())->method('revokeAccessToken')->with('bearer-token');
        $this->tokenManager->expects(self::never())->method('revokeRefreshToken');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testLogoutWithEmptyBodyAndNoAuthorizationDoesNotRevoke(): void
    {
        $request = Request::create('/api/auth/logout', 'POST', content: '');

        $this->tokenManager->expects(self::never())->method('revokeAccessToken');
        $this->tokenManager->expects(self::never())->method('revokeRefreshToken');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testRefreshReturnsBadRequestWhenRefreshTokenMissing(): void
    {
        $request = Request::create('/api/auth/token/refresh', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([], JSON_THROW_ON_ERROR));

        $this->tokenManager->expects(self::never())->method('rotateRefreshToken');

        $response = $this->controller->refresh($request);
        self::assertSame(400, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Refresh token is required', (string) ($payload['message'] ?? ''));
    }

    public function testRefreshReturnsUnauthorizedWhenRotationFails(): void
    {
        $request = Request::create('/api/auth/token/refresh', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'refresh_token' => 'broken-token',
        ], JSON_THROW_ON_ERROR));

        $this->tokenManager->expects(self::once())
            ->method('rotateRefreshToken')
            ->with('broken-token')
            ->willThrowException(new \RuntimeException('Token reuse detected.'));

        $response = $this->controller->refresh($request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginWithUnverifiedPhoneReturnsForbidden(): void
    {
        $user = (new User())
            ->setEmail('phone@example.com')
            ->setUsername('phone')
            ->setPhone('+8613812345678')
            ->setPhoneVerified(false)
            ->setPassword('hash');

        $this->userRepository->expects(self::once())
            ->method('findByPhone')
            ->with('+8613812345678')
            ->willReturn($user);

        $this->hasher->expects(self::never())->method('isPasswordValid');

        $request = Request::create('/api/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'identifier' => '+8613812345678',
            'password' => 'Whatever123!',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->login($request);
        self::assertSame(403, $response->getStatusCode());
    }
}
