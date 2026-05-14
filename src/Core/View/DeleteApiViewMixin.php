<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait DeleteApiViewMixin
{
    protected function deletionFilter($filter = null)
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
    public function deleteAction($id): Response
    {
        $service = $this->service ?? $this->get($this->serviceClass);
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
