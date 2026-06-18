<?php

namespace App\Core\View;

use App\Core\Utils\FixJSON;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Exception\ValidatorException;

trait CreateApiViewMixin
{
    private static $TYPE_OBJECT = 'object';
    private static $TYPE_ARRAY = 'array';

    //protected $requiredCreateProperties = [];
    //protected $acceptedCreateProperties = [];

    protected function defaultCreateValues(): array
    {
        /** Default values */
        return [];
    }

    /**
     * @param array $content
     * @param null $entity
     * @return array
     */
    protected function processCreateContent(array $content, $entity = null)
    {
        /** Default values */
        return $content;
    }

    /**
     * @param $content
     * @param $entity
     * @return mixed
     */
    protected function processEntity($content, $entity)
    {
        return $entity;
    }

    protected function afterCreated($entity)
    {
        /** Created entity */
        return $entity;
    }

    #[OA\Post(
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Create'],
        parameters: [
            new OA\Parameter(name: '@partial', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: '@transform', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api create view'),
        ]
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $service = $this->service ?? $this->get($this->serviceClass);

        if (FixJSON::getJSONType($request->getContent()) === false) {
            return $this->warning('Invalid JSON', 400, '', 400);
        }

        // External content
        $contents = json_decode($request->getContent(), true) ? : [];

        // Partial create
        $partial = $request->query->getBoolean('@partial', false);

        // Transformer
        $transformer = $request->query->get('@transform');

        // Check input type
        $inputType = FixJSON::getJSONType($request->getContent());
        $response = [];
        if($inputType === self::$TYPE_OBJECT) {
            // Single input
            $contents = [$contents];
        }
        elseif($inputType === self::$TYPE_ARRAY) {
            // Multiple input
            // Do nothing ...
            $contents = (array) $contents;
        }
        else {
            $contents = [];
        }

        $processItem = function ($item) use ($service, $inputType, $transformer, &$response) {
            // Copy item
            $content = $item;

            // Create entity
            $entity = $service->new();

            // properties process.
            if(
                property_exists($this, 'requiredCreateProperties') ||
                property_exists($this, 'acceptedCreateProperties')
            ) {
                $data = [];

                if(property_exists($this, 'requiredCreateProperties')) {
                    foreach ($this->requiredCreateProperties as $property) {
                        if (!array_key_exists($property, $content))
                            throw new ValidatorException(ucfirst($property) . " is required");
                        $data[$property] = $content[$property];
                    }
                }

                if(property_exists($this, 'acceptedCreateProperties')) {
                    foreach ($this->acceptedCreateProperties as $property) {
                        if(array_key_exists($property, $content)) {
                            $data[$property] = $content[$property];
                        }
                    }
                }

                $content = $data;
            }

            // process content
            $content = array_merge($content, $this->defaultCreateValues());

            if($transformer) {
                $data = json_decode($transformer, true);
                $content = $this->transformContent($content, $data, $entity);
            }
            $content = $this->processCreateContent($content, $entity);

            // process entity
            $entity = $this->processEntity($content, $entity);

            if ($entity = $service->update($entity, $content)) {
                if($inputType === self::$TYPE_OBJECT) {
                    $response = $this->afterCreated($entity);
                }
                elseif($inputType === self::$TYPE_ARRAY) {
                    $response[] = $this->afterCreated($entity);
                }
                else {
                    throw new ValidatorException();
                }
            }
        };

        $transactional = !$partial && !empty($contents);

        try {
            if ($transactional) {
                $service->wrapInTransaction(function ($em) use ($contents, $processItem) {
                    foreach ($contents as $item) {
                        $processItem($item);
                    }
                });
            } else {
                foreach ($contents as $item) {
                    $processItem($item);
                }
            }
        }
        catch (ValidatorException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
        catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 404, '', 404);
        }
        catch (\Exception $exception) {
            return $this->warning($exception->getMessage() ?: 'Create failed', 500, '', 500);
        }

        return $this->success($response, 'SUCCESS', 201);
    }
}
