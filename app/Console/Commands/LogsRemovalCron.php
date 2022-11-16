<?php

namespace App\Console\Commands;

use App\Http\Controllers\LogsRemovelController;
use Illuminate\Console\Command;

class LogsRemovalCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'removeLogs:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        LogsRemovelController::removeLogs();
        return 'command executed';
    }
}
