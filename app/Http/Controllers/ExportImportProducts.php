<?php

namespace App\Http\Controllers;

use App\Models\ProductSetting;
use App\Models\ExportProducts as ExportProductsModel;
use App\Mail\ExportProducts as ExportProductsEmail;
use App\Models\Store;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\URL;
use ZipArchive;
use Illuminate\Filesystem\Filesystem;
use App\CurlRequest;

class ExportImportProducts extends Controller
{
    public $curlRequest;
    public $access_token;
    public $store_hash;


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
            $productsChunk->chunk(2000, function ($products, $chunkCount = 0) use ($comma, $folderName) {
                $fileName = $chunkCount++ . '-export.csv';
                $filename = $folderName . '/' . $fileName;
                $folderNamePath[] = $filename;
                $fp = fopen($filename, "w");
                if (true) {
                    $line = 'Product Id, Product Name, Product SKU, Weight (lbs), Length (in), Width (in), Height (in),Freight Enabled, Freight Class, Hazardous Enabled, Insurance, Dropship Enabled, Dropship Location';
                    $line .= "\n";
                    fputs($fp, $line);
                }
                foreach ($products as $key => $product) {
                    $line = $product->source_product_id;
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
        return response()->json(['error' => false,
            'data' => [],
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

    public function importProducts(Request $request){
        echo time();
        $indexes = $request->indexes;
        $store_id = $request['store_id'];
        $store = Store::where('id', $store_id)->first();
        $this->access_token = $store->access_token;
        $this->store_hash = $request['store_hash'];
        $this->curlRequest = new CurlRequest();

        $path = public_path('import_files/'.$request['store_hash'].'/'.$request['filename']);
        $csv = array_map('str_getcsv', file($path));
        array_walk($csv, function(&$a) use ($csv) {
            $a = array_combine(array_map('trim', $csv[0]), array_map('trim', $a));
        });
        unset($csv[0]);
        $count = 0;
        try {
            foreach ($csv as $key => $product) {
                $this->getUpdateData($product, $indexes, $store_id);
                /*$count++;
                if($count>4){
                    dd($count);
                }*/
            }
        }catch (RequestException $e){
            echo 'catch'.time();
        }
        echo time();
    }
    function getUpdateData($product, $indexes, $store_id){
        $update = [];
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
        if(isset($indexes['id']) && $indexes['id']){
            $key = $indexes['id'];
            $source_product_id = (int) $product["$key"];
            $oldSettings = ProductSetting::where('source_product_id', $source_product_id)
                ->where('store_id', $store_id)->pluck('settings')->toArray();
            $update['settings'] = json_encode($this->getSettings($oldSettings, $product, $indexes));
        }
        if(!empty($update)){
            ProductSetting::where('source_product_id', $source_product_id)
                ->where('store_id', $store_id)->update($update);
            unset($update['settings']);
            $this->updateBCProduct($source_product_id, $store_id, $update);
        }
        return $update;
    }
    public function getSettings($oldSettings, $product, $indexes){
        $settings = $oldSettings[0] ? json_decode($oldSettings[0]) : new \stdClass();
        if(isset($indexes['freight_enabled'])){
            $key = $indexes['freight_enabled'];
            $settings->freight_enabled = (bool) $product["$key"];
        }
        if(isset($indexes['freight_class']) && $indexes['freight_class']){
            $key = $indexes['freight_class'];
            $settings->freight_class = (string) $product["$key"];
        }
        if(isset($indexes['dropship_enabled'])){
            $key = $indexes['dropship_enabled'];
            $settings->dropship_enabled = (bool) $product["$key"];
        }
        if(isset($indexes['dropship_location']) && $indexes['dropship_location']){
            $key = $indexes['dropship_location'];
            $settings->dropship_location = (int) $product["$key"];
        }
        if(isset($indexes['insurance'])){
            $key = $indexes['insurance'];
            $settings->insurance = (bool) $product["$key"];
        }
        if(isset($indexes['hazardous_enabled'])){
            $key = $indexes['hazardous_enabled'];
            $settings->hazardous_enabled = (bool) $product["$key"];
        }
        return $settings;
    }

    public function updateBCProduct($source_product_id, $store_id, $update){
        $headers[] = 'X-Auth-Token: ' . $this->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/".$this->store_hash."/v3/catalog/products/".$source_product_id;
        $update['depth'] = $update['length'];
        unset($update['length']);
        $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($update), $headers, 'PUT', false);
    }

}
