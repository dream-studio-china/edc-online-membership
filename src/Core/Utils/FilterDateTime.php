<?php

namespace App\Core\Utils;

class FilterDateTime
{
    public function get($time = 'now', ?\DateTimeZone $timezone = null)
    {
        return new \DateTime($time, $timezone);
    }
}
