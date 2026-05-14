<?php

namespace App\Core\Controller;

use App\Core\Utils\ArrayCommon;
use App\Core\Utils\FixJSON;
use App\Core\Utils\Math;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

class RestController extends AbstractController
{
    const UNKNOWN_ERROR = 'Api error occurred';

    // Allow nullable properties so child controllers may omit calling parent::__construct()
    private ?RequestStack $requestStack = null;
    private ?SerializerInterface $serializer = null;
    private ?TranslatorInterface $translator = null;

    /**
     * Constructor accepts optional dependencies so subclasses can call parent::__construct()
     * with or without arguments. If dependencies are not provided, getters will fetch them
     * lazily from the container (AbstractController::$container) so child controllers don't
     * need to explicitly declare or forward those arguments.
     */
    public function __construct(
        ?RequestStack $requestStack = null,
        ?SerializerInterface $serializer = null,
        ?TranslatorInterface $translator = null
    ) {
        $this->requestStack = $requestStack;
        $this->serializer = $serializer;
        $this->translator = $translator;
    }
    /** @noinspection PhpPossiblePolymorphicInvocationInspection */
    public function getService(): object
    {
        return $this->service;
    }

    #[Required]
    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    #[Required]
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    protected function getRequestStack(): RequestStack
    {
        if ($this->requestStack instanceof RequestStack) {
            return $this->requestStack;
        }
        throw new \RuntimeException('RequestStack is not available in RestController');
    }

    protected function getSerializer()
    {
        if ($this->serializer instanceof SerializerInterface) {
            return $this->serializer;
        }

        throw new \RuntimeException('Serializer is not available in RestController');
    }

    protected function getTranslator()
    {
        if ($this->translator instanceof TranslatorInterface) {
            return $this->translator;
        }

        throw new \RuntimeException('Translator is not available in RestController');
    }


    /**
     * @param mixed $collection
     * @return array{items:mixed,paginator:?array}
     */
    protected function pagination($collection)
    {
        // get current request
        $request = $this->getRequestStack()->getCurrentRequest();
        if ($request === null || $request->getMethod() !== 'GET') {
            return ['items' => $collection, 'paginator' => null];
        }

        $DEFAULT_PAGE_LIMIT = 100; // PHP_INT_MAX
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', $DEFAULT_PAGE_LIMIT));
        $offset = ($page - 1) * $limit;

        $buildMeta = static function (int $total, int $page, int $limit): array {
            $pages = max(1, (int) ceil($total / $limit));
            return [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pages,
                'has_previous' => $page > 1,
                'has_next' => $page < $pages,
            ];
        };

        if ($collection instanceof QueryBuilder) {
            $query = $collection->getQuery();
            $total = count(new DoctrinePaginator($query, true));
            $collection->setFirstResult($offset)->setMaxResults($limit);
            return [
                'items' => $collection->getQuery()->getResult(),
                'paginator' => $buildMeta($total, $page, $limit),
            ];
        }

        if (is_array($collection) || $collection instanceof ArrayCollection) {
            $items = $collection instanceof ArrayCollection ? $collection->toArray() : $collection;
            $total = count($items);
            return [
                'items' => array_slice($items, $offset, $limit),
                'paginator' => $buildMeta($total, $page, $limit),
            ];
        }

        return ['items' => $collection, 'paginator' => null];
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
     * @param int $status
     * @return Response
     * @throws ExceptionInterface
     */
    protected function success(
        mixed $content = '',
        string $addition_message = 'SUCCESS',
        int $status = 200
    ): Response
    {
        if ($status === 204) {
            return new Response('', 204, ['Content-Type' => 'application/json']);
        }

        $paginated = $this->pagination($content);
        $processedContent = $this->requestProcess($paginated['items']);

        $response = [
            'data' => $processedContent,
            'code' => 0,
            'message' => $addition_message,
        ];
        if (is_array($paginated['paginator'])) {
            $response['paginator'] = $paginated['paginator'];
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
     * @param int $status
     * @return Response
     * @throws ExceptionInterface
     */
    protected function warning(
        string $error_msg = self::UNKNOWN_ERROR,
        int $error_code = -1,
        mixed $raw_data = '',
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

}
