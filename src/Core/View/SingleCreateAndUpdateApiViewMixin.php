<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidatorException;

trait SingleCreateAndUpdateApiViewMixin
{
    // protected array $requiredCreateProperties = [];
    // protected array $acceptedCreateProperties = [];
    // protected array $requiredUpdateProperties = [];
    // protected array $acceptedUpdateProperties = [];

    protected function defaultCreateValues(): array
    {
        return [];
    }

    protected function defaultUpdateValues(): array
    {
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
        try {
            $service = $this->service ?? $this->get($this->serviceClass);
            $content = json_decode($request->getContent(), true) ?: [];

            $filter = $this->commonFilter();
            $entity = $service->get($filter, false);

            if (empty($entity)) {
                $content = $this->filterCreateProperties($content);
                $content = array_merge($content, $this->defaultCreateValues());
                $entity = $service->new();
            } else {
                $content = $this->filterUpdateProperties($content);
                $content = array_merge($content, $this->defaultUpdateValues());
            }

            if ($entity = $service->update($entity, $content)) {
                return $this->success($entity);
            }

            return $this->warning();
        } catch (ValidatorException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 404, '', 404);
        }
    }

    private function filterCreateProperties(array $content): array
    {
        return $this->filterProperties(
            $content,
            'requiredCreateProperties',
            'acceptedCreateProperties'
        );
    }

    private function filterUpdateProperties(array $content): array
    {
        return $this->filterProperties(
            $content,
            'requiredUpdateProperties',
            'acceptedUpdateProperties'
        );
    }

    private function filterProperties(array $content, string $requiredProp, string $acceptedProp): array
    {
        $hasRequired = property_exists($this, $requiredProp) && $this->{$requiredProp};
        $hasAccepted = property_exists($this, $acceptedProp) && $this->{$acceptedProp};

        if (!$hasRequired && !$hasAccepted) {
            return $content;
        }

        $data = [];

        if ($hasRequired) {
            foreach ($this->{$requiredProp} as $property) {
                if (!array_key_exists($property, $content)) {
                    throw new ValidatorException(ucfirst($property) . ' is required');
                }
                $data[$property] = $content[$property];
            }
        }

        if ($hasAccepted) {
            foreach ($this->{$acceptedProp} as $property) {
                if (array_key_exists($property, $content)) {
                    $data[$property] = $content[$property];
                }
            }
        }

        return $data;
    }
}
