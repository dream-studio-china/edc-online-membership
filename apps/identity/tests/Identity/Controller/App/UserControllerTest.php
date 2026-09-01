<?php

declare(strict_types=1);

namespace App\Tests\Identity\Controller\App;

use App\Identity\Main\Controller\App\UserController;
use App\Identity\Main\Service\UserService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

final class UserControllerTest extends TestCase
{
    private function createController(UserService $service): UserController
    {
        $controller = new UserController($service);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

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

    public function testChangePasswordRejectsUnauthenticatedUser(): void
    {
        $service = $this->createMock(UserService::class);
        $controller = $this->createController($service);

        $request = Request::create('/change-password', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"currentPassword":"old","newPassword":"new"}');
        $response = $controller->changePasswordAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testProfileRejectsUnauthenticatedUser(): void
    {
        $service = $this->createMock(UserService::class);
        $controller = $this->createController($service);

        $response = $controller->profileAction();

        self::assertSame(401, $response->getStatusCode());
    }

    public function testUpdateProfileRejectsUnauthenticatedUser(): void
    {
        $service = $this->createMock(UserService::class);
        $controller = $this->createController($service);

        $request = Request::create('/me', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"username":"test"}');
        $response = $controller->updateProfileAction($request);

        self::assertSame(401, $response->getStatusCode());
    }
}
