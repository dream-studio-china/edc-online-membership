<?php
declare(strict_types=1);

namespace App\Core\Service\Concern;

use App\Core\Utils\Inflect;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Exception\ValidatorException;

trait BaseServiceMutationTrait
{
    /**
     * @return mixed
     */
    public function new()
    {
        $ref = new \ReflectionClass($this->entityClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            return $ref->newInstance();
        }

        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * @param $object
     * @param array|null $data
     * @throws \ReflectionException
     */
    public function updateWithoutListener($object, array $data)
    {
        if (empty($object)) {
            $this->logger->error('Object error, original data: '. json_encode($data));
            throw new ValidatorException('Update object cannot be null');
        }
        else {
            $object = $object->getId() ? $this->get($object->getId()) : $object;
        }

        if (!empty($data)) {
            $this->wrapInTransaction(function ($em) use ($object, $data) {
                $qb = $em->createQueryBuilder()
                    ->update(get_class($object), 'entity')
                    ->where('entity = :entity')
                    ->setParameter('entity', $object)
                ;

                foreach ($data as $key => $val) {
                    $qb->set("entity.$key", ":$key")
                        ->setParameter($key, $val);
                }

                $qb->getQuery()->execute();

                if ($object->getId()) {
                    $em->refresh($object);
                }
            });
        }
        else {
            throw new ValidatorException('Data cannot be empty');
        }

        return $object->getId() ? $this->get($object->getId()) : $object;
    }

    /**
     * @param $object
     * @param array|null $data
     * @return bool
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \ReflectionException
     */
    public function update($object, array $data = null, bool $noFlush = false)
    {
        if (empty($object)) {
            $this->logger->error('Object error, original data: '. json_encode($data));
            throw new ValidatorException('Update object cannot be null');
        }
        else {
            $object = $object->getId() ? $this->get($object->getId()) : $object;
        }

        if (!empty($data)) {
            $serializer = $this->getSerializer();

            try {
                $reflect = new \ReflectionClass(get_class($object));

                foreach ($data as $key => $val) {
                    if (!$reflect->hasProperty($key)) {
                        continue;
                    }
                    $property = $reflect->getProperty($key);
                    $annotations = $this->getPropertyMetadata($property);
                    foreach ($annotations as $annotation) {
                        if (
                            $annotation instanceof ManyToOne ||
                            $annotation instanceof OneToOne
                        ) {
                            $dataClass = $annotation->targetEntity;
                            $rep = $this->em->getRepository($dataClass);

                            $entity = null;
                            if ($val && empty($entity = $rep->find($val))) {
                                throw new NotFoundHttpException("The entity of key[$key] is not found");
                            } else {
                                $setter = 'set' . ucfirst($key);
                                $object->$setter($entity);

                                unset($data[$key]);
                            }
                            break;
                        }
                        elseif(
                            $annotation instanceof ManyToMany ||
                            $annotation instanceof OneToMany
                        ) {
                            $dataClass = $annotation->targetEntity;
                            $rep = $this->em->getRepository($dataClass);

                            $ucfirst = ucfirst($key);
                            $getter = "get$ucfirst";
                            $entities = $object->$getter() ?? new ArrayCollection();
                            $entitiesIds = $entities->map(function ($entity) {
                                return $entity->getId();
                            })->toArray();

                            $removes = array_values(array_diff($entitiesIds, $val));
                            $adds = array_values(array_diff($val, $entitiesIds));

                            $singularize = ucfirst(Inflect::singularize($key));
                            $adder = "add$singularize";
                            $remover = "remove$singularize";

                            foreach ($removes as $remove) {
                                $entity = $rep->find($remove);
                                $object->$remover($entity);
                            }
                            foreach ($adds as $add) {
                                if (empty($entity = $rep->find($add))) {
                                    throw new NotFoundHttpException("The entity of key[$key] is not found");
                                } else {
                                    $object->$adder($entity);
                                }
                            }

                            unset($data[$key]);
                        }
                        else {
                            if ($this->isDateLikeMapping($annotation, $property)) {
                                $setter = 'set' . ucfirst($key);

                                if($val instanceof \DateTimeInterface) {
                                    $object->$setter($val);
                                }
                                else {
                                    $object->$setter(new \DateTime((string) $val));
                                }

                                unset($data[$key]);
                            }
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                $this->logger->error('Save entity error: '.$e->getMessage());
                return false;
            } catch (\Exception $e) {
                $this->logger->error('Object error, original data: '. json_encode($data));
                throw $e;
            }

            if ($serializer === null) {
                throw new \RuntimeException('Serializer service is not available. Ensure the Symfony serializer is registered and that ServiceLocator provides it.');
            }

            $serializer->deserialize(
                json_encode($data),
                get_class($object),
                'json',
                [
                    'object_to_populate' => $object
                ]
            );
        }

        $validator = $this->getValidator();
        $errors = $validator ? $validator->validate($object) : [];
        if (count($errors) > 0) {
            $errorsString = (string)$errors;
            throw new ValidatorException($errorsString);
        }

        try {
            $this->em->persist($object);
            if (!$noFlush) {
                $this->em->flush();
            }
        }
        catch (UniqueConstraintViolationException $ex) {
            throw new ValidatorException('Duplication entries');
        }
        catch (\Exception $exception) {
            throw $exception;
        }

        return $object;
    }

    /**
     * @param $object
     * @return bool
     * @throws ORMException
     */
    public function remove($object): bool
    {
        $object = $this->get($object);

        $this->em->remove($object);
        try {
            $this->em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return array<int, object>
     */
    private function getPropertyMetadata(\ReflectionProperty $property): array
    {
        $metadata = [];

        foreach ($property->getAttributes() as $attribute) {
            $metadata[] = $attribute->newInstance();
        }

        if (class_exists('Doctrine\\Common\\Annotations\\AnnotationReader')) {
            /** @var object $reader */
            $reader = new \Doctrine\Common\Annotations\AnnotationReader();
            $metadata = array_merge($metadata, $reader->getPropertyAnnotations($property));
        }

        return $metadata;
    }

    private function isDateLikeMapping(object $mapping, \ReflectionProperty $property): bool
    {
        if (property_exists($mapping, 'type')) {
            /** @var mixed $type */
            $type = $mapping->type;
            if (in_array($type, ['datetime', 'date', 'time', 'datetime_immutable', 'date_immutable'], true)) {
                return true;
            }
        }

        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        $name = ltrim($type->getName(), '\\');
        return in_array($name, ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'], true);
    }
}
