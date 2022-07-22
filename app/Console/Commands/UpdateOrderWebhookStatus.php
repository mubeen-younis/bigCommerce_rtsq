<?php

namespace App\Console\Commands;

use App\CustomClasses\Functions;
use App\Http\Controllers\WebHooksController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateOrderWebhookStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orderWebhook:cron';

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
        WebHooksController::updateOrderWebhookStatus();
    }
}
