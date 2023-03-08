<?php

namespace App\Console;

use App\Console\Commands\ExpireTrials;
use App\Console\Commands\OrderDetailCron;
use App\Console\Commands\UpdateOrderWebhookStatus;
use App\Console\Commands\WsGraphCron;
use App\Console\Commands\LogsRemovalCron;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        ExpireTrials::class,
        WsGraphCron::class,
        UpdateOrderWebhookStatus::class,
        LogsRemovalCron::class,
        OrderDetailCron::class
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('expire:trials')->daily();
        $schedule->command('wsgraph:cron')->daily();
        $schedule->command('orderWebhook:cron')->daily();
        $schedule->command('removeLogs:cron')->hourly();
        $schedule->command('orderDetail:cron')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
