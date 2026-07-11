<?php

declare(strict_types=1);

namespace App\Tests\Core\View;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

final class TransformContentTest extends TestCase
{
    public function testServiceGatewayAllowsGetAndList(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);
        $entity = new TransformInputEntity();

        $getResult = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => "Service.get({'name': ':value'}).getId()"],
            $entity,
        );
        $listResult = $controller->transform(
            ['relation' => 'all'],
            ['relation' => "Service.list(':value').getId()"],
            $entity,
        );

        self::assertSame(11, $getResult['relation']);
        self::assertSame(['name' => 'lookup'], $service->getCriteria);
        self::assertSame(22, $listResult['relation']);
        self::assertSame('all', $service->listCriteria);
    }

    public function testServiceGatewayDoesNotExposeOtherServiceMethods(): void
    {
        $service = new TransformLookupService();
        $controller = $this->createController($service);

        $result = $controller->transform(
            ['relation' => 'lookup'],
            ['relation' => 'Service.eraseEverything()'],
            new TransformInputEntity(),
        );

        self::assertSame('lookup', $result['relation']);
        self::assertFalse($service->wasErased);
    }

    private function createController(TransformLookupService $service): object
    {
        $controller = new class extends RestController {
            use ApiView;

            public function transform(array $content, array $transformer, object $entity): array
            {
                return $this->transformContent($content, $transformer, $entity);
            }
        };

        $container = new Container();
        $container->set(
            str_replace('Entity', 'Service', TransformLookupEntity::class) . 'Service',
            $service,
        );
        $controller->setServiceContainer($container);

        return $controller;
    }
}

final class TransformInputEntity
{
    #[ORM\ManyToOne(targetEntity: TransformLookupEntity::class)]
    private ?object $relation = null;
}

final class TransformLookupEntity
{
}

final class TransformLookupService
{
    public mixed $getCriteria = null;
    public mixed $listCriteria = null;
    public bool $wasErased = false;

    public function get(mixed $criteria): TransformLookupResult
    {
        $this->getCriteria = $criteria;

        return new TransformLookupResult(11);
    }

    public function list(mixed $criteria = null): TransformLookupResult
    {
        $this->listCriteria = $criteria;

        return new TransformLookupResult(22);
    }

    public function eraseEverything(): void
    {
        $this->wasErased = true;
    }
}

final class TransformLookupResult
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}
