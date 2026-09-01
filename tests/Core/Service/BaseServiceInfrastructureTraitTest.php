<?php

namespace App\Tests\Core\Service;

use App\Core\Service\BaseService;
use App\Core\Service\ServiceLocatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;

final class BaseServiceInfrastructureTraitTest extends TestCase
{
    private function createService(ContainerInterface $container, string $entityClass, ?ServiceLocatorInterface $locator = null): BaseService
    {
        return new class($container, $entityClass, $locator) extends BaseService {
            public function __construct(ContainerInterface $container, string $entityClass, ?ServiceLocatorInterface $locator)
            {
                parent::__construct($container, $entityClass, $locator);
            }
            // Expose protected methods for testing
            public function callGetEntityManager() { return $this->getEntityManager(); }
            public function callGetRepository(?string $class = null) { return $this->getRepository($class); }
            public function callGetLogger() { return $this->getLogger(); }
            public function callGetSerializer() { return $this->getSerializer(); }
            public function callGetValidator() { return $this->getValidator(); }
            public function callGetRequestStack() { return $this->getRequestStack(); }
            public function callGetCurrentRequest() { return $this->getCurrentRequest(); }
            public function callGetQueryBuilderFactory() { return $this->getQueryBuilderFactory(); }
            public function callGetExpressionService() { return $this->getExpressionService(); }
            public function callGetLegacyEvaluator() { return $this->getLegacyEvaluator(); }
            public function clearEntityManager(): void { $this->em = null; }
            public function clearLogger(): void { $this->logger = null; }
        };
    }

    public function testListResultToCollectionWithArray(): void
    {
        $result = BaseService::listResultToCollection([1, 2, 3]);
        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertCount(3, $result);
    }

    public function testListResultToCollectionWithInvalid(): void
    {
        $result = BaseService::listResultToCollection(null);
        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testListResultToCollectionWithString(): void
    {
        $result = BaseService::listResultToCollection('invalid');
        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testExternalExpressionValues(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $values = $service->externalExpressionValues();

        self::assertArrayHasKey('math', $values);
        self::assertArrayHasKey('datetime', $values);
        self::assertArrayHasKey('Math', $values);
        self::assertArrayHasKey('Datetime', $values);
        self::assertArrayHasKey('ArrayCommon', $values);
    }

    public function testGetEntityManagerLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetEntityManager();
        self::assertSame($em, $result);
    }

    public function testGetLoggerLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetLogger();
        self::assertInstanceOf(NullLogger::class, $result);
    }

    public function testGetSerializerCreatesFallback(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em, false); // no serializer
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetSerializer();
        self::assertInstanceOf(\Symfony\Component\Serializer\SerializerInterface::class, $result);
    }

    public function testGetValidatorReturnsNullWhenMissing(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em, true, false); // no validator
        $service = $this->createService($container, InfraDummyEntity::class);

        self::assertNull($service->callGetValidator());
    }

    public function testGetQueryBuilderFactoryLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetQueryBuilderFactory();
        self::assertNotNull($result);
    }

    public function testGetExpressionServiceLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetExpressionService();
        self::assertNotNull($result);
    }

    public function testGetLegacyEvaluatorLazy(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetLegacyEvaluator();
        self::assertNotNull($result);
    }

    public function testGetCurrentRequestWithoutRequestStack(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $container = new InfraFakeNoRequestStackContainer($em);
        $service = $this->createService($container, InfraDummyEntity::class);

        self::assertNull($service->callGetCurrentRequest());
    }

    public function testGetCurrentRequestWithActiveRequest(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);
        $container = new InfraFakeContainer($em, true, true, $stack);
        $service = $this->createService($container, InfraDummyEntity::class);

        $result = $service->callGetCurrentRequest();
        self::assertSame($request, $result);
    }

    public function testInfrastructureUsesCachedRepositoryAndFailsWithoutAnEntityManager(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $service = $this->createService(new InfraFakeNoEntityManagerContainer($em), InfraDummyEntity::class);

        self::assertSame($repo, $service->callGetRepository());
        $service->clearEntityManager();
        $this->expectException(\RuntimeException::class);
        $service->callGetEntityManager();
    }

    public function testSerializerUsesLocatorAndLoggerFallsBackWhenContainerHasNoLogger(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraFakeEntityManager($repo);
        $serializer = new \Symfony\Component\Serializer\Serializer([new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer()]);
        $locator = new class($em, $serializer) implements ServiceLocatorInterface {
            public function __construct(private object $em, private object $serializer) {}
            public function getEntityManager() { return $this->em; }
            public function getLogger() { return new NullLogger(); }
            public function getTokenStorage() { return null; }
            public function getRequestStack() { return null; }
            public function getSerializer() { return $this->serializer; }
            public function getValidator() { return null; }
        };
        $service = $this->createService(new InfraFakeNoLoggerContainer($em), InfraDummyEntity::class, $locator);
        $service->clearLogger();

        self::assertSame($serializer, $service->callGetSerializer());
        self::assertInstanceOf(NullLogger::class, $service->callGetLogger());
    }

    public function testWrapInTransactionCommitsSuccessfulWorkAndRollsBackFailures(): void
    {
        $repo = new InfraFakeRepository();
        $em = new InfraTransactionalEntityManager($repo);
        $service = $this->createService(new InfraFakeContainer($em), InfraDummyEntity::class);

        self::assertSame('saved', $service->wrapInTransaction(static fn (object $manager): string => $manager === $em ? 'saved' : 'wrong'));
        self::assertSame(['begin', 'flush', 'commit'], $em->events);

        try {
            $service->wrapInTransaction(static function (): void { throw new \RuntimeException('abort'); });
            self::fail('Expected transaction failure to be rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('abort', $e->getMessage());
        }
        self::assertSame(['begin', 'flush', 'commit', 'begin', 'rollback'], $em->events);
    }
}

final class InfraDummyEntity
{
    public function __construct(private ?int $id = null) {}
    public function getId(): ?int { return $this->id; }
}

final class InfraFakeRepository
{
    public function find($id): ?object { return null; }
    public function findOneBy(array $criteria): ?object { return null; }
}

final class InfraFakeEntityManager
{
    public function __construct(private readonly InfraFakeRepository $repo) {}
    public function getRepository(string $class): InfraFakeRepository { return $this->repo; }
    public function createQueryBuilder(): object { throw new \LogicException('not needed'); }
}

final class InfraTransactionalEntityManager
{
    public array $events = [];
    private bool $active = false;
    public function __construct(private readonly InfraFakeRepository $repo) {}
    public function getRepository(string $class): InfraFakeRepository { return $this->repo; }
    public function beginTransaction(): void { $this->active = true; $this->events[] = 'begin'; }
    public function flush(): void { $this->events[] = 'flush'; }
    public function commit(): void { $this->active = false; $this->events[] = 'commit'; }
    public function rollback(): void { $this->active = false; $this->events[] = 'rollback'; }
    public function getConnection(): object
    {
        return new class($this) {
            public function __construct(private InfraTransactionalEntityManager $manager) {}
            public function isTransactionActive(): bool { return $this->manager->active(); }
        };
    }
    public function active(): bool { return $this->active; }
}

class InfraFakeContainer implements ContainerInterface
{
    public function __construct(
        private readonly object $em,
        private readonly bool $hasSerializer = true,
        private readonly bool $hasValidator = true,
        private ?RequestStack $requestStack = null,
    ) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'request_stack' => $this->requestStack,
            'security.token_storage' => new class { public function getToken(): ?object { return null; } },
            'serializer' => $this->hasSerializer ? new \Symfony\Component\Serializer\Serializer([new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer()], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]) : null,
            'validator' => $this->hasValidator ? new class { public function validate(object $obj) { return []; } } : null,
            default => null,
        };
    }

    public function has(string $id): bool
    {
        if ($id === 'request_stack') return $this->requestStack !== null;
        if ($id === 'serializer') return $this->hasSerializer;
        if ($id === 'validator') return $this->hasValidator;
        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}

final class InfraFakeNoRequestStackContainer implements ContainerInterface
{
    public function __construct(private readonly object $em) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'security.token_storage' => new class { public function getToken(): ?object { return null; } },
            default => null,
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, ['doctrine.orm.entity_manager', 'logger', 'security.token_storage'], true);
    }

    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return null; }
    public function hasParameter(string $name): bool { return false; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}

final class InfraFakeNoEntityManagerContainer extends InfraFakeContainer
{
    public function has(string $id): bool
    {
        return $id !== 'doctrine.orm.entity_manager' && parent::has($id);
    }
}

final class InfraFakeNoLoggerContainer extends InfraFakeContainer
{
    public function has(string $id): bool
    {
        return $id !== 'logger' && parent::has($id);
    }
}
