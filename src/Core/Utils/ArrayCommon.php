<?php

namespace App\Core\Utils;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class ArrayCommon
{
    /**
     * @param $needle
     * @param array $array
     * @return bool
     */
    public static function in_array($needle, array $array): bool
    {
        return in_array($needle, $array);
    }

    /**
     * @param array $array
     * @return int
     */
    public static function count(array $array): int
    {
        return count($array);
    }

    /**
     * @param mixed ...$arrays
     * @return array
     */
    public static function merge(array ...$arrays): array
    {
        return array_merge($arrays);
    }

    /**
     * @param array $array
     * @param $arrayPush
     * @return array
     */
    public static function push(array $array, $arrayPush): array
    {
        $array[] = $arrayPush;
        return $array;
    }

    /**
     * @param $key
     * @param array $array
     * @return bool
     */
    public static function key_exist($key, array $array): bool
    {
        return array_key_exists($key, $array);
    }

    /**
     * @param $array
     * @param $expression
     * @param array $external
     * @return array
     */
    public static function filter($array, $expression, array $external = []): array
    {
        return array_filter($array, function ($value) use ($expression, $external) {
            $expressionLanguage = new ExpressionLanguage();
            return $expressionLanguage->evaluate(
                $expression, array_merge(['value' => $value], $external)
            );
        });
    }

    /**
     * @param $array
     * @param $expression
     * @param array $external
     * @return array
     */
    public static function map($array, $expression, array $external = []): array
    {
        return array_map(function($item) use ($expression, $external) {
            $expressionLanguage = new ExpressionLanguage();
            return $expressionLanguage->evaluate(
                $expression, array_merge(['item' => $item], $external)
            );
        }, $array);
    }

    /**
     * @param $array
     * @param $expression
     * @param null $initial
     * @param array $external
     * @return mixed|null
     */
    public static function reduce($array, $expression, $initial = null, array $external = [])
    {
        return array_reduce($array, function($carry, $item) use ($expression, $external) {
            $expressionLanguage = new ExpressionLanguage();
            return $expressionLanguage->evaluate(
                $expression, array_merge(['carry' => $carry, 'item' => $item], $external)
            );
        }, $initial);
    }
}