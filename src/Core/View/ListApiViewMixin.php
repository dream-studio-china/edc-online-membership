<?php

namespace App\Core\View;

use App\Core\Service\BaseService;
use Doctrine\Common\Collections\ArrayCollection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait ListApiViewMixin
{
    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|null
     */
    protected function listFilter(array|\Doctrine\ORM\QueryBuilder|null $filter = null)
    {
        /** list filter for list entities */
        return $filter;
    }

    protected function listProcessor(mixed $entities): mixed
    {
        /** list processor for list entities */
        return $entities;
    }

    protected function listResponses(mixed $entities): mixed
    {
        /** list responses for list entities */
        return $entities;
    }

    #[OA\Get(
        tags: ['List'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@order', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@dql', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@select', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@groupBy', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@hints', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@filter', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@expands', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@display', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@showDQL', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api list view'),
        ]
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $service = $this->service;
        $filter = $this->listFilter($this->commonFilter());
        $entities = $this->listProcessor(
            $service->list($filter, null, false)
        );
        $entities = $this->listResponses($entities);
        return $this->success($entities);
    }
}
