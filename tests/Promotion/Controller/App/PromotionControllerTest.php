<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Tests\Promotion\Controller\App;

use App\Promotion\Controller\App\PromotionController;
use App\Promotion\Service\PromotionServiceInterface;
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

final class PromotionControllerTest extends TestCase
{
    private function createController(PromotionServiceInterface $service): PromotionController
    {
        $controller = new PromotionController($service);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));
        $container->set('validator', Validation::createValidator());

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    public function testControllerIsInstantiable(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        self::assertInstanceOf(PromotionController::class, $controller);
    }

    public function testCommonFilterReturnsEnabledTrue(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('commonFilter');
        $method->setAccessible(true);
        $filter = $method->invoke($controller);

        self::assertIsArray($filter);
        self::assertArrayHasKey('enabled', $filter);
        self::assertTrue($filter['enabled']);
    }

    public function testCommonFilterOnlyContainsEnabled(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('commonFilter');
        $method->setAccessible(true);
        $filter = $method->invoke($controller);

        self::assertCount(1, $filter);
    }

    public function testControllerUsesServiceInterface(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);

        self::assertSame($service, $prop->getValue($controller));
    }

    public function testControllerHasNoWriteMixins(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);
        self::assertFalse($ref->hasProperty('acceptedCreateProperties'));
        self::assertFalse($ref->hasProperty('acceptedUpdateProperties'));
        self::assertFalse($ref->hasProperty('requiredCreateProperties'));
    }

    public function testGetServiceReturnsInjectedService(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        self::assertSame($service, $controller->getService());
    }
}
