<?php

namespace App\Logging;
// use Illuminate\Log\Logger;
use App\Models\AppLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class MySQLLoggingHandler extends AbstractProcessingHandler
{
    /**
     *
     * Reference:
     * https://github.com/markhilton/monolog-mysql/blob/master/src/Logger/Monolog/Handler/MysqlHandler.php
     */
    public function __construct($level = Logger::DEBUG, $bubble = true)
    {
        $this->table = 'app_logs';
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        $appLog = new AppLog();
        $appLog->message = $record['message'];
        $appLog->context = json_encode($record['context']);
        $appLog->level = $record['level'];
        $appLog->level_name = $record['level_name'];
        $appLog->channel = $record['channel'];
        $appLog->record_datetime = $record['datetime']->format('Y-m-d H:i:s');
        $appLog->extra = json_encode($record['extra']);
        $appLog->formatted = $record['formatted'];
        $appLog->remote_addr = $_SERVER['REMOTE_ADDR'] ?? "no";
        $appLog->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? "null";
        $appLog->created_at = date("Y-m-d H:i:s");
        $appLog->save();
    }
}
