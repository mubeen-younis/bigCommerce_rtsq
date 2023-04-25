<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AppLog extends Model
{
    use HasFactory;

    public $timestamps = false;


    public static function getLogsDB($searchTerm, $limit = 50, $sortOrder = 'desc')
    {
        $appLogs = AppLog::where('formatted', 'LIKE', "%{$searchTerm}%")
            ->orderBy('id', $sortOrder)
            ->paginate($limit);
        return $appLogs->appends(['search' => $searchTerm, 'page_size' => $limit, 'sort_order' => $sortOrder]);
    }

    public function toSearchableArray()
    {
        return Arr::only($this->toArray(), ['message', 'context']);
    }
}
