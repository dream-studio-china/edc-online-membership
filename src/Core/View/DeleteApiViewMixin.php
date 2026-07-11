<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait DeleteApiViewMixin
{
    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|null
     */
    protected function deletionFilter(array|\Doctrine\ORM\QueryBuilder|null $filter = null): array|\Doctrine\ORM\QueryBuilder|null
    {
        /** list filter for list entities */
        return $filter;
    }

    #[OA\Delete(
        tags: ['Delete'],
        responses: [
            new OA\Response(response: 200, description: 'Api delete view'),
        ]
    )]
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function deleteAction(int|string $id): Response
    {
        $service = $this->service;
        $filter = $this->mixIdToCommonFilter($id);
        $filter = $this->deletionFilter($filter);
        $entity = $service->get($filter, false);

        if (!$entity) {
            return $this->warning('Entity is not found', 404, '', 404);
        }

        return $service->remove($entity) ?
            $this->success('', 'SUCCESS', 204) : $this->warning();
    }
}
