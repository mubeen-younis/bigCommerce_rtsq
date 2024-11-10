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
use App\Models\CSVimportExport;

class ImportProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $chunk, $request, $headerRow, $csvChunkCount, $emailNotify;
    public function __construct($chunk, $request, $headerRow)
    {
        $this->chunk = $chunk;
        $this->request = $request;
        $this->headerRow = $headerRow;
        $this->csvChunkCount = $request['csv_chunk_count'];
        $this->emailNotify = $request['importEmailAddress'] ?? '';
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
        $exceptionProducts = $ExportImportProducts->importProductCsvJob($this->chunk, $this->request, $this->headerRow);

        // Increment the counter and reset expiration to avoid early cache expiry
        $processedChunks = Cache::increment('chunks_processed');
        Cache::put('chunks_processed', $processedChunks, now()->addHours(2));

         // Check if all chunks are complete
        if ($processedChunks >= $this->csvChunkCount) {

            CSVimportExport::where('id', $this->request['CSVinsertedId'])->update([
                'total_rows'=> $this->request['csv_count'],
               'error_at_rows' => json_encode($exceptionProducts),
                'status' => count($exceptionProducts) == $this->request['csv_count'] ? 3 : (empty($exceptionProducts) ? 1 : 2),
            ]);

            $ExportImportProducts->ImportNotifyEmail($this->emailNotify);
            if(Functions::isEnabledLogs($this->request['store_hash'])){
                Log::info('CSV Import Poducts Email Send.');
                Log::info('ended import products process');
            } 
            // Clear the cache counter to reset for next use
            Cache::forget('chunks_processed');
        }
    }
}
