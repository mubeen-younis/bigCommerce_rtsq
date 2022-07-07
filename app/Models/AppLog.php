<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AppLog extends Model
{
    use HasFactory;

    public $timestamps = false;


    public static function getLogsDB($searchTerm, $limit = 20)
    {
        return AppLog::where('message', 'LIKE', "%{$searchTerm}%")
            ->orWhere('context', 'LIKE', "%{$searchTerm}%")->paginate($limit);
    }

    public function toSearchableArray()
    {
        return Arr::only($this->toArray(), ['message', 'context']);
    }
}
