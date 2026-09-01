<?php

namespace App\Tests\Core\Service;

use App\Core\Service\ExpressionService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class ExpressionServiceTest extends TestCase
{
    public function testBuildFilterUsesParsedAssemblerResult(): void
    {
        $service = new class extends ExpressionService {
            protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
            {
                $qb = new class {
                    public function getDQL(): string
                    {
                        return 'DQL_PLACEHOLDER';
                    }
                };

                $parameter = new class {
                    public function getName(): string
                    {
                        return 'p1';
                    }

                    public function getValue(): int
                    {
                        return 42;
                    }
                };

                return ['qb' => $qb, 'parameters' => [$parameter]];
            }
        };

        $result = $service->buildFilter('a==b', 'Entity', [], new \stdClass());

        self::assertArrayHasKey('qb', $result);
        self::assertArrayHasKey('parameters', $result);
        self::assertSame('p1', $result['parameters'][0]->getName());
        self::assertSame(42, $result['parameters'][0]->getValue());
    }

    public function testBuildFilterCachesDqlAndNormalizesCollectionParameters(): void
    {
        $cache = new ExpressionArrayCache();
        $service = new class($cache) extends ExpressionService {
            public int $assemblies = 0;

            protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
            {
                ++$this->assemblies;
                $parameter = new \Doctrine\ORM\Query\Parameter('status', $values['status']);
                $queryBuilder = new class {
                    public function getDQL(): string { return 'SELECT item FROM Item item WHERE item.status = :status'; }
                };

                return ['qb' => $queryBuilder, 'parameters' => new ArrayCollection([$parameter])];
            }
        };
        $em = new class {
            public array $queries = [];
            public function createQuery(string $dql): object
            {
                $this->queries[] = $dql;
                return new class($dql) {
                    public function __construct(public string $dql) {}
                };
            }
        };

        $first = $service->buildFilter('status == :status', 'Item', ['status' => 'active'], $em);
        $second = $service->buildFilter('status == :status', 'Item', ['status' => 'inactive'], $em);

        self::assertSame(1, $service->assemblies);
        self::assertSame('active', $first['parameters'][0]->getValue());
        self::assertSame('active', $second['parameters'][0]->getValue());
        self::assertSame(['SELECT item FROM Item item WHERE item.status = :status'], $em->queries);
        self::assertCount(1, $cache->values);
        self::assertStringStartsWith('expr_', array_key_first($cache->values));
    }
}

final class ExpressionArrayCache implements CacheInterface
{
    public array $values = [];
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool { $this->values[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->values[$key]); return true; }
    public function clear(): bool { $this->values = []; return true; }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { foreach ($keys as $key) yield $key => $this->get($key, $default); }
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { foreach ($values as $key => $value) $this->set($key, $value, $ttl); return true; }
    public function deleteMultiple(iterable $keys): bool { foreach ($keys as $key) $this->delete($key); return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
}
