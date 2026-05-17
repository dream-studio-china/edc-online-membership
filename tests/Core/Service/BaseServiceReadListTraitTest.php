<?php

namespace App\Tests\Core\Service;

use App\Core\Service\BaseService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\NullLogger;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class BaseServiceReadListTraitTest extends TestCase
{
    private function createService(ContainerInterface $container, string $entityClass): BaseService
    {
        return new class($container, $entityClass) extends BaseService {
            public function __construct(ContainerInterface $container, string $entityClass)
            {
                parent::__construct($container, $entityClass);
            }
        };
    }

    // -------------------------------------------------------
    //  get()
    // -------------------------------------------------------

    public function testGetByIntegerId(): void
    {
        $entity = new ReadListEntity(7, 'test');
        $repo = new ReadListFakeRepository([7 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get(7);

        self::assertSame($entity, $result);
    }

    public function testGetByObjectWithId(): void
    {
        $entity = new ReadListEntity(5, 'obj');
        $repo = new ReadListFakeRepository([5 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get($entity);

        self::assertSame($entity, $result);
    }

    public function testGetByArrayCriteria(): void
    {
        $entity = new ReadListEntity(3, 'match');
        $repo = new ReadListFakeRepository([3 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get(['name' => 'match']);

        self::assertSame($entity, $result);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        self::assertNull($service->get(999));
    }

    public function testGetReturnsNullForNullInput(): void
    {
        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        self::assertNull($service->get(null));
    }

    public function testGetByQueryBuilderReturnsSingleResult(): void
    {
        $entity = new ReadListEntity(1, 'qb');
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getSingleResult')->willReturn($entity);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getQuery')->willReturn($query);

        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get($qb);
        self::assertSame($entity, $result);
    }

    public function testGetByQueryBuilderNoResultReturnsNull(): void
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getSingleResult')->willThrowException(new \Doctrine\ORM\NoResultException());

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getQuery')->willReturn($query);

        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        self::assertNull($service->get($qb));
    }

    // -------------------------------------------------------
    //  list()
    // -------------------------------------------------------

    public function testListWithNoFilterReturnsAll(): void
    {
        $e1 = new ReadListEntity(1, 'alpha');
        $e2 = new ReadListEntity(2, 'beta');
        $repo = new ReadListFakeRepository([1 => $e1, 2 => $e2]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$e1, $e2]);
        $container = new ReadListFakeContainer($em);

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(null, null, true);

        self::assertIsArray($result);
        self::assertCount(2, $result);
    }

    public function testListWithArrayFilter(): void
    {
        $e1 = new ReadListEntity(1, 'alpha');
        $e2 = new ReadListEntity(2, 'beta');
        $repo = new ReadListFakeRepository([1 => $e1, 2 => $e2]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$e1]); // filter return alpha only
        $container = new ReadListFakeContainer($em);

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(['name' => 'alpha'], null, true);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('alpha', $result[0]->getName());
    }

    public function testListWithQueryBuilderInput(): void
    {
        $e1 = new ReadListEntity(1, 'x');
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([$e1]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['entity']);
        $qb->method('getQuery')->willReturn($query);

        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list($qb, null, true);

        self::assertIsArray($result);
        self::assertCount(1, $result);
    }

    public function testListWithNullReturnsAll(): void
    {
        $e1 = new ReadListEntity(1, 'one');
        $e2 = new ReadListEntity(2, 'two');
        $e3 = new ReadListEntity(3, 'three');
        $repo = new ReadListFakeRepository([1 => $e1, 2 => $e2, 3 => $e3]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$e1, $e2, $e3]);
        $container = new ReadListFakeContainer($em);

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(null, null, true);

        self::assertIsArray($result);
        self::assertCount(3, $result);
    }
}

// -------------------------------------------------------
//  Fake dependencies
// -------------------------------------------------------

final class ReadListEntity
{
    public function __construct(private ?int $id = null, private string $name = '') {}
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
}

final class ReadListFakeRepository
{
    public function __construct(private array $byId) {}
    public function find($id): ?object { return $this->byId[$id] ?? null; }
    public function findOneBy(array $criteria): ?object
    {
        foreach ($this->byId as $entity) {
            $match = true;
            foreach ($criteria as $k => $v) {
                $getter = 'get' . ucfirst($k);
                if (!method_exists($entity, $getter) || $entity->$getter() !== $v) { $match = false; break; }
            }
            if ($match) return $entity;
        }
        return null;
    }
}

final class ReadListFakeEntityManager
{
    private array $queryResults = [];

    public function __construct(private readonly ReadListFakeRepository $repo) {}

    public function setQueryResults(array $results): void { $this->queryResults = $results; }

    public function getRepository(string $class): ReadListFakeRepository { return $this->repo; }

    public function createQuery(string $dql): object
    {
        return new class { public function getDQL(): string { return ''; } };
    }

    public function createQueryBuilder(): object
    {
        $results = $this->queryResults;
        return new class ($results) {
            private array $wheres = [];
            private array $params = [];
            private string $alias = 'entity';
            private $selectClause = null;
            private array $orderBy = [];
            private array $joins = [];

            public function __construct(private array $results) {}
            public function select($s): self { $this->selectClause = $s; return $this; }
            public function from(string $from, string $alias): self { return $this; }
            public function where(string $condition): self { return $this; }
            public function andWhere(string $condition): self { $this->wheres[] = $condition; return $this; }
            public function setParameter(string $name, mixed $value): self { $this->params[$name] = $value; return $this; }
            public function addOrderBy(string $field, string $order): self { $this->orderBy[$field] = $order; return $this; }
            public function addGroupBy(string $group): self { $this->groupByClause = $group; return $this; }
            public function leftJoin(string $join, string $alias): self { $this->joins[$alias] = $join; return $this; }
            public function getRootAliases(): array { return [$this->alias]; }
            public function getDQL(): string { return 'SELECT ...'; }
            public function getQuery(): object
            {
                return new class ($this->results) {
                    public function __construct(private array $results) {}
                    public function setHint(string $k, mixed $v): void {}
                    public function getResult(): array { return $this->results; }
                    public function getSingleResult(): mixed { return $this->results[0] ?? null; }
                };
            }
        };
    }
}

final class ReadListFakeContainer implements ContainerInterface
{
    public function __construct(private readonly ReadListFakeEntityManager $em) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'request_stack' => new RequestStack(),
            'security.token_storage' => new class { public function getToken(): ?object { return null; } },
            'serializer' => new \Symfony\Component\Serializer\Serializer([
                new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer()
            ], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]),
            'validator' => null,
            default => null,
        };
    }

    public function has(string $id): bool { return true; }
    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}
