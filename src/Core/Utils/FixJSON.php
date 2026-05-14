<?php

namespace App\Core\Utils;

class FixJSON
{
    /**
     * @param $json
     * @return array|string|string[]|null
     */
    public static function fixJSON($json)
    {
        $regex = <<<'REGEX'
~
    "[^"\\]*(?:\\.|[^"\\]*)*"
    (*SKIP)(*F)
  | '([^'\\]*(?:\\.|[^'\\]*)*)'
~x
REGEX;

        return preg_replace_callback($regex, function($matches) {
            return '"' . preg_replace('~\\\\.(*SKIP)(*F)|"~', '\\"', $matches[1]) . '"';
        }, $json);
    }

    /**
     * @param $json
     * @return false|string
     */
    public static function getJSONType($json)
    {
        $obj = json_decode($json);

        // Not valid JSON
        if ($obj === null) return false;
        $json = ltrim($json);

        // Object
        if (strpos($json, '{') === 0) return 'object';

        // Array
        if (strpos($json, '[') === 0) return 'array';

        return false;
    }
}