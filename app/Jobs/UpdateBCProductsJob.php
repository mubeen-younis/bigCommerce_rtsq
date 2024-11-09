<?php

namespace App\Jobs;

use App\Http\Controllers\ExportImportProducts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateBCProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $batches, $request;
    public function __construct($batches, $request)
    {
        $this->batches = $batches;
        $this->request = $request;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ExportImportProducts = new ExportImportProducts();
        $ExportImportProducts->importProductCsvJob2($this->batches, $this->request);
    }
}
