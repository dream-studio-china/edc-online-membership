<?php

declare(strict_types=1);

namespace App\Tests\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\SingleCreateAndUpdateApiViewMixin;
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

final class SingleCreateAndUpdateApiViewMixinTest extends TestCase
{
    private function createController(?array $config, BaseServiceInterface $service): object
    {
        $controller = new class($service) extends RestController {
            use ApiView, SingleCreateAndUpdateApiViewMixin;

            public function __construct(BaseServiceInterface $service)
            {
                $this->service = $service;
            }

            protected function commonFilter(): array
            {
                return [];
            }
        };

        if ($config !== null) {
            foreach ($config as $prop => $value) {
                (new \ReflectionProperty($controller, $prop))->setValue($controller, $value);
            }
        }

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

    // ────────────────── pass-through (no properties) ──────────────────

    public function testNoPropertiesPassesThroughOnUpdate(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(null, $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"any":1,"stuff":"hello"}');
        $controller->updateAction($request);

        self::assertSame(['any' => 1, 'stuff' => 'hello'], $receivedData);
    }

    public function testNoPropertiesPassesThroughOnCreate(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn(null);
        $service->method('new')->willReturn((object) []);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(null, $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"any":1,"stuff":"hello"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('any', $receivedData);
        self::assertArrayHasKey('stuff', $receivedData);
    }

    // ────────────────── acceptedCreateProperties ──────────────────

    public function testAcceptedCreatePropertiesFiltersUnwanted(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn(null);
        $service->method('new')->willReturn((object) []);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(['acceptedCreateProperties' => ['nickname']], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"nickname":"ok","level":"forbidden"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('nickname', $receivedData);
        self::assertArrayNotHasKey('level', $receivedData);
    }

    // ────────────────── acceptedUpdateProperties ──────────────────

    public function testAcceptedUpdatePropertiesFiltersUnwanted(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(['acceptedUpdateProperties' => ['nickname']], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"nickname":"ok","level":"forbidden"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('nickname', $receivedData);
        self::assertArrayNotHasKey('level', $receivedData);
    }

    // ────────────────── requiredCreateProperties ──────────────────

    public function testRequiredCreatePropertiesThrowsWhenMissing(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);

        $controller = $this->createController(['requiredCreateProperties' => ['name']], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"other":"stuff"}');
        $response = $controller->updateAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRequiredCreatePropertiesPassesWhenPresent(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn(null);
        $service->method('new')->willReturn((object) []);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(['requiredCreateProperties' => ['name']], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"hello"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('name', $receivedData);
    }

    // ────────────────── requiredUpdateProperties ──────────────────

    public function testRequiredUpdatePropertiesThrowsWhenMissing(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);

        $controller = $this->createController(['requiredUpdateProperties' => ['name']], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"other":"stuff"}');
        $response = $controller->updateAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRequiredUpdatePropertiesPassesWhenPresent(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(['requiredUpdateProperties' => ['name']], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"hello"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('name', $receivedData);
    }

    // ────────────────── combined required + accepted ──────────────────

    public function testRequiredAndAcceptedWorkTogether(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn(null);
        $service->method('new')->willReturn((object) []);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController([
            'requiredCreateProperties' => ['name'],
            'acceptedCreateProperties' => ['name', 'nickname'],
        ], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"req","nickname":"opt","level":"bad"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('name', $receivedData);
        self::assertArrayHasKey('nickname', $receivedData);
        self::assertArrayNotHasKey('level', $receivedData);
    }

    // ────────────────── empty properties don't filter ──────────────────

    public function testEmptyAcceptedPropertiesDoesNotFilter(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);
        $receivedData = null;
        $service->method('update')->willReturnCallback(function ($entity, $data) use (&$receivedData) {
            $receivedData = $data;
            return $entity;
        });

        $controller = $this->createController(['acceptedUpdateProperties' => []], $service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"everything":"passes"}');
        $controller->updateAction($request);

        self::assertArrayHasKey('everything', $receivedData);
    }
}
