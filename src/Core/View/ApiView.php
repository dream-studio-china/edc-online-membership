<?php

namespace App\Core\View;

use App\Core\Query\DqlExpression;
use App\Core\Utils\UUID;
use Doctrine\ORM\QueryBuilder;

trait ApiView
{
    protected function entityNotFoundMessage(): string { return 'Entity not found'; }

    use TransformContent;

    // protected $service = null;
    protected ?string $serviceClass = null;

    /** @return array<string, mixed>|QueryBuilder|DqlExpression */
    protected function commonFilter()
    {
        /** common filter for all entities */
        return [];
    }

    /**
     * Resolve commonFilter and bind internal `this` context when needed.
     *
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function resolvedCommonFilter(): array|QueryBuilder|DqlExpression
    {
        $filter = $this->commonFilter();
        if ($filter instanceof DqlExpression && $filter->usesThis() && $filter->context() === null) {
            return $filter->withContext($this);
        }

        return $filter;
    }

    /**
     * @param array<string, mixed>|QueryBuilder|DqlExpression|null $commonFilter
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function mixIdToCommonFilter(int|string $id, array|QueryBuilder|DqlExpression|null $commonFilter = null)
    {
        return $this->mixToCommonFilter([UUID::is_valid((string) $id) ? 'uuid' : 'id' => $id], $commonFilter);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|QueryBuilder|DqlExpression|null $commonFilter
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function mixToCommonFilter(array $data, array|QueryBuilder|DqlExpression|null $commonFilter = null)
    {
        $filter = $this->resolvedCommonFilter();

        if ($filter instanceof DqlExpression) {
            return $filter->withCriteria($data);
        }

        if ($filter instanceof QueryBuilder) {
            $alias = $filter->getRootAliases()[0];
            foreach ($data as $key => $item) {
                $filter->andWhere("$alias.$key = :$key")->setParameter($key, $item);
            }

            return $filter;
        }

        $base = $commonFilter ?? $this->resolvedCommonFilter();
        if ($base instanceof DqlExpression) {
            $resolved = $base;
            if ($resolved->usesThis() && $resolved->context() === null) {
                $resolved = $resolved->withContext($this);
            }

            return $resolved->withCriteria($data);
        }

        if ($base instanceof QueryBuilder) {
            $alias = $base->getRootAliases()[0];
            foreach ($data as $key => $item) {
                $base->andWhere("$alias.$key = :$key")->setParameter($key, $item);
            }

            return $base;
        }

        $filter = array_merge($data, $base);

        return $filter;
    }
}
