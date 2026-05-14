<?php

namespace App\Core\View;

use Doctrine\ORM\QueryBuilder;

trait ApiView
{
    use TransformContent;

    // protected $service = null;
    protected ?string $serviceClass = null;

    /**
     * @return mixed
     */
    protected function commonFilter()
    {
        /** common filter for all entities */
        return [];
    }

    protected function mixIdToCommonFilter($id, $commonFilter = null)
    {
        return $this->mixToCommonFilter(['id' => $id], $commonFilter);
    }

    protected function mixToCommonFilter(array $data, $commonFilter = null)
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
