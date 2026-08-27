<?php

namespace App\Core\View;

use App\Core\Utils\UUID;
use Doctrine\ORM\QueryBuilder;

trait ApiView
{
    protected function entityNotFoundMessage(): string { return 'Entity not found'; }

    use TransformContent;

    // protected $service = null;
    protected ?string $serviceClass = null;

    /** @return array<string, mixed>|QueryBuilder */
    protected function commonFilter()
    {
        /** common filter for all entities */
        return [];
    }

    /**
     * @param array<string, mixed>|QueryBuilder|null $commonFilter
     * @return array<string, mixed>|QueryBuilder
     */
    protected function mixIdToCommonFilter(int|string $id, array|QueryBuilder|null $commonFilter = null)
    {
        return $this->mixToCommonFilter([UUID::is_valid((string) $id) ? 'uuid' : 'id' => $id], $commonFilter);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|QueryBuilder|null $commonFilter
     * @return array<string, mixed>|QueryBuilder
     */
    protected function mixToCommonFilter(array $data, array|QueryBuilder|null $commonFilter = null)
    {
        $filter = $this->commonFilter();

        if ($filter instanceof QueryBuilder) {
            $alias = $filter->getRootAliases()[0];
            foreach ($data as $key => $item) {
                $filter->andWhere("$alias.$key = :$key")->setParameter($key, $item);
            }
        }
        else {
            $base = $commonFilter ?? $this->commonFilter();
            if (is_array($base)) {
                $filter = array_merge($data, $base);
            }
        }

        return $filter;
    }
}
