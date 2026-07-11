<?php

namespace App\Core\View;

use Doctrine\ORM\QueryBuilder;

trait ApiView
{
    use TransformContent;

    // protected $service = null;
    protected ?string $serviceClass = null;

    /** @return array<string, mixed>|QueryBuilder */
    protected function commonFilter(): array|QueryBuilder
    {
        /** common filter for all entities */
        return [];
    }

    /**
     * @param array<string, mixed>|QueryBuilder|null $commonFilter
     * @return array<string, mixed>|QueryBuilder
     */
    protected function mixIdToCommonFilter(int|string $id, array|QueryBuilder|null $commonFilter = null): array|QueryBuilder
    {
        return $this->mixToCommonFilter(['id' => $id], $commonFilter);
    }

    /**
     * @param array<string, mixed>|QueryBuilder|null $commonFilter
     * @return array<string, mixed>|QueryBuilder
     */
    protected function mixToCommonFilter(array $data, array|QueryBuilder|null $commonFilter = null): array|QueryBuilder
    {
        $filter = $this->commonFilter();

        if($filter instanceof QueryBuilder) {
            $alias = $filter->getRootAliases()[0];
            foreach ($data as $key => $item) {
                $filter->andWhere("$alias.$key = :$key")->setParameter($key, $item);
            }
        }
        else {
            $filter = array_merge($data, $commonFilter ?? $this->commonFilter());
        }

        return $filter;
    }
}
