<?php

namespace App\Jobs;

use App\Http\Controllers\ExportImportProducts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ImportProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $chunk, $request, $headerRow;
    public function __construct($chunk, $request, $headerRow)
    {
        $this->chunk = $chunk;
        $this->request = $request;
        $this->headerRow = $headerRow;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!Cache::has('chunks_processed')) {
            Cache::put('chunks_processed', 0, now()->addHours(2));
        }
        $ExportImportProducts = new ExportImportProducts();
        $ExportImportProducts->importProductCsvJob($this->chunk, $this->request, $this->headerRow);
    }
}
