<?php

namespace App\Core\Utils;

class Math
{
    // user defined
    public static function random($min = 0, $max = 1)
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    public static function locationDistance($longitude1, $latitude1, $longitude2, $latitude2)
    {
        $radian = function ($d) {
            return $d * 3.1415926535898 / 180.0;
        };
        $radLat1 = $radian ($latitude1);
        $radLat2 = $radian ($latitude2);
        $a = $radian ($latitude1) - $radian ($latitude2);
        $b = $radian ($longitude1) - $radian ($longitude2);

        $s = 2 * asin(sqrt(pow(sin($a / 2), 2) + cos($radLat1) *
                cos($radLat2) * pow(sin($b / 2), 2)));
        $s = $s * 6378.137;
        $s = round($s * 10000) / 10000;

        return $s;
    }

    // constrain
    const M_E = 2.7182818284590452354;
    const M_EULER = 0.57721566490153286061;
    const M_LNPI = 1.14472988584940017414;
    const M_LN2 = 0.69314718055994530942;
    const M_LN10 = 2.30258509299404568402;
    const M_LOG2E = 1.4426950408889634074;
    const M_LOG10E = 0.43429448190325182765;
    const M_PI = 3.14159265358979323846;
    const M_PI_2 = 1.57079632679489661923;
    const M_PI_4 = 0.78539816339744830962;
    const M_1_PI = 0.31830988618379067154;
    const M_2_PI = 0.63661977236758134308;
    const M_SQRTPI = 1.77245385090551602729;
    const M_2_SQRTPI = 1.12837916709551257390;
    const M_SQRT1_2 = 0.70710678118654752440;
    const M_SQRT2 = 1.41421356237309504880;
    const M_SQRT3 = 1.73205080756887729352;

    // common
    public static function abs($x) { return abs($x); }
    public static function acos($x) { return acos($x); }
    public static function acosh($x) { return acosh($x); }
    public static function asin($x) { return asin($x); }
    public static function asinh($x) { return asinh($x); }
    public static function atan($x) { return atan($x); }
    public static function atan2($y, $x) { return atan2($y, $x); }
    public static function atanh($x) { return atanh($x); }
    public static function base_convert($number, $frombase, $tobase) { return base_convert($number,$frombase,$tobase); }
    public static function bindec($x) { return bindec($x); }
    public static function ceil($x) { return ceil($x); }
    public static function cos($x) { return cos($x); }
    public static function cosh($x) { return cosh($x); }
    public static function decbin($x) { return decbin($x); }
    public static function dechex($x) { return dechex($x); }
    public static function decoct($x) { return decoct($x); }
    public static function deg2rad($x) { return deg2rad($x); }
    public static function exp($x) { return exp($x); }
    public static function expm1($x) { return expm1($x); }
    public static function floor($x) { return floor($x); }
    public static function fmod($x, $y) { return fmod($x, $y); }
    public static function getrandmax() { return getrandmax(); }
    public static function hexdec($x) { return hexdec($x); }
    public static function hypot($x, $y) { return hypot($x, $y); }
    public static function is_finite($x) { return is_finite($x); }
    public static function is_infinite($x) { return is_infinite($x); }
    public static function is_nan($x) { return is_nan($x); }
    public static function lcg_value() { return lcg_value(); }
    public static function log($x) { return log($x); }
    public static function log10($x) { return log10($x); }
    public static function log1p($x) { return log1p($x); }
    public static function max($value, ...$values) { return max($value, ...$values); }
    public static function min($value, ...$values) { return min($value, ...$values); }
    public static function mt_getrandmax() { return mt_getrandmax(); }
    public static function mt_rand($x) { return mt_rand($x); }
    public static function mt_srand(?int $seed = null, int $mode = MT_RAND_MT19937): void { mt_srand($seed, $mode); }
    public static function octdec($x) { return octdec($x); }
    public static function pi() { return pi(); }
    public static function pow($x, $y) { return pow($x, $y); }
    public static function rad2deg($x) { return rad2deg($x); }
    public static function rand($x) { return rand($x); }
    public static function round($num, int $precision = 0, int $mode = PHP_ROUND_HALF_UP): float
    {
        return match ($mode) {
            PHP_ROUND_HALF_UP, PHP_ROUND_HALF_DOWN, PHP_ROUND_HALF_EVEN, PHP_ROUND_HALF_ODD => round($num, $precision, $mode),
            default => throw new \InvalidArgumentException('Invalid rounding mode.'),
        };
    }
    public static function sin($x) { return sin($x); }
    public static function sinh($x) { return sinh($x); }
    public static function sqrt($x) { return sqrt($x); }
    public static function srand(?int $seed = null, int $mode = MT_RAND_MT19937): void { srand($seed, $mode); }
    public static function tan($x) { return tan($x); }
    public static function tanh($x) { return tanh($x); }
}
