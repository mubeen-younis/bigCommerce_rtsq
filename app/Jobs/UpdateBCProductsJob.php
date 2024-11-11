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
    public $batches, $request, $exceptionProducts;
    public function __construct($batches, $request, $exceptionProducts)
    {
        $this->batches = $batches;
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
        $ExportImportProducts = new ExportImportProducts();
        $ExportImportProducts->importBCProductCsvJob($this->batches, $this->request, $this->exceptionProducts);
    }
}
