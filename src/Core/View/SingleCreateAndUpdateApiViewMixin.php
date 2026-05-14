<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait SingleCreateAndUpdateApiViewMixin
{
    protected function defaultCreateValues(): array
    {
        /** Default values */
        return [];
    }

    protected function defaultUpdateValues(): array
    {
        /** Default values */
        return [];
    }

    #[OA\Put(
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Update'],
        responses: [
            new OA\Response(response: 200, description: 'Api single create and update view'),
        ]
    )]
    #[Route('', name: 'update', methods: ['PUT'])]
    public function updateAction(Request $request): Response
    {
        $service = $this->service ?? $this->get($this->serviceClass);
        $content = json_decode($request->getContent(), true) ? : [];

        $filter = $this->commonFilter();
        $entity = $service->get($filter, false);

        if(empty($entity)) {
            $entity = $service->new();
            $content = array_merge($content, $this->defaultCreateValues());
        }
        else {
            $content = array_merge($content, $this->defaultUpdateValues());
        }

        if ($entity = $service->update($entity, $content)) {
            return $this->success($entity);
        } else {
            return $this->warning();
        }
    }
}
