<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Security\TokenManager;
use App\Identity\Service\OtpService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class AuthController
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly OtpService $otpService,
        private readonly EntityManagerInterface $em,
        private readonly string $otpLoginTemplate,
        private readonly string $otpVerifyPhoneTemplate,
    ) {
    }

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Login with identifier and password',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['identifier', 'password'],
                properties: [
                    new OA\Property(
                        property: 'identifier',
                        type: 'string',
                        description: 'Email, username, or phone number',
                        example: 'admin@example.com'
                    ),
                    new OA\Property(
                        property: 'password',
                        type: 'string',
                        format: 'password',
                        minLength: 1,
                        description: 'Plain password. Must not be empty.',
                        example: 'P@ssw0rd'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login success, tokens returned'),
            new OA\Response(response: 400, description: 'Identifier or password missing'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 403, description: 'Phone not verified'),
        ],
        tags: ['Identity/Auth']
    )]
    #[Route('/api/auth/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $identifier = trim((string) ($data['identifier'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($identifier === '' || $password === '') {
            return $this->error('Identifier and password are required.', Response::HTTP_BAD_REQUEST);
        }

        // Phone-based login: check verification status separately
        if ($this->looksLikePhone($identifier)) {
            $user = $this->userRepository->findByPhone($identifier);
            if ($user !== null && !$user->isPhoneVerified()) {
                return $this->error('Phone not verified.', Response::HTTP_FORBIDDEN);
            }
        } else {
            $user = $this->userRepository->findByIdentifier($identifier);
        }

        if ($user === null) {
            return $this->error('Invalid credentials.', Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->error('Invalid credentials.', Response::HTTP_UNAUTHORIZED);
        }

        $accessToken = $this->tokenManager->createAccessToken($user);
        $refreshToken = $this->tokenManager->createRefreshToken($user);

        return new JsonResponse([
            'access_token' => $accessToken,
            'expires_in' => $this->tokenManager->getAccessTtl(),
            'refresh_token' => $refreshToken,
        ]);
    }

    private function looksLikePhone(string $value): bool
    {
        return (bool) preg_match('/^\+?[0-9]{7,20}$/', $value);
    }

    #[Route('/api/auth/otp/request', methods: ['POST'])]
    public function requestOtp(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $phone = trim((string) ($data['phone'] ?? ''));
        $purpose = trim((string) ($data['purpose'] ?? 'login'));

        if ($phone === '') {
            return $this->error('Phone number is required.', Response::HTTP_BAD_REQUEST);
        }

        if (!\in_array($purpose, ['login', 'verify_phone'], true)) {
            return $this->error('Invalid purpose. Must be "login" or "verify_phone".', Response::HTTP_BAD_REQUEST);
        }

        $templateCode = $purpose === 'login' ? $this->otpLoginTemplate : $this->otpVerifyPhoneTemplate;

        try {
            $this->otpService->generateAndSend($phone, $purpose, $templateCode);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_TOO_MANY_REQUESTS);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/otp/verify', methods: ['POST'])]
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $phone = trim((string) ($data['phone'] ?? ''));
        $otp = trim((string) ($data['otp'] ?? ''));
        $purpose = trim((string) ($data['purpose'] ?? 'login'));

        if ($phone === '' || $otp === '') {
            return $this->error('Phone and OTP are required.', Response::HTTP_BAD_REQUEST);
        }

        if (!\in_array($purpose, ['login', 'verify_phone'], true)) {
            return $this->error('Invalid purpose.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->otpService->verify($phone, $purpose, $otp)) {
            return $this->error('Invalid or expired OTP.', Response::HTTP_UNAUTHORIZED);
        }

        if ($purpose === 'login') {
            $user = $this->userRepository->findByPhone($phone);
            if ($user === null || !$user->isPhoneVerified()) {
                return $this->error('Phone not verified or user not found.', Response::HTTP_UNAUTHORIZED);
            }

            $accessToken = $this->tokenManager->createAccessToken($user);
            $refreshToken = $this->tokenManager->createRefreshToken($user);

            return new JsonResponse([
                'access_token' => $accessToken,
                'expires_in' => $this->tokenManager->getAccessTtl(),
                'refresh_token' => $refreshToken,
            ]);
        }

        // purpose === verify_phone
        $user = $this->userRepository->findByPhone($phone);
        if ($user !== null) {
            $user->setPhoneVerified(true);
            $this->em->flush();
        }

        return new JsonResponse(['phone_verified' => true]);
    }

    #[Route('/api/auth/token/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            return $this->error('Refresh token is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $tokens = $this->tokenManager->rotateRefreshToken($refreshToken);

            return new JsonResponse([
                'access_token' => $tokens['access_token'],
                'expires_in' => $this->tokenManager->getAccessTtl(),
                'refresh_token' => $tokens['refresh_token'],
            ]);
        } catch (\RuntimeException $e) {
            // Token reuse or invalid
            return $this->error($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/api/auth/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($refreshToken !== '') {
            $this->tokenManager->revokeRefreshToken($refreshToken);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'message' => $message,
        ], $status);
    }
}
