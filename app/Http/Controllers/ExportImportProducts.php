<?php

namespace App\Http\Controllers;

use App\Jobs\ImportProducts as ImportProductsJob;
use App\Jobs\ImportProductsNotification;
use App\Models\ProductSetting;
use App\Models\ExportProducts as ExportProductsModel;
use App\Mail\ExportProducts as ExportProductsEmail;
use App\Mail\ImportProducts as ImportProductsEmail;
use App\Models\Store;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\URL;
use ZipArchive;
use Illuminate\Filesystem\Filesystem;
use App\CurlRequest;
use Carbon\Carbon;

class ExportImportProducts extends Controller
{
    public $curlRequest;

    public function __construct(){
        $this->curlRequest = new CurlRequest();
    }

    public function exportProductsTemplate(Request $request)
    {
        if (isset($request['onlyResponse']) && $request['onlyResponse'] === true) {
           return response()->json(['error' => false,
                'data' => [],
                'message' => 'The import CSV template will be emailed to ' . $request['email'],
            ], 200);
        } else {
            $this->createExportData($request);
        }
    }

    public function createExportData($request){
        $productsChunk = ProductSetting::where('store_id', $request['store_id']);
        $comma = ",";
        if(!isset($request['rerunrequest'])) {
            $fileName = '/export_files/' . $request['store_hash'] . '/' . time();
            $request['folderName'] = public_path() . $fileName;
            $hash = md5($request['store_id'] . time());
            $this->makeDirectory($request['folderName'], $mode = 0777, true, true);
            $request['exportProductsId'] = ExportProductsModel::insertGetId(['store_id' => $request['store_id'], 'foldername' => $fileName.'.zip', 'hash' => $hash, 'request_time' => time(), 'email' => $request['email'], 'status' => 0]);
        }
        $folderName = $request['folderName'];
        $folderNamePath = [];
        try {
            $productsChunk->chunk(2500, function ($products, $chunkCount = 0) use ($comma, $folderName) {
                $fileName = $chunkCount++ . '-export.csv';
                $filename = $folderName . '/' . $fileName;
                $folderNamePath[] = $filename;
                $fp = fopen($filename, "w");
                if (true) {
                    $line = 'Product Id, Variant Id, Product Name, Product SKU, Weight (lbs), Length (in), Width (in), Height (in),Freight Enabled, Freight Class, Hazardous Enabled, Insurance, Dropship Enabled, Dropship Location, Parcel Enabled';
                    $line .= "\n";
                    fputs($fp, $line);
                }
                foreach ($products as $key => $product) {
                    $line = 'P'.$product->source_product_id;
                    $line .= $comma . 'V'.$product->variant_id;
                    $line .= $comma . $product->name;
                    $line .= $comma . $product->sku;
                    $line .= $comma . $product->weight;
                    $line .= $comma . $product->length;
                    $line .= $comma . $product->width;
                    $line .= $comma . $product->height;


                    $settings = json_decode($product->settings);
                    $line .=  isset($settings->freight_enabled)  ? $comma . $settings->freight_enabled : $comma . false;

                    $line .=  isset($settings->freight_class)  ? $comma . $settings->freight_class : $comma;
                    $line .=  isset($settings->hazardous_enabled)  ? $comma . $settings->hazardous_enabled : $comma . false;
                    $line .=  isset($settings->insurance)  ? $comma . $settings->insurance : $comma . false;
                    $line .=  isset($settings->dropship_enabled)  ? $comma . $settings->dropship_enabled : $comma . false;
                    $line .=  isset($settings->dropship_location)  ? $comma . $settings->dropship_location : $comma;
                    $line .=  isset($settings->small_enabled)  ? $comma . $settings->small_enabled : $comma;



                    $line .= "\n";
                    fputs($fp, $line);
                }
            });
            ExportProductsModel::find($request['exportProductsId'])->update(['status' => 1]);
            $this->makeZipWithFiles($folderName);
            $this->sendEmail($request['email'], $hash);
        } catch (RequestException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorMessage = "An error occurred.";

            if ($e->hasResponse()) {
                if ($statusCode != 500) {
                    echo $errorMessage = Psr7\str($e->getResponse());
                }
            }
        }
    }

    public function makeDirectory($path, $mode = 0777, $recursive = false, $force = false)
    {
        if ($force){
            return @mkdir($path, $mode, $recursive);
        }else{
            return mkdir($path, $mode, $recursive);
        }
    }


    public function sendEmail($email, $hash){
        //$to = 'gula47141@gmail.com';
        Mail::to($email)->send(new ExportProductsEmail($hash));
    }

    public function ImportNotifyEmail($email){
        Mail::to($email)->send(new ImportProductsEmail());
    }

    public function makeZipWithFiles($folderName){
            $zip = new ZipArchive;
            $files = glob($folderName.'/*.csv');
            $zipFileName = $folderName.'.zip';
            if ($zip->open($zipFileName, ZipArchive::CREATE) === TRUE) {
                // Add File in ZipArchive
                foreach($files as $file) {
                    $name = explode('/',$file);
                    $name = $name[count($name)-1];
                    $zip->addFile($file, $name);
                }
                $zip->close();
            }
            File::deleteDirectory($folderName);
            return $zipFileName;

    }

    public function downloadCsv($hash){
        $status = ExportProductsModel::where('hash', $hash)->first();
        if(empty($status) || $status->status == 0 || ($status->request_time <= time()-24*3600) ){
            echo "Download link has been expired";
        }else{
            $status->status = 2;
            $status->save();
            $foldername = explode('/', $status->foldername);
            $foldername = implode('/', $foldername);
            $filetopath = asset('public'.$foldername);
            header('Location: '. $filetopath); exit;
        }
    }

    public function uploadCsv(Request $request){
        $token = $request->token;
        $store = Store::where('token', $token)->get()->toArray();
        $hash = $store[0]['hash'];
        if ($request->hasFile('file')){
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension(); // you can also use file name
            $fileName = time().'.'.$extension;
            $path = public_path().'/import_files/'.$hash;
            $file->move($path,$fileName);
        }
        return response()->json(['error' => false,
            'data' => [],
            'filename' => $fileName,
            'message' => 'Uploaded Completed',
        ], 200);
    }

    public function getRowHeaderImportedFile(Request $request){
        $path = public_path('import_files/'.$request['store_hash'].'/'.$request['filename']);
        $csv = array_map('str_getcsv', file($path));
        array_walk($csv, function(&$a) use ($csv) {
            $a = array_combine($csv[0], $a);
        });
        if( isset($request['hasheaders']) && $request['hasheaders'] === "false"){
            $heading = range('A','ZZ');
        }else{
            foreach ($csv[0] as $key=> $val){
                $heading[] = trim($val);
            }
        }
        $heading = array_slice($heading, 0, count($csv[0]));
        return response()->json([
            'error' => false,
            'data' => $heading ?? [],
        ], 200);
    }

    public function importProductsCsv(Request $request){
        $chunks = $this->splitCcvInChunks($request);
        //$this->importProductCsvJob($request);
        $delay = 10;

        $data['filename'] = $request['filename'];
        $data['firstHeader'] = $request['firstHeader'];
        $data['importEmailAddress'] = $request['importEmailAddress'];
        $data['indexes'] = $request['indexes'];
        $data['store_hash'] = $request['store_hash'];
        $data['store_id'] = $request['store_id'];
        $data['store_name'] = $request['store_name'];
        foreach ($chunks as $key => $path){
            $data['path'] = $path;
            $delay = ($key+1)*10;
            ImportProductsJob::dispatch($data)->delay(Carbon::now()->addSecond($delay));
            //$this->importProductCsvJob($request);
        }
        ImportProductsNotification::dispatch($data['importEmailAddress'])->delay(Carbon::now()->addSecond($delay+10));
        // start running queue
        \Artisan::call('queue:work');
        return response()->json([
            'error' => false,
            'data' => $data,
            'delay'=>$delay,
        ], 200);
    }

    public function importProductCsvJob($request){
        $indexes = $request['indexes'];
        $store_id = $request['store_id'];
        $store = Store::where('id', $store_id)->first();
        $emailNotify = $request['importEmailAddress'] ?? '';
        $path = $request['path'];//public_path('import_files/'.$request['store_hash'].'/'.$request['filename']);
        $csv = array_map('str_getcsv', file($path));
        $headerRow = array_slice(range('A','Z'), 0, count($csv[0]));
        if($request['firstHeader'] == "true" ){
            $headerRow = $csv[0];
            unset($csv[0]);
        }
        array_walk($csv, function(&$a) use ($csv, $headerRow) {
            $a = array_combine(array_map('trim', $headerRow), array_map('trim', $a));
        });
        foreach ($csv as $key => $product) {
            $this->getUpdateData($product, $indexes, $store_id, $store->access_token, $request['store_hash']);
        }
        unlink($path);
    }
    function getUpdateData($product, $indexes, $store_id, $access_token, $hash){
        $update = [];
        if(isset($indexes['id']) && $indexes['id']){
            $key = $indexes['id'];
            $source_product_id = (int) $product["$key"];
            if(!ProductSetting::where('source_product_id', $source_product_id)
                ->where('store_id', $store_id)->exists()) {
                return true; // no action perform if product not exist
            }
            $oldSettings = ProductSetting::where('source_product_id', $source_product_id)
                ->where('store_id', $store_id)->pluck('settings')->toArray();
            $update['settings'] = json_encode($this->getSettings($oldSettings, $product, $indexes));
        }
        if(isset($indexes['name']) && $indexes['name']){
            $key = $indexes['name'];
            $update['name'] = $product["$key"];
        }
        if(isset($indexes['weight']) && $indexes['weight']){
            $key = $indexes['weight'];
            $update['weight'] = (float) $product["$key"];
        }
        if(isset($indexes['length']) && $indexes['length']){
            $key = $indexes['length'];
            $update['length'] = (float) $product["$key"];
        }
        if(isset($indexes['width']) && $indexes['width']){
            $key = $indexes['width'];
            $update['width'] = (float) $product["$key"];
        }
        if(isset($indexes['height']) && $indexes['height']){
            $key = $indexes['height'];
            $update['height'] = (float) $product["$key"];
        }
        if(!empty($update)){
            ProductSetting::where('source_product_id', $source_product_id)
                ->where('store_id', $store_id)->update($update);
            unset($update['settings']);
            $this->updateBCProduct($source_product_id, $store_id, $update,  $access_token, $hash);
        }
    }
    public function getSettings($oldSettings, $product, $indexes){
        $settings = $oldSettings[0] ? json_decode($oldSettings[0]) : new \stdClass();
        if(isset($indexes['freight_enabled']) && $indexes['freight_enabled']){
            $key = $indexes['freight_enabled'];
            if(array_key_exists($key, $product)){
                $settings->freight_enabled = (bool) $product["$key"];
            }
        }
        if(isset($indexes['small_enabled']) && $indexes['small_enabled']){
            $key = $indexes['small_enabled'];
            if(array_key_exists($key, $product)){
                $settings->small_enabled = (bool) $product["$key"];
            }
        }
        if(isset($indexes['freight_class']) && $indexes['freight_class']){
            $key = $indexes['freight_class'];
            if(array_key_exists($key, $product)) {
                $settings->freight_class = (string)$product["$key"];
            }
        }
        if(isset($indexes['dropship_enabled']) && $indexes['dropship_enabled']){
            $key = $indexes['dropship_enabled'];
            if(array_key_exists($key, $product)) {
                $settings->dropship_enabled = (bool)$product["$key"];
            }
        }
        if(isset($indexes['dropship_location']) && $indexes['dropship_location']){
            $key = $indexes['dropship_location'];
            if(array_key_exists($key, $product)) {
                $settings->dropship_location = (int)$product["$key"];
            }
        }
        if(isset($indexes['insurance']) && $indexes['insurance']){
            $key = $indexes['insurance'];
            if(array_key_exists($key, $product)) {
                $settings->insurance = (bool)$product["$key"];
            }
        }
        if(isset($indexes['hazardous_enabled']) && $indexes['hazardous_enabled']){
            $key = $indexes['hazardous_enabled'];
            if(array_key_exists($key, $product)) {
                $settings->hazardous_enabled = (bool)$product["$key"];
            }
        }
        return $settings;
    }

    public function updateBCProduct($source_product_id, $store_id, $update,  $access_token, $hash){
        unset($headers);
        $headers[] = 'X-Auth-Token: ' . $access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/".$hash."/v3/catalog/products/".$source_product_id;
        if(isset($update['length'])){
            $update['depth'] = $update['length'];
        }
        unset($update['length']);
        $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($update), $headers, 'PUT', false);
    }

    public function splitCcvInChunks($request){
        $path = public_path('import_files/'.$request['store_hash'].'/'.$request['filename']);
        $inputFile = $path;
        $outputFile =  str_replace('.csv', '', $path).'/';
        $this->makeDirectory($outputFile, $mode = 0777, true, true);
        $splitSize = 20;

        $in = fopen($inputFile, 'r');
        $headerRow = [];
        if($request['firstHeader'] == "true" ){
            $headerRow = fgetcsv($in);
        }
        $rowCount = 0;
        $fileCount = 1;
        $files = [];
        while (!feof($in)) {
            if (($rowCount % $splitSize) == 0) {
                if ($rowCount > 0) {
                    fclose($out);
                }
                $fileName = $outputFile . $fileCount++ . '.csv';
                $files[] = $fileName;
                $out = fopen($fileName, 'w');
                if(!empty($headerRow)) {
                    fputcsv($out, $headerRow);
                }
            }
            $data = fgetcsv($in);
            if ($data)
                fputcsv($out, $data);
            $rowCount++;
        }

        fclose($out);
        return $files;
    }

    /**
     * Output the cpu usage
     */
    public function getCpuUsage()
    {
        $load = sys_getloadavg();
        return $load[0];
    }
}
