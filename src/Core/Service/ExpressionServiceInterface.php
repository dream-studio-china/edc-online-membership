<?php

namespace App\Core\Service;

interface ExpressionServiceInterface
{
    /**
     * Build filter and return ['qb' => Query|QueryBuilder, 'parameters' => array]
     * @param string $filter
     * @param string $dataClass
     * @param array $values
     * @param mixed $em
     * @return array
     */
    public function buildFilter(string $filter, string $dataClass, array $values, $em): array;
}

