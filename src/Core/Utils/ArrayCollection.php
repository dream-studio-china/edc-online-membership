<?php

namespace App\Core\Utils;

use Curl\Curl;

class ArrayCollection {
    /**
     * @param array $array
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public static function init($array): \Doctrine\Common\Collections\ArrayCollection
    {
        return new \Doctrine\Common\Collections\ArrayCollection(is_array($array) ? $array : []);
    }

    /**
     * @param string $json
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public static function fromJsonString(string $json): \Doctrine\Common\Collections\ArrayCollection
    {
        return new \Doctrine\Common\Collections\ArrayCollection(json_decode($json, true));
    }

    /**
     * @param $array
     * @param $key
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public static function map($array, $key): \Doctrine\Common\Collections\ArrayCollection
    {
        if(!($array instanceof \Doctrine\Common\Collections\ArrayCollection)) {
            $array = new \Doctrine\Common\Collections\ArrayCollection($array);
        }

        return $array->map(function ($item) use ($key) {
            $getter = 'get' . ucfirst($key);
            return $item->$getter();
        });
    }
}