<?php

namespace App\Core\Serializer\Normalizer;

class CircularReferenceHandler
{
    public static function handle($object)
    {
        if (method_exists($object, 'getId')) {
            return $object->getId();
        }

        throw new \Exception('Every entity should have `getId` method');
    }
}
