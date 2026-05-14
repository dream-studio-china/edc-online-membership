<?php

namespace App\Core\Serializer\Callbacks;

class ObjectCallback
{
    public static function handle($object)
    {
        if (is_object($object) && method_exists($object, 'getId')) {
            return $object->getId();
        }

        return null;
    }
}
