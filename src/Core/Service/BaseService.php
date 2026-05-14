<?php
declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Utils\ArrayCommon;
use App\Core\Utils\FilterDateTime;
use App\Core\Utils\Inflect;
use App\Core\Utils\Math;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Core\Service\ServiceLocatorInterface;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Exception\ValidatorException;


abstract class BaseService implements BaseServiceInterface
{
    /** @var ContainerInterface */
    protected $container;
    /** @var \Doctrine\ORM\EntityManager|object */
    protected $em;
    /** @var \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository */
    protected $rep;
    /** @var string */
    protected $entityClass;
    /** @var Logger */
    protected $logger;
    /** @var UserInterface */
    protected $user;
    /** @var QueryBuilderFactory|null */
    protected $qbFactory;
    /** @var ExpressionServiceInterface|null */
    protected $expressionService;
    /** @var LegacyEvaluator|null */
    protected $legacyEvaluator;
    /** @var SymfonySerializerInterface|null */
    protected $serializerService;
    /** @var ServiceLocatorInterface|null */
    protected $serviceLocator;

    /**
     * BaseService constructor.
     * @param ContainerInterface $container
     * @param string $entityClass
     * @param ServiceLocatorInterface|null $locator
     * @param ExpressionServiceInterface|null $expressionService
     * @param LegacyEvaluator|null $legacyEvaluator
     */
    function __construct(
        ContainerInterface          $container,
        string                      $entityClass,
        ?ServiceLocatorInterface    $locator = null,
        ?ExpressionServiceInterface $expressionService = null,
        ?LegacyEvaluator            $legacyEvaluator = null
    ) {
        $this->container = $container;
        $this->entityClass = $entityClass;

        // Prefer injected locator for testability. If none is provided, create
        // a DefaultServiceLocator from the container so all container access is
        // centralized in one place.
        if ($locator === null) {
            $locator = new \App\Core\Service\DefaultServiceLocator($container);
        }

        // Keep a reference to the locator for later resolution of services
        $this->serviceLocator = $locator;

        // Use locator to resolve commonly used services
        $this->em = $locator->getEntityManager();
        $this->rep = $this->em->getRepository($entityClass);
        $this->logger = $locator->getLogger();

        $tokenStorage = $locator->getTokenStorage();
        $token = $tokenStorage ? $tokenStorage->getToken() : null;
        $this->user = $token ? $token->getUser() : null;

        // still keep container reference for backward compatibility
        $this->container = $container;

        // Allow optional injection of LegacyEvaluator for testability and DI
        // Optional injection of ExpressionService and LegacyEvaluator
        if ($expressionService !== null) {
            $this->expressionService = $expressionService;
        }
        if ($legacyEvaluator !== null) {
            $this->legacyEvaluator = $legacyEvaluator;
        }
    }

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

        // Others
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
     * @param $object
     * @param bool $disableRequest
     * @return null|object
     */
    public function get($object, bool $directly = false)
    {
        if ($object === null) {
            return null;
        }

        // get object
        if ($object instanceof QueryBuilder) {
            try {
                $entity = $object->getQuery()->getSingleResult();
            } catch (NoResultException | NonUniqueResultException $e) {
                $entity = null;
            }
        }
        elseif (is_object($object) && method_exists($object, 'getId')) {
            $entityId = $object->getId();
            $entity = $entityId === null ? null : $this->rep->find($entityId);
        }
        elseif (is_array($object)) {
            $entity = $this->rep->findOneBy($object);
        } else {
            $entity = $this->rep->find($object);
        }

        return $entity;
    }

    /**
     * @param null $object
     * @param null $order
     * @param bool $disableRequest
     * @return int|mixed|string
     * @throws \Exception
     */
    public function list(
        $object = null,
        $order = null,
        bool $disableRequest = true
    ) {
        /*
            // JS
            let query = {
                // Controller level
                'page': 1,
                'limit': 10,
                '@expands': "['category', 'template.category', 'items.specification']",
                '@display': "['id', 'category.name']"
                '@display': "{id: 'entity.getId()', 'category': 'entity.getCategory().getName()'}" // Display with expression

                // Database level
                '@order': 'entity.name | ASC, entity.id | DESC', // order by
                '@filter': 'entity.getUser().getProfile().getCreatedTime() > datetime.get("now") && entity.getCategory().getId() == 5',
                '@dql': 'SELECT p FROM MainBundle:Product p WHERE p.id = 2',
                '@hints': '{"doctrine.forcePartialLoad": true}',

                '@select': 'entity.status, SUM(entity.price) AS sum',
                '@groupBy': 'entity.status',

                // Service level
                // Special filter, low efficient
                '@sort': 'x.getId() > y.getId()',
                '@filter': 'entity.getCategories().count() > 10',
            }
        */

        // Get and parse request
        $em = $this->getEntityManager();
        $request = $this->getCurrentRequest();


        // Normal list

        // Transform to query builder
        // Query builder
        if($object instanceof QueryBuilder) {
            $qb = $object;

            // Get root alias
            $aliases = $object->getRootAliases();
            if(empty($aliases)) {
                throw new ValidatorException('Invalid query build aliases');
            }
            $alias = $aliases[0];
        }
        else {
            // Set root alias
            $alias = 'entity';

            $qb = $this->getQueryBuilderFactory()
                ->create($this->entityClass, $alias)
            ;

            if(is_array($object)) {
                // Transform from $repository->find(['key' => $value]) to Query
                foreach ($object as $key => $value) {
                    $qb
                        ->andWhere("entity.$key = :value_$key")
                        ->setParameter("value_$key", $value)
                    ;
                }
            }
        }


        // Sub DQL
        if ($request && !$disableRequest && ($subDql = $request->query->get('@dql'))) {
            $subDql = $em->createQuery($subDql);
            $qb->andWhere((new Expr())->in("$alias.id", $subDql->getDQL()));
        }

        // Database level filter
        $filterError = false;
        if ($request && !$disableRequest && ($filter = $request->query->get('@filter'))) {

            // Backup current query builder
            $backupQb = clone $qb;

            try {
                $expressionService = $this->getExpressionService();
                $result = $expressionService->buildFilter($filter, $this->entityClass, $this->externalExpressionValues(), $this->getEntityManager());

                // apply filter QB
                /** @var QueryBuilder $filterQb */
                $filterQb = $result['qb'];
                $qb->andWhere((new Expr())->in("$alias.id", $filterQb->getDQL()));

                // set parameters
                foreach ($result['parameters'] as $parameter) {
                    $qb->setParameter($parameter->getName(), $parameter->getValue());
                }
            } catch (\Exception $exception) {
                $this->logger->error('Filter validation exception: '. $exception->getMessage());
                $this->logger->error('Filter source: '. $filter);

                // Reverse
                $filterError = true;
                $qb = $backupQb;
            }
        }

        // Set object
        $object = $qb;

        // Join
        $joins = [];
        $joiner = function(string &$expression, array &$joins, string $rootAlias) {
            // Replace independence select/groupBy 'entity' to root alias
            // 1. Root alias replace pattern: /\w+((\.\w+)+)/g -> root_alias$1
            $expressionAlias = 'entity';
            $aliasPattern = "/$expressionAlias((\.\w+)+)/";
            $aliasReplacement = "$rootAlias$1";
            $expression = preg_replace($aliasPattern, $aliasReplacement, $expression);

            // 2. Match pattern: /(\w+\s*\.\s*)+\w+/g
            $joinPattern = '/(\w+\s*\.\s*)+\w+/';
            if(preg_match_all($joinPattern, $expression, $matches)) {
                foreach ($matches[0] as $item) {
                    $itemParts = explode('.', $item);
                    $joinKey = '';
                    foreach ($itemParts as $i => $match) {
                        if($i == 0) {
                            $joinKey = $match; continue;
                        }
                        $exportValue = $joinKey . '.' . $match;
                        $joinKey .= '_' . $match;

                        if($i >= count($itemParts) -1) break;
                        $joins[$joinKey] = $exportValue;
                    }
                }
            }

            // Translate select fields to correct style
            // 3. Normal replace pattern: /\.(\w+)(?=\.)/g -> _$1
            $expression = preg_replace('/\.(\w+)(?=\.)/', '_$1', $expression);
        };

        // Set select
        // Select demo: entity.user.profile.nickName AS nickName, SUM(entity.user.profile.balance) AS totalBalance
        $select = null;
        if ($request && !$disableRequest && ($select = $request->query->get('@select'))) {
            $joiner($select, $joins, $alias);
            $qb->select($select);
        }

        // Add group by
        // Group by demo: entity.user.profile, entity.user.enabled
        $groupBy = null;
        if ($request && !$disableRequest && ($groupBy = $request->query->get('@groupBy'))) {
            $joiner($groupBy, $joins, $alias);
            $qb->addGroupBy($groupBy);
        }

        // Concat orders
        // Order demo: entity.user.root | ASC, totalBalance | DESC
        // Replace order
        if ($request && !$disableRequest && ($preOrders = $request->query->get('@order'))) {
            $joiner($preOrders, $joins, $alias);

            $preOrders = explode(',', trim($preOrders));
            $order = [];

            foreach ($preOrders as $o) {
                $t = explode('|', $o);
                if (count($t) == 2) {
                    $order[trim($t[0])] = trim($t[1]);
                }
            }
        }
        if($order) {
            foreach ($order as $key => $value) {
                $object->addOrderBy($key, $value);
            }
        }

        // Combine joins
        // Create joins in root DQL automatics
        foreach ($joins as $key => $value) {
            $qb->leftJoin($value, $key);
        }

        /////////////////////////////////////////////////
        // Get main query
        $query = $object->getQuery();

        // Load hints
        if ($request && !$disableRequest && ($hints = $request->query->get('@hints'))) {
            $hints = json_decode($hints);
            foreach($hints as $k => $v) {
                $query->setHint($k, $v);
            }

            // $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
        }

        // Show DQL
        if ($request && !$disableRequest && $request->query->get('@showDQL')) {
            throw new ValidatorException('DQL: '. $qb->getDQL());
        }

        // Check sorter
        if ($request && !$disableRequest && $request->query->get('@sort')) {
            $filterError = true;
        }

        // Request enabled and no filter error, query builder return
        if (!$disableRequest && !$filterError) {
            if($select || $groupBy) {
                return $query->getResult();
            }
            else {
                return $object;
            }
        }

        // Filter error
        else {
            // Find result
            if($select || $groupBy) {
                throw new ValidatorException('Filter error from grouping by or selection.');
            }
            else {
                $entities = $query->getResult();
            }

            if ($request && !$disableRequest) {
                // Legacy filter
                if ($filter = $request->query->get('@filter')) {
                    // Backup filter
                    $entities = array_filter(
                        $entities,
                        function ($entity) use ($filter) {
                            try {
                                return $this->getLegacyEvaluator()->evaluateBool($filter, array_merge(['entity' => $entity], $this->externalExpressionValues()));
                            } catch (\Exception $e) {
                                return false;
                            }
                        }
                    );
                }

                // Legacy sorter
                if ($sorter = $request->query->get('@sort')) {
                    usort(
                        $entities,
                        function ($x, $y) use ($sorter) {
                            try {
                                return $this->getLegacyEvaluator()->evaluateBool($sorter, array_merge(['x' => $x, 'y' => $y], $this->externalExpressionValues()));
                            } catch (\Exception $e) {
                                return false;
                            }
                        }
                    );
                }
            }

            return $entities;
        }
    }


    /**
     * @return mixed
     */
    public function new()
    {
        // Create a new instance of the entity class. Some entities may declare
        // required constructor arguments; in that case we instantiate without
        // invoking the constructor to preserve backwards compatibility with
        // code that expects BaseService::new() to return an empty entity.
        $ref = new \ReflectionClass($this->entityClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            return $ref->newInstance();
        }

        // Constructor requires parameters -> create instance without running it
        return $ref->newInstanceWithoutConstructor();
    }


    /**
     * @param $object
     * @param array|null $data
     * @throws \ReflectionException
     */
    public function updateWithoutListener($object, array $data)
    {
        // TODO: CAN UPDATE ONLY, CREATE IS NOT WORK HERE.

        if (empty($object)) {
            $this->logger->error('Object error, original data: '. json_encode($data));
            throw new ValidatorException('Update object cannot be null');
        }
        else {
            // Get object for updating or create an object
            $object = $object->getId() ? $this->get($object->getId()) : $object;
        }

        if (!empty($data)) {
            // Create query builder
            $em = $this->getEntityManager();
            $qb = $em->createQueryBuilder()
                ->update(get_class($object), 'entity')
                ->where('entity = :entity')
                ->setParameter('entity', $object)
            ;

            foreach ($data as $key => $val) {
                $qb->set("entity.$key", ":$key")
                    ->setParameter($key, $val);
            }

            // Save
            $qb->getQuery()->execute();

            if ($object->getId()) {
                $this->em->refresh($object);
            }
        }
        else {
            throw new ValidatorException('Data cannot be empty');
        }

        return $object->getId() ? $this->get($object->getId()) : $object;
    }


    /**
     * @param $object
     * @param array|null $data
     * @return bool
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \ReflectionException
     */
    public function update($object, array $data = null)
    {
        if (empty($object)) {
            $this->logger->error('Object error, original data: '. json_encode($data));
            throw new ValidatorException('Update object cannot be null');
        }
        else {
            // Get object for updating or create an object
            $object = $object->getId() ? $this->get($object->getId()) : $object;
        }

        if (!empty($data)) {
            $serializer = $this->getSerializer();

            try {
                $reflect = new \ReflectionClass(get_class($object));

                foreach ($data as $key => $val) {
                    if (!$reflect->hasProperty($key) /*|| !is_numeric($val)*/) {
                        // the entity does not have a such property
                        continue;
                    }
                    $property = $reflect->getProperty($key);
                    $annotations = $this->getPropertyMetadata($property);
                    foreach ($annotations as $annotation) {
                        if (
                            $annotation instanceof ManyToOne ||
                            $annotation instanceof OneToOne
                        ) {
                            $dataClass = $annotation->targetEntity;
                            $rep = $this->em->getRepository($dataClass);

                            $entity = null;
                            if ($val && empty($entity = $rep->find($val))) {
                                throw new NotFoundHttpException("The entity of key[$key] is not found");
                            } else {
                                $setter = 'set' . ucfirst($key);
                                $object->$setter($entity);

                                // clear data
                                unset($data[$key]);
                            }
                            break;
                        }
                        elseif(
                            $annotation instanceof ManyToMany ||
                            $annotation instanceof OneToMany
                        ) {
                            $dataClass = $annotation->targetEntity;
                            $rep = $this->em->getRepository($dataClass);

                            // compare origin and new arrays
                            $ucfirst = ucfirst($key);
                            $getter = "get$ucfirst";
                            $entities = $object->$getter() ?? new ArrayCollection();
                            $entitiesIds = $entities->map(function ($entity) {
                                return $entity->getId();
                            })->toArray();

                            // get removes array and adds array
                            $removes = array_values(array_diff($entitiesIds, $val));
                            $adds = array_values(array_diff($val, $entitiesIds));

                            // generate adder and remover
                            $singularize = ucfirst(Inflect::singularize($key));
                            $adder = "add$singularize";
                            $remover = "remove$singularize";

                            foreach ($removes as $remove) {
                                $entity = $rep->find($remove);
                                $object->$remover($entity);
                            }
                            foreach ($adds as $add) {
                                if (empty($entity = $rep->find($add))) {
                                    throw new NotFoundHttpException("The entity of key[$key] is not found");
                                } else {
                                    $object->$adder($entity);
                                }
                            }

                            // clear data
                            unset($data[$key]);
                        }
                        else {
                            if ($this->isDateLikeMapping($annotation, $property)) {
                                $setter = 'set' . ucfirst($key);

                                if($val instanceof \DateTimeInterface) {
                                    $object->$setter($val);
                                }
                                else {
                                    $object->$setter(new \DateTime((string) $val));
                                }

                                unset($data[$key]);
                            }
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                $this->logger->error('Save entity error: '.$e->getMessage());
                return false;
            } catch (\Exception $e) {
                $this->logger->error('Object error, original data: '. json_encode($data));
                throw $e;
            }

            if ($serializer === null) {
                throw new \RuntimeException('Serializer service is not available. Ensure the Symfony serializer is registered and that ServiceLocator provides it.');
            }

            $serializer->deserialize(
                json_encode($data),
                get_class($object),
                'json',
                [
                    'object_to_populate' => $object
                ]
            );
        }

        $validator = $this->getValidator();
        $errors = $validator ? $validator->validate($object) : [];
        if (count($errors) > 0) {
            /*
             * Uses a __toString method on the $errors variable which is a
             * ConstraintViolationList object. This gives us a nice string
             * for debugging.
             */
            $errorsString = (string)$errors;
            throw new ValidatorException($errorsString);
        }

        // Save
        try {
            $this->em->persist($object);
            $this->em->flush();
        }
        catch (UniqueConstraintViolationException $ex) {
            throw new ValidatorException('Duplication entries');
        }
        catch (\Exception $exception) {
            throw $exception;
        }

        return $object;
    }

    /**
     * @param $object
     * @return bool
     * @throws ORMException
     */
    public function remove($object): bool
    {
        $object = $this->get($object);

        $this->em->remove($object);
        try {
            $this->em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
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
                // Service may have been inlined/removed; fall through to NullLogger.
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

        // Prefer locator-provided serializer when available
        if ($this->serviceLocator !== null) {
            try {
                $serializer = $this->serviceLocator->getSerializer();
                if ($serializer !== null) {
                    $this->serializerService = $serializer;
                    return $this->serializerService;
                }
            } catch (\Exception $e) {
                // fall back to container below
            }
        }

        if ($this->container) {
            try {
                if ($this->container->has('serializer')) {
                    $this->serializerService = $this->container->get('serializer');
                    return $this->serializerService;
                }
            } catch (\Exception $e) {
                // ignore and continue to local fallback below
            }
        }

        // Final fallback: build a local Symfony serializer so legacy BaseService
        // update()/deserialize() keeps working even when the framework serializer
        // service is private/inlined or unavailable in the compiled container.
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
        // Create default factory using current EM
        $em = $this->getEntityManager();
        $this->qbFactory = new QueryBuilderFactory($em);
        return $this->qbFactory;
    }

    /**
     * Lazy access to ExpressionService (for filters, sorts, etc). Creates one using current EntityManager.
     * Allows tests to override by extending BaseService and providing custom service.
     * @return ExpressionServiceInterface
     */
    protected function getExpressionService()
    {
        if (isset($this->expressionService) && $this->expressionService) return $this->expressionService;
        // Create default service (no cache) - buildFilter will receive the EntityManager
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
        // Create default service (no cache) - buildFilter will receive the EntityManager
        $this->legacyEvaluator = new LegacyEvaluator();
        return $this->legacyEvaluator;
    }

    /**
     * @return array<int, object>
     */
    private function getPropertyMetadata(\ReflectionProperty $property): array
    {
        $metadata = [];

        foreach ($property->getAttributes() as $attribute) {
            $metadata[] = $attribute->newInstance();
        }

        if (class_exists('Doctrine\\Common\\Annotations\\AnnotationReader')) {
            /** @var object $reader */
            $reader = new \Doctrine\Common\Annotations\AnnotationReader();
            $metadata = array_merge($metadata, $reader->getPropertyAnnotations($property));
        }

        return $metadata;
    }

    private function isDateLikeMapping(object $mapping, \ReflectionProperty $property): bool
    {
        if (property_exists($mapping, 'type')) {
            /** @var mixed $type */
            $type = $mapping->type;
            if (in_array($type, ['datetime', 'date', 'time', 'datetime_immutable', 'date_immutable'], true)) {
                return true;
            }
        }

        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        $name = ltrim($type->getName(), '\\');
        return in_array($name, ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'], true);
    }
}
