<?php

declare(strict_types=1);

namespace App\Tests\Identity\Controller\App;

use App\Identity\Controller\App\ProfileController;
use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Identity\Service\ProfileServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

final class ProfileControllerTest extends TestCase
{
    private function createController(?User $authenticatedUser, ProfileServiceInterface $service): ProfileController
    {
        $controller = new ProfileController($service);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($authenticatedUser !== null) {
            $token = new UsernamePasswordToken($authenticatedUser, 'main', $authenticatedUser->getRoles());
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', new RequestStack());
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));
        $container->set('validator', Validation::createValidator());

        $controller->setContainer($container);
        $controller->setRequestStack(new RequestStack());
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    // ──────────────────────── GET ────────────────────────

    public function testGetRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController(null, $service);

        $response = $controller->detailAction();

        self::assertSame(401, $response->getStatusCode());
    }

    public function testGetReturnsOwnProfile(): void
    {
        $user = new User();
        $profile = new Profile($user, Profile::LEVEL_GOLD);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn($profile);

        $controller = $this->createController($user, $service);

        $response = $controller->detailAction();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetReturnsNullWhenNoProfile(): void
    {
        $user = new User();

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn(null);

        $controller = $this->createController($user, $service);

        $response = $controller->detailAction();

        self::assertSame(200, $response->getStatusCode());
    }

    // ──────────────────────── PUT ────────────────────────

    public function testPutRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController(null, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"level":"silver"}');
        $response = $controller->updateAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testPutCreatesProfileWhenNoneExists(): void
    {
        $user = new User();
        $profile = new Profile($user, Profile::LEVEL_BRONZE);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn(null);
        $service->method('new')->willReturn($profile);
        $service->method('update')->willReturnCallback(function ($entity, $data) use ($profile) {
            $profile->setLevel($data['level'] ?? Profile::LEVEL_BRONZE);
            return $profile;
        });

        $controller = $this->createController($user, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = $controller->updateAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPutReturnsExistingProfile(): void
    {
        $user = new User();
        $existingProfile = new Profile($user, Profile::LEVEL_GOLD);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn($existingProfile);
        $service->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $controller = $this->createController($user, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = $controller->updateAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPutRejectsLevelChange(): void
    {
        $user = new User();
        $existingProfile = new Profile($user, Profile::LEVEL_BRONZE);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn($existingProfile);
        $service->method('update')->willReturnCallback(function ($entity, $data) use ($existingProfile) {
            // level should not be in $data — it's filtered out
            self::assertArrayNotHasKey('level', $data);
            if (isset($data['level'])) {
                $existingProfile->setLevel($data['level']);
            }
            return $existingProfile;
        });

        $controller = $this->createController($user, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"level":"silver"}');
        $response = $controller->updateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Profile::LEVEL_BRONZE, $existingProfile->getLevel());
    }

    public function testPutUpdatesNickname(): void
    {
        $user = new User();
        $existingProfile = new Profile($user, Profile::LEVEL_BRONZE);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn($existingProfile);
        $service->method('update')->willReturnCallback(function ($entity, $data) use ($existingProfile) {
            if (isset($data['nickname'])) {
                $existingProfile->setNickname($data['nickname']);
            }
            return $existingProfile;
        });

        $controller = $this->createController($user, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"nickname":"Johnny"}');
        $response = $controller->updateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Johnny', $existingProfile->getNickname());
    }

    public function testPutFiltersUnknownFields(): void
    {
        $user = new User();
        $existingProfile = new Profile($user, Profile::LEVEL_BRONZE);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn($existingProfile);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData, $existingProfile) {
            $receivedData = $data;
            return $existingProfile;
        });

        $controller = $this->createController($user, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"nickname":"Good","level":"diamond","random_field":"bad"}');
        $response = $controller->updateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('nickname', $receivedData);
        self::assertArrayNotHasKey('level', $receivedData);
        self::assertArrayNotHasKey('random_field', $receivedData);
    }

    public function testPutDefaultCreateValuesAreApplied(): void
    {
        $user = new User();
        $profile = new Profile($user, Profile::LEVEL_BRONZE);

        $service = $this->createMock(ProfileServiceInterface::class);
        $service->method('get')->with(['user' => $user], false)->willReturn(null);
        $service->method('new')->willReturn($profile);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData, $profile) {
            $receivedData = $data;
            return $profile;
        });

        $controller = $this->createController($user, $service);

        $request = Request::create('/app/profiles', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $controller->updateAction($request);

        self::assertNotNull($receivedData);
        self::assertSame(Profile::LEVEL_BRONZE, $receivedData['level'] ?? null);
    }
}
