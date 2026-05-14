<?php
declare(strict_types=1);

namespace App\Core\Service\Concern;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Validator\Exception\ValidatorException;

trait BaseServiceReadListTrait
{
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
        $em = $this->getEntityManager();
        $request = $this->getCurrentRequest();

        if($object instanceof QueryBuilder) {
            $qb = $object;

            $aliases = $object->getRootAliases();
            if(empty($aliases)) {
                throw new ValidatorException('Invalid query build aliases');
            }
            $alias = $aliases[0];
        }
        else {
            $alias = 'entity';

            $qb = $this->getQueryBuilderFactory()
                ->create($this->entityClass, $alias)
            ;

            if(is_array($object)) {
                foreach ($object as $key => $value) {
                    $qb
                        ->andWhere("entity.$key = :value_$key")
                        ->setParameter("value_$key", $value)
                    ;
                }
            }
        }

        if ($request && !$disableRequest && ($subDql = $request->query->get('@dql'))) {
            $subDql = $em->createQuery($subDql);
            $qb->andWhere((new Expr())->in("$alias.id", $subDql->getDQL()));
        }

        $filterError = false;
        if ($request && !$disableRequest && ($filter = $request->query->get('@filter'))) {
            $backupQb = clone $qb;

            try {
                $expressionService = $this->getExpressionService();
                $result = $expressionService->buildFilter($filter, $this->entityClass, $this->externalExpressionValues(), $this->getEntityManager());

                /** @var QueryBuilder $filterQb */
                $filterQb = $result['qb'];
                $qb->andWhere((new Expr())->in("$alias.id", $filterQb->getDQL()));

                foreach ($result['parameters'] as $parameter) {
                    $qb->setParameter($parameter->getName(), $parameter->getValue());
                }
            } catch (\Exception $exception) {
                $this->logger->error('Filter validation exception: '. $exception->getMessage());
                $this->logger->error('Filter source: '. $filter);

                $filterError = true;
                $qb = $backupQb;
            }
        }

        $object = $qb;

        $joins = [];
        $joiner = function(string &$expression, array &$joins, string $rootAlias) {
            $expressionAlias = 'entity';
            $aliasPattern = "/$expressionAlias((\.\w+)+)/";
            $aliasReplacement = "$rootAlias$1";
            $expression = preg_replace($aliasPattern, $aliasReplacement, $expression);

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

            $expression = preg_replace('/\.(\w+)(?=\.)/', '_$1', $expression);
        };

        $select = null;
        if ($request && !$disableRequest && ($select = $request->query->get('@select'))) {
            $joiner($select, $joins, $alias);
            $qb->select($select);
        }

        $groupBy = null;
        if ($request && !$disableRequest && ($groupBy = $request->query->get('@groupBy'))) {
            $joiner($groupBy, $joins, $alias);
            $qb->addGroupBy($groupBy);
        }

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

        foreach ($joins as $key => $value) {
            $qb->leftJoin($value, $key);
        }

        $query = $object->getQuery();

        if ($request && !$disableRequest && ($hints = $request->query->get('@hints'))) {
            $hints = json_decode($hints);
            foreach($hints as $k => $v) {
                $query->setHint($k, $v);
            }
        }

        if ($request && !$disableRequest && $request->query->get('@showDQL')) {
            throw new ValidatorException('DQL: '. $qb->getDQL());
        }

        if ($request && !$disableRequest && $request->query->get('@sort')) {
            $filterError = true;
        }

        if (!$disableRequest && !$filterError) {
            if($select || $groupBy) {
                return $query->getResult();
            }
            else {
                return $object;
            }
        }

        else {
            if($select || $groupBy) {
                throw new ValidatorException('Filter error from grouping by or selection.');
            }
            else {
                $entities = $query->getResult();
            }

            if ($request && !$disableRequest) {
                if ($filter = $request->query->get('@filter')) {
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
}
