<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Scout\Searchable;

class AppLog extends Model
{
    use HasFactory, Searchable;

    public $timestamps = false;


    public static function getLogs($search, $limit = 20)
    {
        return AppLog::search($search)->paginate($limit);
    }

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
