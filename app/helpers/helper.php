<?php

namespace App\Helpers;


class Helper
{
    public static function jsonValidator($data = NULL)
    {
        json_decode($data);
        return (json_last_error() === JSON_ERROR_NONE);
    }

}
