<?php

declare(strict_types=1);

namespace App\Core\Service;

use Doctrine\ORM\QueryBuilder;

interface BaseServiceInterface
{
    /**
     * Find entity by id or criteria or execute a QueryBuilder to return single result.
     * @param mixed $object
     * @param bool $directly
     * @return object|null
     */
    public function get($object, bool $directly = false);

    /**
     * List entities or return a QueryBuilder. When $disableRequest is false, the service may consult current Request.
     * @param mixed|null $object
     * @param mixed|null $order
     * @param bool $disableRequest
     * @return mixed  array|QueryBuilder|ArrayCollection
     */
    public function list($object = null, $order = null, bool $disableRequest = true);

    /**
     * Create a new instance of the entity managed by the service.
     * @return object
     */
    public function new();

    /**
     * Update an entity with provided data (may persist and flush).
     * @param mixed $object
     * @param array|null $data
     * @return mixed
     */
    public function update($object, array $data = null);

    /**
     * Update without triggering listeners (bulk update path).
     * @param mixed $object
     * @param array $data
     * @return mixed
     */
    public function updateWithoutListener($object, array $data);

    /**
     * Remove the given entity.
     * @param mixed $object
     * @return bool
     */
    public function remove($object): bool;
}

