<?php

namespace App\Core\View;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Exception\ValidatorException;

trait UpdateApiViewMixin
{
    protected static $MODE_CREATE = 0;
    protected static $MODE_UPDATE = 1;

    //protected $requiredUpdateProperties = [];
    //protected $acceptedUpdateProperties = [];

    /**
     * @return array
     */
    protected function defaultValues(): array
    {
        /** Default values */
        return [];
    }

    /**
     * @param array $content
     * @param null $entity
     * @return array
     */
    protected function processContent(array $content, $entity = null): array
    {
        /** Default values */
        return $content;
    }

    /**
     * @param $entity
     * @return mixed
     */
    protected function after($entity)
    {
        /** Updated entity */
        return $entity;
    }


    /**
     * @return array
     */
    protected function defaultUpdateValues(): array
    {
        return $this->defaultValues();
    }

    /**
     * @param array $content
     * @param null $entity
     * @return array
     */
    protected function processUpdateContent(array $content, $entity = null): array
    {
        return $this->processContent($content, $entity);
    }

    /**
     * @param $entity
     * @return mixed
     */
    protected function afterUpdated($entity)
    {
        return $this->after($entity);
    }

    /**
     * @param $entity
     * @param $content
     * @param array|null $transformer
     * @param int $writeMode
     * @return mixed
     */
    private function updateSingle($entity, $content, ?array $transformer = null, int $writeMode = 1 /* MODE_UPDATE */, bool $noFlush = false)
    {
        $service = $this->service ?? $this->get($this->serviceClass);

        // Properties process.
        // FIXED: Add properties null checker for inherit
        if(
            (property_exists($this, 'requiredUpdateProperties') && $this->requiredUpdateProperties) ||
            (property_exists($this, 'acceptedUpdateProperties') && $this->acceptedUpdateProperties)
        ) {
            $data = [];

            if (property_exists($this, 'requiredUpdateProperties')) {
                foreach ($this->requiredUpdateProperties as $property) {
                    if (!array_key_exists($property, $content)) {
                        throw new ValidatorException(ucfirst($property) . " cannot be empty.");
                    }
                    $data[$property] = $content[$property];
                }
            }

            if (property_exists($this, 'acceptedUpdateProperties')) {
                foreach ($this->acceptedUpdateProperties as $property) {
                    if (array_key_exists($property, $content)) {
                        $data[$property] = $content[$property];
                    }
                }
            }


            $content = $data;
        }

        // Process content
        // TODO: May be bug occur here. Use other function instead of 'array_merge'
        $content = array_merge($content,
            $writeMode
                ? $this->defaultUpdateValues()
                : (
            method_exists($this, 'defaultCreateValues')
                ? $this->{'defaultCreateValues'}()
                : $this->defaultValues()
            )
        );

        if($transformer) {
            $content = $this->transformContent($content, $transformer, $entity);
        }
        $content = $writeMode
            ? $this->processUpdateContent($content, $entity)
            : (
            method_exists($this, 'processCreateContent')
                ? $this->{'processCreateContent'}($content, $entity)
                : $this->processContent($content, $entity)
            )
        ;

        // remove id
        unset($content['id']);

        // save
        $entity = $service->update($entity, $content, $noFlush);
        return $writeMode
            ? $this->afterUpdated($entity)
            : (
            method_exists($this, 'afterCreated')
                ? $this->{'afterCreated'}($entity)
                : $this->after($entity)
            );
    }

    /**
     * @param Request $request
     * @param null $id
     * @return array|mixed
     * @throws \Exception
     */
    private function updateRecords(Request $request, $id = null)
    {
        // No explicit service injection.
        $service = $this->service ?? $this->get($this->serviceClass);

        // External content
        $content = json_decode($request->getContent(), true) ? : [];

        // Batch mode
        // update, mixed
        $mode = $request->query->get('@mode', 'mixed');

        // Update basis
        // eg: id, name, ...
        $basis = $request->query->get('@basis');
        $basis = $basis ? array_map(function($item) { return trim($item); }, explode(',', $basis)) : [];

        // Partial create / update
        $partial = $request->query->getBoolean('@partial', false);

        // Transformer
        $transformer = $request->query->get('@transform');
        if($transformer) {
            $transformer = json_decode($transformer, true);
        }

        // Start

        if($id) {
            // Single update
            $filter = $this->mixIdToCommonFilter($id);
            $entity = $service->get($filter, false);

            if (!$entity) {
                throw new NotFoundHttpException('Entity is not found');
            }

            return $this->updateSingle($entity, $content, $transformer);
        }
        elseif(is_array($content)) {
            // Multiple update
            $response = [];

            if (!$partial) {
                $service->wrapInTransaction(function ($em) use ($content, $service, $basis, $mode, $transformer, &$response) {
                    foreach ($content as $item) {
                        $data = [];
                        foreach ($basis as $basisItem) {
                            $data[$basisItem] = $item[$basisItem];
                        }
                        $filter = $this->mixToCommonFilter($data);
                        $entity = $service->get($filter, false);
                        $writeMode = self::$MODE_UPDATE;
                        if(empty($entity) || empty($basis)) {
                            if($mode == 'mixed') {
                                $writeMode = self::$MODE_CREATE;
                                $entity = $service->new();
                            }
                            else continue;
                        }
                        $response[] = $this->updateSingle($entity, $item, $transformer, $writeMode, true);
                    }
                });
            } else {
                foreach ($content as $item) {
                    try {
                        $data = [];
                        foreach ($basis as $basisItem) {
                            $data[$basisItem] = $item[$basisItem];
                        }
                        $filter = $this->mixToCommonFilter($data);
                        $entity = $service->get($filter, false);
                        $writeMode = self::$MODE_UPDATE;
                        if(empty($entity) || empty($basis)) {
                            if($mode == 'mixed') {
                                $writeMode = self::$MODE_CREATE;
                                $entity = $service->new();
                            }
                            else continue;
                        }
                        $response[] = $this->updateSingle($entity, $item, $transformer, $writeMode, false);
                    } catch (\Exception) {
                        // Partial mode: skip failed items
                    }
                }
            }
        }
        else {
            throw new ValidatorException('Content type error.');
        }

        return $response ?? null;
    }

    #[OA\Post(
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Update'],
        parameters: [
            new OA\Parameter(name: '@mode', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@basis', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@partial', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: '@transform', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api multiple update view'),
        ]
    )]
    #[Route('/batch-update', name: 'batch-update', methods: ['POST'])]
    public function batchUpdateAction(Request $request): Response
    {
        $response = $this->updateRecords($request);

        if($response === null) {
            throw new ValidatorException('Batch update error');
        }
        else {
            return $this->success($response);
        }
    }

    #[OA\Put(
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Update'],
        parameters: [
            new OA\Parameter(name: '@transform', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api update view'),
        ]
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
    public function updateAction(Request $request, $id): Response
    {
        try {
            $response = $this->updateRecords($request, $id);
        } catch (ValidatorException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 404, '', 404);
        } catch (\Exception $exception) {
            return $this->warning($exception->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }

        if ($response) {
            return $this->success($response);
        } else {
            return $this->warning();
        }
    }
}
