<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait DetailApiViewMixin
{
    protected function detailFilter($filter = null)
    {
        /** list filter for list entities */
        return $filter;
    }

    protected function detailProcessor($entity)
    {
        /** detail processor */
        return $entity;
    }

    protected function detailResponse($entity)
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
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function detailAction($id): Response
    {
        $service = $this->service ?? $this->get($this->serviceClass);
        $filter = $this->mixIdToCommonFilter($id);
        $filter = $this->detailFilter($filter);
        $entity = $this->detailProcessor(
            $service->get($filter, false)
        );
        $response = $this->detailResponse($entity);

        return $response ?
            $this->success($response):
            $this->warning('Entity is not found');
    }
}
