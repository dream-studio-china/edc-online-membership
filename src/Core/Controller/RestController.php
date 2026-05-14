<?php

namespace App\Core\Controller;

use App\Core\Utils\ArrayCommon;
use App\Core\Utils\FixJSON;
use App\Core\Utils\Math;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RestController extends AbstractController
{
    const UNKNOWN_ERROR = 'Api error occurred';

    private ?object $paginator = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SerializerInterface $serializer,
        private readonly TranslatorInterface $translator
    ) {}

    public function getService(): object
    {
        return $this->service;
    }

    protected function getRequestStack(): RequestStack
    {
        return $this->requestStack;
    }

    public function setPaginator(?object $paginator): void { $this->paginator = $paginator; }

    protected function getSerializer()
    {
        return $this->serializer;
    }

    protected function getTranslator()
    {
        return $this->translator;
    }


    /**
     * @param $collection
     * @return array|ArrayCollection|QueryBuilder
     */
    protected function pagination($collection)
    {
        // get current request
        $request = $this->getRequestStack()->getCurrentRequest();

        $DEFAULT_PAGE_LIMIT = 100; // PHP_INT_MAX

        if ($collection && (
                is_array($collection)
                || $collection instanceof ArrayCollection
                || $collection instanceof QueryBuilder
            )) {
            $pager = $this->paginator;
            if (!is_object($pager) || !method_exists($pager, 'paginate')) {
                return $collection;
            }

            if ($request->getMethod() === 'GET') {
                // GET
                $paginated = $pager->paginate($collection,
                    $request->query->getInt('page', 1),
                    $request->query->getInt('limit', $DEFAULT_PAGE_LIMIT));
            } else {
                $paginated = $collection;
            }
            return $paginated;
        } else {
            return $collection;
        }
    }

    /**
     * @param $entity
     * @param array $attributeSets
     */
    private function expandObjects($entity, array $attributeSets)
    {
        foreach ($attributeSets as $attributeSet) {
            $attributeChain = explode('.', $attributeSet);

            if (current($attributeChain) == '' || current($attributeChain) == 'entity') {
                array_shift($attributeChain);
            }
            $this->expandObjectToMetadata($entity, $attributeChain);
        }
    }

    /**
     * @param $entity
     * @param array $attributeChain
     * @param int $level
     */
    private function expandObjectToMetadata(&$entity, array $attributeChain, int $level = -1)
    {
        if (empty($entity) || 0 === count($attributeChain) || 0 === $level) return;

        if (method_exists($entity, $getter = 'get' . ucfirst(trim($attributeChain[0])))) {
            if ($next = $entity->$getter()) {
                foreach ($next instanceof \Traversable ? $next : [$next] as $node) {
                    // expand
                    $node->__metadata = $node;

                    // recursive
                    $copy = $attributeChain;
                    $this->expandObjectToMetadata(
                        $node,
                        array_splice($copy, 1),
                        $level - 1
                    );
                }
            }
        }
    }

    /**
     * @param $collection
     * @return array|array[]|ArrayCollection|mixed
     */
    private function requestProcess($collection)
    {
        $request = $this->getRequestStack()->getCurrentRequest();

        // Expend Object
        $expands = json_decode(
            str_replace('\'', '"',
                $request->query->get('@expands', '[]')), true);
        try {
            if (is_array($expands)) {
                if ($collection && (
                        is_array($collection)
                        || $collection instanceof ArrayCollection)
                ) {
                    foreach ($collection as $entity) {
                        $this->expandObjects($entity, $expands);
                    }
                } else {
                    $this->expandObjects($collection, $expands);
                }
            }

        } catch (\Exception $exception) {
        }

        // General display
        if ($collection && (
                is_array($collection)
                || $collection instanceof ArrayCollection)
        ) {
            $display = $request->query->get('@display', 'complex');
            $displayRequest = FixJSON::fixJSON($display);
            $display = json_decode($displayRequest) ?? $display;

            if (is_array($display)) {
                return array_map(function ($entity) use ($display) {
                    $result = [];
                    foreach ($display as $part) {
                        $part = trim($part);
                        $fields = explode('.', $part);
                        if (current($fields) == '' || current($fields) == 'entity') {
                            array_shift($fields);
                        }

                        $next = $entity;
                        foreach ($fields as $field) {
                            if(is_object($next)) {
                                $fieldGetter = 'get' . ucfirst($field);
                                $next = $next->$fieldGetter();
                            }
                            elseif (is_array($next)) {
                                $next = $next[$field] ?? null;
                            }
                        }

                        $result[$part] = $next;
                    }

                    return $result;
                }, $collection);
            } elseif (is_object($display)) {
                $display = json_decode($displayRequest, true) ?? $display;
                $result = [];

                foreach ($collection as $item) {
                    $set = [];
                    foreach ($display as $key => $value) {
                        try {
                            $expressionLanguage = new ExpressionLanguage();
                            $set[$key] = $expressionLanguage->evaluate(
                                $value, [
                                    'entity' => $item,
                                    'Math' => new Math(),
                                    'ArrayCommon' => new ArrayCommon()
                                ]
                            );
                        } catch (\Exception $e) {
                        }
                    }
                    $result[] = $set;
                }

                return $result;
            } else {
                if ($display === 'reduce') {
                    return array_map(function ($entity) {
                        return [
                            'id' => $entity->getId(),
                            '__toString' => $entity->__toString(),
                        ];
                    }, $collection);
                } else {
                    return $collection;
                }
            }
        }

        return $collection;
    }


    /**
     * @param mixed $content
     * @param string $addition_message
     * @return Response
     */
    protected function success(
        $content = '',
        string $addition_message = 'SUCCESS',
        int $status = 200
    ): Response
    {
        if ($status === 204) {
            return new Response('', 204, ['Content-Type' => 'application/json']);
        }

        $paginatedContent = $this->pagination($content);

        // If pagination did not execute the query (no paginator available) and
        // we still have a QueryBuilder, execute it to get serializable results.
        if ($paginatedContent instanceof QueryBuilder) {
            $paginatedContent = $paginatedContent->getQuery()->getResult();
        }

        if ($this->isKnpPagination($paginatedContent)) {
            /** @var \Knp\Component\Pager\Pagination\AbstractPagination $pagination */
            $pagination = $paginatedContent;
            $processedContent = $this->requestProcess($pagination->getItems());
        } else {
            $processedContent = $this->requestProcess($paginatedContent);
        }

        $response = [
            'data' => $processedContent,
            'code' => 0,
            'message' => $addition_message,
        ];
        if ($this->isKnpPagination($paginatedContent)) {
            /** @var \Knp\Component\Pager\Pagination\AbstractPagination $pagination */
            $pagination = $paginatedContent;
            $response['paginator'] = $pagination->getPaginationData();
        }
        return new Response(
            $this->getSerializer()->serialize($response, 'json'),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * @param string $error_msg
     * @param int $error_code
     * @param mixed $raw_data
     * @return Response
     */
    protected function warning(
        string $error_msg = self::UNKNOWN_ERROR,
        int $error_code = -1,
        $raw_data = '',
        int $status = 200
    ): Response
    {
        $response = [
            'code' => $error_code,
            'message' => $this->getTranslator()->trans($error_msg),
            'raw_data' => $raw_data,
        ];
        return new Response(
            $this->getSerializer()->serialize($response, 'json'),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    private function isKnpPagination($value): bool
    {
        return class_exists('Knp\\Component\\Pager\\Pagination\\AbstractPagination')
            && $value instanceof \Knp\Component\Pager\Pagination\AbstractPagination;
    }
}
