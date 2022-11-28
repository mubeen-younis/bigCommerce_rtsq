<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminalLocation extends Model
{
    use HasFactory;

    public static function getTerminalLocations()
    {
        $terminalLocations = optional(self::all())->toArray() ?? [];

        return $terminalLocations;
    }
}
