<?php

namespace App\Console\Commands;

use App\Http\Controllers\HubSpotController;
use App\Models\HubSpot;
use App\Models\Subscription\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireTrials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expire:trials';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trial Expired';

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
        $trials = Subscription::where('plan_id',1)->where('status', 1)->get();
        foreach ($trials as $trial){
            if ($trial->plan_id == 1 && Carbon::now() > Carbon::parse($trial->ends_at)){
                //If Trial is expired then update expired (2) status to DB
                $trial->update([
                    'status' => 2
                ]);
                $hubSpotUser = HubSpot::select('email')->where('store_id', $trial->store_id)->first()->toArray();
                $user = [ 'email' => $hubSpotUser['email'] ];
                $status = [ 'products_lost' => true ];
                $hubSpotController = new HubSpotController();
                $hubSpotController->createUpdateHubSpotUser($trial->store_id, $user, $status);
            }
        }
        return 0;
    }
}
