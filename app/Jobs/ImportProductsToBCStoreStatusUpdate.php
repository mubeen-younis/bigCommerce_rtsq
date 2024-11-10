<?php

namespace App\Jobs;

use App\Mail\SyncProductNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CSVimportExport;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\ExportImportProducts;
use App\CustomClasses\Functions;
use Illuminate\Support\Facades\Log;

class ImportProductsToBCStoreStatusUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $request;
    public $exceptionProducts;
    public function __construct($exceptionProducts, $request)
    {
        $this->request = $request;
        $this->exceptionProducts = $exceptionProducts;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('$this->exceptionProducts :' . json_encode($this->exceptionProducts));
        $ExportImportProducts = new ExportImportProducts();
        CSVimportExport::where('id', $this->request['CSVinsertedId'])->update([
            'total_rows'=> $this->request['csv_count'],
            'error_at_rows' => json_encode($this->exceptionProducts),
            'status' => count($this->exceptionProducts) == $this->request['csv_count'] ? 3 : (empty($this->exceptionProducts) ? 1 : 2),
        ]);

        $ExportImportProducts->ImportNotifyEmail($this->request['importEmailAddress']);
        if(Functions::isEnabledLogs($this->request['store_hash'])){
            Log::info('CSV Import Poducts Email Send.');
            Log::info('ended import products process');
        }
    }
}
