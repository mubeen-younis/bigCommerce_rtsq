<?php

namespace App\Console\Commands;

use App\Http\Controllers\OrderDetailCronController;
use Illuminate\Console\Command;

class OrderDetailCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orderDetail:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs to update the order webhook details';

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
        return (new OrderDetailCronController())->createOrderDetailData();
    }
}
