<?php

namespace App\Core\Utils;

class StringCase
{
    /**
     * @param $string
     * @param bool $capitalizeFirstCharacter
     * @return string
     */
    public static function dashesToCamelCase($string, bool $capitalizeFirstCharacter = false): string
    {
        $str = str_replace('-', '', ucwords($string, '-'));

        if (!$capitalizeFirstCharacter) {
            $str = lcfirst($str);
        }

        return $str;
    }
}
