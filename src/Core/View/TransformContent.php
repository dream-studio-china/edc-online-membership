<?php

namespace App\Core\View;

use App\Core\Utils\ArrayCommon;
use App\Core\Utils\Math;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * @example
 *   POST /api/contents
 *   data: {"title": "Title here", "category": "Test"}
 *   parameter: {"category": "Service.get({'name': ':value'}).getId()"}
 */
trait TransformContent
{
    /**
     * @param array $content
     * @param array $transformer
     * @param null $entity
     * @return array
     */
    protected function transformContent(array $content, array $transformer, $entity): array
    {
        $expressionLanguage = new ExpressionLanguage();

        foreach ($transformer as $field => $expression) {

            if(array_key_exists($field, $content)) {
                $value = $content[$field];
            }
            else {
                continue;
            }

            $docReader = new AnnotationReader();
            $reflect = new \ReflectionClass(get_class($entity));

            if (!$reflect->hasProperty($field)) {
                throw new ValidatorException('Invalid content field');
            }
            $annotations = $docReader->getPropertyAnnotations($reflect->getProperty($field));
            $service = null;

            foreach ($annotations as $annotation) {
                if (
                    $annotation instanceof ManyToOne ||
                    $annotation instanceof OneToOne ||
                    $annotation instanceof ManyToMany ||
                    $annotation instanceof OneToMany
                ) {
                    $dataClass = $annotation->targetEntity;

                    // Not accuracy
                    $serviceClass = str_replace( 'Entity', 'Service', $dataClass) . 'Service';
                    $service = $this->get($serviceClass);
                }
            }


            $expression = str_replace( ':value', $value, $expression);
            try {
                $content[$field] = $expressionLanguage->evaluate(
                    $expression, [
                        'Service' => $service,
                        'entity' => $entity,
                        'Math' => new Math(),
                        'ArrayCommon' => new ArrayCommon()
                    ]
                );
            }
            catch (\Exception $exception) {}
        }

        return $content;
    }

}
