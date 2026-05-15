<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class JwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $authHeader = $request->headers->get('Authorization');

        return $authHeader !== null && str_starts_with($authHeader, 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization', '');
        $jwt = substr($authHeader, 7);

        if ($jwt === '' || $jwt === false) {
            throw new CustomUserMessageAuthenticationException('Missing JWT token.');
        }

        $payload = $this->tokenManager->decodeAccessToken($jwt);

        if ($payload === null) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired JWT token.');
        }

        $userId = $payload['sub'];

        return new SelfValidatingPassport(
            new UserBadge($userId, function (string $id) {
                $user = $this->userRepository->find((int) $id);
                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException('User not found.');
                }

                return $user;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Continue to controller
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'code' => 401,
            'message' => $exception->getMessageKey() !== '' ? $exception->getMessageKey() : 'Authentication failed.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
