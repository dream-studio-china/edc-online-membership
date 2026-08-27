<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait DetailApiViewMixin
{
    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|null
     */
    protected function detailFilter(array|\Doctrine\ORM\QueryBuilder|null $filter = null)
    {
        /** list filter for list entities */
        return $filter;
    }

    protected function detailProcessor(?object $entity): ?object
    {
        /** detail processor */
        return $entity;
    }

    protected function detailResponse(?object $entity): mixed
    {
        /** detail response */
        return $entity;
    }

    #[OA\Get(
        tags: ['Detail'],
        parameters: [
            new OA\Parameter(name: '@expands', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api detail view'),
        ]
    )]
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function detailAction(int|string $id): Response
    {
        $service = $this->service;
        $filter = $this->mixIdToCommonFilter($id);
        $filter = $this->detailFilter($filter);
        if ($filter === null) {
            return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
        }
        $entity = $this->detailProcessor(
            $service->get($filter, false)
        );
        $response = $this->detailResponse($entity);

        return $response ?
            $this->success($response):
            $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
    }
}
