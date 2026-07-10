<?php

declare(strict_types=1);

namespace App\Tests\Identity\Controller\Manage;

use App\Identity\Controller\Manage\ProfileController;
use App\Identity\Service\ProfileServiceInterface;
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

final class ProfileControllerTest extends TestCase
{
    private function createController(ProfileServiceInterface $service): ProfileController
    {
        $controller = new ProfileController($service);

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

    public function testCreateRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController($service);

        $request = Request::create('/manage/profiles', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"user":1,"level":"gold"}');
        $response = $controller->createAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testListRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController($service);

        $response = $controller->listAction();

        self::assertSame(401, $response->getStatusCode());
    }

    public function testDetailRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController($service);

        $response = $controller->detailAction(1);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testUpdateRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController($service);

        $request = Request::create('/manage/profiles/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"level":"platinum"}');
        $response = $controller->updateAction(1, $request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testDeleteRejectsUnauthenticated(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController($service);

        $response = $controller->deleteAction(1);

        self::assertSame(401, $response->getStatusCode());
    }
}
