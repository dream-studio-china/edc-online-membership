<?php
declare(strict_types=1);

namespace App\Core\Service\Concern;

use App\Core\Service\ExpressionService;
use App\Core\Service\LegacyEvaluator;
use App\Core\Service\QueryBuilderFactory;
use App\Core\Utils\ArrayCommon;
use App\Core\Utils\FilterDateTime;
use App\Core\Utils\Math;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

trait BaseServiceInfrastructureTrait
{
    /**
     * TODO: List result will HIGHLY REDUCE PERFORMANCE in database, MUST OPTIMIZE
     *
     * @param $list
     * @return ArrayCollection
     */
    public static function listResultToCollection($list): ArrayCollection
    {
        if($list instanceof QueryBuilder) {
            $result = $list->getQuery()->getResult();
            if($result) {
                return new ArrayCollection($result);
            }
        }
        elseif(is_array($list)) {
            return new ArrayCollection($list);
        }

        return new ArrayCollection();
    }

    /**
     * @return array
     */
    public function externalExpressionValues(): array
    {
        return [
            'math' => new Math(),
            'datetime' => new FilterDateTime(),
            'Math' => new Math(),
            'Datetime' => new FilterDateTime(),
            'ArrayCommon' => new ArrayCommon(),
        ];
    }

    /**
     * Get EntityManager (resolved from locator or container). Lazily initializes $this->em if needed.
     * @return \Doctrine\ORM\EntityManagerInterface
     */
    protected function getEntityManager()
    {
        if ($this->em) return $this->em;
        if ($this->container && $this->container->has('doctrine.orm.entity_manager')) {
            $this->em = $this->container->get('doctrine.orm.entity_manager');
            return $this->em;
        }
        throw new \RuntimeException('EntityManager not available');
    }

    /**
     * Get repository for given class (or default entityClass).
     */
    protected function getRepository(string $class = null)
    {
        if ($class === null) $class = $this->entityClass;
        if ($this->rep && $class === $this->entityClass) return $this->rep;
        return $this->getEntityManager()->getRepository($class);
    }

    /**
     * Get logger (from locator or container).
     */
    protected function getLogger()
    {
        if ($this->logger) return $this->logger;
        if ($this->container) {
            try {
                if ($this->container->has('logger')) {
                    $this->logger = $this->container->get('logger');
                    return $this->logger;
                }
            } catch (\Exception $e) {
            }
        }
        return new \Psr\Log\NullLogger();
    }

    /**
     * Get serializer if available.
     */
    protected function getSerializer()
    {
        if ($this->serializerService !== null) {
            return $this->serializerService;
        }

        if ($this->serviceLocator !== null) {
            try {
                $serializer = $this->serviceLocator->getSerializer();
                if ($serializer !== null) {
                    $this->serializerService = $serializer;
                    return $this->serializerService;
                }
            } catch (\Exception $e) {
            }
        }

        if ($this->container) {
            try {
                if ($this->container->has('serializer')) {
                    $this->serializerService = $this->container->get('serializer');
                    return $this->serializerService;
                }
            } catch (\Exception $e) {
            }
        }

        $this->serializerService = new Serializer(
            [
                new DateTimeNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(),
            ],
            [
                new JsonEncoder(),
            ]
        );

        return $this->serializerService;
    }

    /**
     * Get validator if available.
     */
    protected function getValidator()
    {
        if ($this->container && $this->container->has('validator')) {
            return $this->container->get('validator');
        }
        return null;
    }

    /**
     * Get request stack if available.
     */
    protected function getRequestStack()
    {
        if ($this->container && $this->container->has('request_stack')) {
            return $this->container->get('request_stack');
        }
        return null;
    }

    /**
     * Get current Request if available (wrapper for request_stack access).
     * Centralized so subclasses/tests can override or mock.
     * @return \Symfony\Component\HttpFoundation\Request|null
     */
    protected function getCurrentRequest()
    {
        $requestStack = $this->getRequestStack();
        return $requestStack ? $requestStack->getCurrentRequest() : null;
    }

    /**
     * Lazy access to QueryBuilderFactory. Creates one using current EntityManager.
     * Allows tests to override by extending BaseService and providing custom factory.
     * @return QueryBuilderFactory
     */
    protected function getQueryBuilderFactory()
    {
        if (isset($this->qbFactory) && $this->qbFactory) return $this->qbFactory;
        $em = $this->getEntityManager();
        $this->qbFactory = new QueryBuilderFactory($em);
        return $this->qbFactory;
    }

    /**
     * Lazy access to ExpressionService (for filters, sorts, etc). Creates one using current EntityManager.
     * Allows tests to override by extending BaseService and providing custom service.
     * @return \App\Core\Service\ExpressionServiceInterface
     */
    protected function getExpressionService()
    {
        if (isset($this->expressionService) && $this->expressionService) return $this->expressionService;
        $this->expressionService = new ExpressionService();
        return $this->expressionService;
    }

    /**
     * Lazy access to LegacyEvaluator service.
     * @return LegacyEvaluator
     */
    protected function getLegacyEvaluator()
    {
        if (isset($this->legacyEvaluator) && $this->legacyEvaluator) return $this->legacyEvaluator;
        $this->legacyEvaluator = new LegacyEvaluator();
        return $this->legacyEvaluator;
    }

    /**
     * Execute a callable within a database transaction.
     * The callable receives the EntityManager for convenience.
     * Flushes before commit, rolls back on any Throwable.
     * Falls back to plain execution when the EM is a fake/mock without transaction support.
     */
    public function wrapInTransaction(callable $fn): mixed
    {
        $em = $this->getEntityManager();

        if (!method_exists($em, 'beginTransaction') || !method_exists($em, 'commit')) {
            $result = $fn($em);
            if (method_exists($em, 'flush')) {
                $em->flush();
            }
            return $result;
        }

        $em->beginTransaction();
        try {
            $result = $fn($em);
            $em->flush();
            $em->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }
            throw $e;
        }
    }
}
