<?php

namespace App\Http\Controllers;

use App\CustomClasses\BigCommerceFunctions;
use App\Jobs\ImportProducts as ImportProductsJob;
use App\Jobs\ImportProductsNotification;
use App\Models\Locations;
use App\Models\ProductSetting;
use App\Models\ExportProducts as ExportProductsModel;
use App\Mail\ExportProducts as ExportProductsEmail;
use App\Mail\ImportProducts as ImportProductsEmail;
use App\Mail\ExportCsvNotification as CsvNotifyEmail;
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
use App\CustomClasses\Functions;

class ExportImportProducts extends Controller
{
    public $curlRequest;
    public $csvChunksLength;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->csvChunksLength = 20;
    }

    public function exportProductsTemplate(Request $request)
    {
        if (isset($request['onlyResponse']) && $request['onlyResponse'] === true) {
            $productsChunk = ProductSetting::where('store_id', $request['store_id']);
            if (!$productsChunk->count()) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Products not available for import template',
                ], 200);
            } else {

                $hash = md5($request['store_id'] . time());
                $this->CsvNotifyEmail($request['email'], $hash);

                return response()->json(['error' => false,
                    'data' => [],
                    'message' => 'The import CSV template will be emailed to ' . $request['email'],
                ], 200);
            }
        } else {
            return $this->createExportData($request);
        }
    }

    public function createExportData($request)
    {
        $locations = Locations::where('store_id', $request['store_id'])->where('type', 2)->get()->toArray();
        $storeHash = $request['store_hash'] ?? null;
        $weightDimensionUnits = $this->getweightDimensionUnits($storeHash);
        $weightUnit = isset($weightDimensionUnits['weight_units']) && !blank($weightDimensionUnits['weight_units']) ? strtolower($weightDimensionUnits['weight_units']) : 'lbs' ?? 'lbs';
        $dimensionsUnit = isset($weightDimensionUnits['dimension_units']) && $weightDimensionUnits['dimension_units'] === 'Centimeters' ? 'cm' : 'in' ?? 'in';
        
        $dropShips = [];
        foreach ($locations as $location) {
            $dropShips[$location['id']] = $location;
        }
        
        $productsChunk = ProductSetting::where('store_id', $request['store_id']);
        if (!$productsChunk->count()) {
            return [];
        }
        $comma = ",";
        
        try {
            if (!isset($request['rerunrequest'])) {
                $fileName = '/export_files/' . $request['store_hash'] . '/' . time();
                $request['folderName'] = public_path() . $fileName;
                $hash = md5($request['store_id'] . time());
                $this->makeDirectory($request['folderName'], $mode = 0777, true, true);
                $request['exportProductsId'] = ExportProductsModel::insertGetId(['store_id' => $request['store_id'], 'foldername' => $fileName . '.zip', 'hash' => $hash, 'request_time' => time(), 'email' => $request['email'], 'status' => 0]);
            }
            $folderName = $request['folderName'];
            $folderNamePath = [];
            
            $productsChunk->chunk(2500, function ($products, $chunkCount = 0) use ($comma, $folderName, $dropShips, $weightUnit, $dimensionsUnit) {
                $fileName = $chunkCount++ . '-export.csv';
                $filename = $folderName . '/' . $fileName;
                $folderNamePath[] = $filename;
                $fp = fopen($filename, "w");
                if (true) {
                    $line = 'Product Id, Variant Id, Product Name, Product SKU, Weight (' . $weightUnit . '), Length (' . $dimensionsUnit . '), Width (' . $dimensionsUnit . '), Height (' . $dimensionsUnit . '), NMFC, Markup, Quote Method, Freight Class, Hazmat, Insurance, Dropship Nickname, Dropship ZIP Code, Dropship City, Dropship State, Dropship Country, Boxing Properties, Ships Own Pallet, Pallet Vertical Rotation';
                    $line .= "\n";
                    fputs($fp, $line);
                }
                foreach ($products as $key => $product) {
                    // Check: if product variant id is null then product will not add in CSV file.
                    if(!isset($product->variant_id) && $product->variant_id == null){
                        continue;
                    }
                    $productLine = [];
                    $productLine[] = 'P' . $product->source_product_id;
                    $productLine[] = 'V' . $product->variant_id;
                    $productLine[] = $product->name ?? '';
                    $productLine[] = $product->sku ?? '';
                    $productLine[] = $product->weight ?? '';
                    $productLine[] = $product->length ?? '';
                    $productLine[] = $product->width ?? '';
                    $productLine[] = $product->height ?? '';
                    $productLine[] = $product->nmfc ?? '';
                    $productLine[] = $product->product_markup ?? '';

                    $settings = json_decode($product->settings);
                    $quoteMethod = '';
                    // Added INstore and local quoting methods
                    if (isset($settings->freight_enabled) && $settings->freight_enabled) {
                        $quoteMethod = 'L';
                    } else if (isset($settings->parcel_enabled) && $settings->parcel_enabled) {
                        $quoteMethod = 'S';
                    } else if (isset($settings->quote_as_local) && $settings->quote_as_local) {
                        $quoteMethod = 'PD';
                    }
                    $productLine[] = $quoteMethod;
                    $productLine[] = $settings->freight_class ?? '';
                    $productLine[] = isset($settings->hazardous_enabled) && $settings->hazardous_enabled ? 1 : 0;
                    $productLine[] = isset($settings->insurance) && $settings->insurance ? 1 : 0;

                    $nickname = $zip = $city = $state = $country = '';
                    if (isset($product->dropship_enabled) && $product->dropship_enabled) {
                        $location = $product->dropship_location ?? false;
                        if ($location) {
                            $dropShip = $dropShips[$location] ?? [];
                            $nickname = $dropShip['nickname'] ?? '';
                            $city = $dropShip['city'] ?? '';
                            $state = $dropShip['state'] ?? '';
                            $zip = $dropShip['zip_code'] ?? '';
                            $country = $dropShip['country'] ?? '';
                        }
                    }
                    $boxingProperty = '';
                    // Added Boxing Properties
                    if (isset($settings->ship_own_package) && $settings->ship_own_package) {
                        $boxingProperty = '1';
                    } else if (isset($settings->allow_vertical) && $settings->allow_vertical) {
                        $boxingProperty = '2';
                    } else if (isset($product->ship_multiple_package) && $product->ship_multiple_package) {
                        $boxingProperty = '3';
                    } else if (isset($product->ship_multiple_package) && !$product->ship_multiple_package && 
                            isset($settings->ship_own_package) && !$settings->ship_own_package && 
                            isset($settings->allow_vertical) && !$settings->allow_vertical) {
                        $boxingProperty = '0';
                    }
                    $productLine[] = $nickname;
                    $productLine[] = $zip;
                    $productLine[] = $city;
                    $productLine[] = $state;
                    $productLine[] = $country;
                    $productLine[] = $boxingProperty;
                    $productLine[] = isset($product->own_pallet) && $product->own_pallet ? 1 : 0;
                    $productLine[] = isset($product->pallet_vertical_rotation) && $product->pallet_vertical_rotation ? 1 : 0;
                    fputcsv($fp, $productLine);
                }
            });
            $isupdate = ExportProductsModel::find($request['exportProductsId'])->update(['status' => 1]);
            $this->makeZipWithFiles($folderName);
            $this->sendEmail($request['email'], $hash);
            if($isupdate){
              return  $this->createCSVDownloadLink($request['exportProductsId'],$hash);
            }
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
    // Create CSV export download link for display on the dashboard of the app
    public function createCSVDownloadLink($exportProductId,$hash)
    {  
        $available = ExportProductsModel::where(['id' => $exportProductId,'status' => 1])->exists();
        if($available){
           $url = URL::to('api/downloadcsv/'.$hash);
           $message =  "The export CSV template has been finished.";
           return response()->json([
            "error" => false,
            'message' => $message,
            'data' => $url,
           ],200); 
        }
    }

    public function getCSVDownloadLink(Request $request)
    {
        $status = ExportProductsModel::where('store_id', $request['store_id'])->latest()->first() ?? [];

        if(isset($request['is_link_invisible']) && $request['is_link_invisible'] == 'true' ){
            $status->is_link_invisible = 1;
            $status->save();
            return response()->json([
                "error" => false,
                'message' => 'Download link has been invisible',
                'data' => '',
               ], 200
            );
        }

        if (empty($status) || $status->status == 0 || $status->is_link_invisible == 1 || ($status->request_time <= time() - 24 * 3600)) {
            return response()->json([
                "error" => true,
                'message' => 'Download link has been expired',
                'data' => '',
               ], 200
            );
        }
        
        if(!empty($status)){
           $url = URL::to('api/downloadcsv/'.$status['hash']);
           $message =  "The export CSV template has been finished.";
           return response()->json([
            "error" => false,
            'message' => $message,
            'data' => $url,
           ],200); 
        }
    }

    public function getweightDimensionUnits($storeHash)
    {
        if (blank($storeHash)) {
            return '';
        }
        $storeDetails = BigCommerceFunctions::getStoreSettings($storeHash);
        $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
        $response = json_decode($storeDetails['response'], true);

        return $response;
    }

    public function makeDirectory($path, $mode = 0777, $recursive = false, $force = false)
    {
        if ($force) {
            return @mkdir($path, $mode, $recursive);
        } else {
            return mkdir($path, $mode, $recursive);
        }
    }


    public function sendEmail($email, $hash)
    {
        //$to = 'gula47141@gmail.com';
        Mail::to($email)->send(new ExportProductsEmail($hash));
    }

    public function CsvNotifyEmail($email, $hash)
    {
        Mail::to($email)->send(new CsvNotifyEmail($hash));
    }

    public function ImportNotifyEmail($email)
    {
        Mail::to($email)->send(new ImportProductsEmail());
    }

    public function makeZipWithFiles($folderName)
    {
        $zip = new ZipArchive;
        $files = glob($folderName . '/*.csv');
        $zipFileName = $folderName . '.zip';
        if ($zip->open($zipFileName, ZipArchive::CREATE) === TRUE) {
            // Add File in ZipArchive
            foreach ($files as $file) {
                $name = explode('/', $file);
                $name = $name[count($name) - 1];
                $zip->addFile($file, $name);
            }
            $zip->close();
        }
        File::deleteDirectory($folderName);
        return $zipFileName;

    }

    public function downloadCsv($hash)
    {
        $status = ExportProductsModel::where('hash', $hash)->first();
        if (empty($status) || $status->status == 0 || ($status->request_time <= time() - 24 * 3600)) {
            echo "Download link has been expired";
        } else {
            $status->status = 2;
            $status->save();
            $foldername = explode('/', $status->foldername);
            $foldername = implode('/', $foldername);
            $filetopath = asset('public' . $foldername);
            header('Location: ' . $filetopath);
            exit;
        }
    }

    public function uploadCsv(Request $request)
    {
        $token = $request->token;
        $store = Store::where('token', $token)->get()->toArray();
        $hash = $store[0]['hash'];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension(); // you can also use file name
            $fileName = time() . '.' . $extension;
            $path = public_path() . '/import_files/' . $hash;
            $file->move($path, $fileName);
        }
        return response()->json(['error' => false,
            'data' => [],
            'filename' => $fileName,
            'message' => 'Uploaded Completed',
        ], 200);
    }

    public function getRowHeaderImportedFile(Request $request)
    {
        $path = public_path('import_files/' . $request['store_hash'] . '/' . $request['filename']);
        $csv = array_map('str_getcsv', file($path));
        //dd($csv[0]);
        /*array_walk($csv, function(&$a) use ($csv) {
            $a = array_combine($csv[0], $a);
        });*/

        if (isset($request['hasheaders']) && $request['hasheaders'] === "false") {
            $heading = range('A', 'ZZ');
        } else {
            foreach ($csv[0] as $key => $val) {
                $heading[] = trim($val);
            }
        }
        $heading = array_slice($heading, 0, count($csv[0]));
        return response()->json([
            'error' => false,
            'data' => $heading ?? [],
        ], 200);
    }

    public function importProductsCsv(Request $request)
    {
        ini_set('memory_limit', '-1');
        try {
            Log::info('started import products process');
            $delay = 2;
            $data['filename'] = $request['filename'];
            $data['firstHeader'] = $request['firstHeader'];
            $data['importEmailAddress'] = $request['importEmailAddress'];
            $data['indexes'] = $request['indexes'];
            $data['store_hash'] = $request['store_hash'];
            $data['store_id'] = $request['store_id'];
            $data['store_name'] = $request['store_name'];
            $data['path'] = public_path('import_files/' . $request['store_hash'] . '/' . $request['filename']);

            ImportProductsJob::dispatch($data)->delay(Carbon::now()->addSeconds($delay));
            unset($data['path']);

            Log::info('ended import products process');

            return response()->json([
                'error' => false,
                'data' => $data,
            ], 200);

        } catch (\Exception $exception) {
            Log::info(json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
            ]));

            return response()->json([
                'error' => true,
                'message' => $exception->getMessage(),
            ], 200);
        }
    }

    public function importProductCsvJob($request)
    {
        try {
            if(Functions::isEnabledLogs($request['store_hash'])){
                Log::info('CSV Import Products Job Start: ' . json_encode($request));
            }
            $indexes = $request['indexes'];
            $store_id = $request['store_id'];
            $store = Store::where('id', $store_id)->first();
            $emailNotify = $request['importEmailAddress'] ?? '';
            $path = $request['path'];
            $exceptionProducts = [];
    
            if (!file_exists($path)) {
                return false;
            }
    
            // Converting Csv TO String
            $csvArray = array_map('str_getcsv', file($path));
            if (count($csvArray) < 1) {
                return false;
            }
    
            $headerRow = array_slice(range('A', 'Z'), 0, count($csvArray[0]));
            if ($request['firstHeader'] == "true") {
                $headerRow = $csvArray[0];
                 unset($csvArray[0]);
            }
            array_walk($csvArray, function (&$a) use ($csvArray, $headerRow) {
                $a = array_combine(array_map('trim', $headerRow), array_map('trim', $a));
            });
    
            $csvChunks = array_chunk($csvArray, $this->csvChunksLength);
    
            foreach ($csvChunks as $chunkKey => $csv) {
                foreach ($csv as $key => $product) {
                    try {
                        $this->getUpdateData($product, $indexes, $store_id, $store->access_token, $request['store_hash']);
    
                    } catch (\Exception $exception) {
                        $exceptionProducts[] = [
                            'productId' => $product['Product Id'] ?? '',
                            'varientId' => $product['Variant Id'] ?? '',
                        ];
    
                        if(Functions::isEnabledLogs($request['store_hash'])){
                            Log::info('CSV Products Exception Array: ' . json_encode($exceptionProducts));
                            Log::info(json_encode([
                                'line' => $exception->getLine(),
                                'message' => $exception->getMessage(),
                                'file' => $exception->getFile(),
                            ]));
                        }
                    }
                }
            }
    
            $this->ImportNotifyEmail($emailNotify);
            if(Functions::isEnabledLogs($request['store_hash'])){
                Log::info('CSV Import Poducts Email Send.');
            }
        } catch (\Exception $exception) {
            Log::info(json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
            ]));
        }
        
    }

    function getUpdateData($product, $indexes, $store_id, $access_token, $hash)
    {
        $update = [];
        if (isset($indexes['id']) && $indexes['id'] && isset($indexes['variantid']) && $indexes['variantid']) {
            $key = $indexes['id'];
            $variant_key = $indexes['variantid'];

            $source_product_id = (int)filter_var($product["$key"], FILTER_SANITIZE_NUMBER_INT);

            $variant_id = (int)filter_var($product["$variant_key"], FILTER_SANITIZE_NUMBER_INT);

            if ($variant_id) {
                $oldSettings = ProductSetting::where('source_product_id', $source_product_id)
                    ->where('variant_id', $variant_id)
                    ->where('store_id', $store_id)->pluck('settings')->toArray();
            } else {
                $oldSettings = ProductSetting::where('source_product_id', $source_product_id)
                    ->whereNull('variant_id')
                    ->where('store_id', $store_id)->pluck('settings')->toArray();
            }
            $settings = $this->getSettings($oldSettings, $product, $indexes, $store_id);
            $shipMultiPackage = isset($settings->ship_multi_package) ? $settings->ship_multi_package : null;
            $update['settings'] = json_encode($settings);
        }
        if (isset($indexes['name']) && $indexes['name']) {
            $key = $indexes['name'];
            $update['name'] = $product["$key"];
        }
        if (isset($indexes['sku']) && $indexes['sku']) {
            $key = $indexes['sku'];
            $update['sku'] = $product["$key"];
        }
        if (isset($indexes['weight']) && $indexes['weight']) {
            $key = $indexes['weight'];
            $data = $product["$key"];
            if(is_numeric($data) || empty($data)){
                $update['weight'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['length']) && $indexes['length']) {
            $key = $indexes['length'];
            $data = $product["$key"];
            if(is_numeric($data) || empty($data)){
                $update['length'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['width']) && $indexes['width']) {
            $key = $indexes['width'];
            $data = $product["$key"];
            if(is_numeric($data) || empty($data)){
                $update['width'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['height']) && $indexes['height']) {
            $key = $indexes['height'];
            $data = $product["$key"];
            if(is_numeric($data) || empty($data)){
                $update['height'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['nmfc']) && $indexes['nmfc']) {
            $key = $indexes['nmfc'];
            $data = $product["$key"];
            if(is_numeric($data) || empty($data)){
                $update['nmfc'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['product_markup']) && $indexes['product_markup']) {
            $key = $indexes['product_markup'];
            $data = $product["$key"];
            if(is_numeric($data) || empty($data)){
                $update['product_markup'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['own_pallet']) && $indexes['own_pallet']) {
            $key = $indexes['own_pallet'];
            $data = (string)$product["$key"];
            $data = $data != '' ? (float)$product["$key"] : '';
            if ($data >= 0 || empty($data)) {
                $update['own_pallet'] = (float)$product["$key"];
            }
        }
        if (isset($indexes['pallet_vertical_rotation']) && $indexes['pallet_vertical_rotation']) {
            $key = $indexes['pallet_vertical_rotation'];
            $data = (string)$product["$key"];
            $data = $data != '' ? (float)$product["$key"] : '';
            if ($data >= 0 || empty($data)) {
                $update['pallet_vertical_rotation'] = (float)$product["$key"];
            }
        }

        if ($shipMultiPackage){
            $update['ship_multiple_package'] = true;    
        } else if ($shipMultiPackage === null){
            $update['ship_multiple_package'] = null;
        } else {
            $update['ship_multiple_package'] = false;
        }

        /*Start -  For Dropship CHange*/
        if (isset($indexes['drop_ship_nickname']) && $indexes['drop_ship_nickname']
        && isset($indexes['drop_ship_city']) && $indexes['drop_ship_city']
        && isset($indexes['drop_ship_state']) && $indexes['drop_ship_state']
        && isset($indexes['drop_ship_zip']) && $indexes['drop_ship_zip']
        && isset($indexes['drop_ship_country']) && $indexes['drop_ship_country'])
        {
            $dropShipId = $this->updateDropShip($product, $indexes, $store_id);
            if ($dropShipId != false) {
                $update['dropship_enabled'] = true;
                $update['dropship_location'] = $dropShipId;
            } else {
                $update['dropship_enabled'] = false;
                $update['dropship_location'] = null;
            }
        }
        // END //

        if(Functions::isEnabledLogs('', $store_id)){
            Log::info('CSV Import products Data: ' . $variant_id . " " . json_encode($update));
        }

        if (!empty($update)) {
            if ($variant_id) {
                ProductSetting::where('source_product_id', $source_product_id)
                    ->where('variant_id', $variant_id)
                    ->where('store_id', $store_id)->update($update);
            } else {
                ProductSetting::where('source_product_id', $source_product_id)
                    ->whereNull('variant_id')
                    ->where('store_id', $store_id)->update($update);
            }
            unset($update['settings']);
            $this->updateBCProduct($source_product_id, $variant_id, $store_id, $update, $access_token, $hash);
        }
    }

    public function getSettings($oldSettings, $product, $indexes, $store_id)
    {
        $settings = isset($oldSettings[0]) && $oldSettings[0] ? json_decode($oldSettings[0]) : new \stdClass();
       
        if (isset($indexes['quote_method']) && $indexes['quote_method']) {
            $key = $indexes['quote_method'];
            $quoteMethod = strtolower($product["$key"]);
            if (array_key_exists($key, $product)) {
                $settings->parcel_enabled = false;
                $settings->freight_enabled = false;
                $settings->quote_as_local = false;
                // Added instore and local delivery quoting method here as well Instore-local

                if ($quoteMethod === 's') {
                    $settings->parcel_enabled = true;
                } else if ($quoteMethod === 'l') {
                    $settings->freight_enabled = true;
                } else if ($quoteMethod === 'pd') {
                    $settings->quote_as_local = true;
                }
            }
        }
        if (isset($indexes['freight_class']) && $indexes['freight_class']) {
            $key = $indexes['freight_class'];
            if (array_key_exists($key, $product)) {
                $freightClass = (string)$product["$key"];
                if ($freightClass == '' || $this->isFreightClass($freightClass)) {
                    $settings->freight_class = (string)$product["$key"];
                }
            }
        }
        if (isset($indexes['boxing_property']) && $indexes['boxing_property']) {
            $key = $indexes['boxing_property'];
            $boxingProperty = strtolower($product["$key"]);
            if (array_key_exists($key, $product)) {
                $settings->ship_own_package = false;
                $settings->allow_vertical = false;
                $settings->ship_multi_package = false;
                // Added Boxing Properties

                if ($boxingProperty === '1') {
                    $settings->ship_own_package = true;
                } else if ($boxingProperty === '2') {
                    $settings->allow_vertical = true;
                } else if ($boxingProperty === '3') {
                    $settings->ship_multi_package = true;
                } else if ($boxingProperty === '') {
                    $settings->ship_own_package = null;
                    $settings->allow_vertical = null;
                    $settings->ship_multi_package = null;
                }
            }
        }

        if (isset($indexes['nmfc']) && $indexes['nmfc']) {
            $key = $indexes['nmfc'];
            if (array_key_exists($key, $product)) {
                $settings->nmfc = (string)$product["$key"];
            }
        }

        if (isset($indexes['own_pallet']) && $indexes['own_pallet']) {
            $key = $indexes['own_pallet'];
            if (array_key_exists($key, $product)) {
                $settings->own_pallet = ($product["$key"] == 1) ? true : false;
            }
        }
        if (isset($indexes['pallet_vertical_rotation']) && $indexes['pallet_vertical_rotation']) {
            $key = $indexes['pallet_vertical_rotation'];
            if (array_key_exists($key, $product)) {
                $settings->pallet_vertical_rotation = ($product["$key"] == 1) ? true : false;;
            }
        }

        if (isset($indexes['insurance']) && $indexes['insurance']) {
            $key = $indexes['insurance'];
            if (array_key_exists($key, $product)) {
                $settings->insurance = ($product["$key"] == 1) ? true : false;;
            }
        }
        if (isset($indexes['hazardous_enabled']) && $indexes['hazardous_enabled']) {
            $key = $indexes['hazardous_enabled'];
            if (array_key_exists($key, $product)) {
                $settings->hazardous_enabled = ($product["$key"] == 1) ? true : false;;
            }
        }

        return $settings;
    }

    function isFreightClass($freigtClass)
    {
        $allFreightClass = ['50', '55', '60', '65', '70', '77.5', '85', '92.5', '100', '125', '150', '175', '200', '250', '300', '400', '500', 'DensityBased'];
        return in_array($freigtClass, $allFreightClass);
    }

    public function updateDropShip($product, $indexes, $store_id)
    {
        $dropShipId = false;
        $isDropShip = isset($indexes['drop_ship_nickname']) && $indexes['drop_ship_nickname']
            && isset($indexes['drop_ship_city']) && $indexes['drop_ship_city']
            && isset($indexes['drop_ship_state']) && $indexes['drop_ship_state']
            && isset($indexes['drop_ship_zip']) && $indexes['drop_ship_zip']
            && isset($indexes['drop_ship_country']) && $indexes['drop_ship_country'];
        if ($isDropShip) {
            $dropship = true;
            if (array_key_exists($indexes['drop_ship_city'], $product)) {
                $drop_ship_city = $product[$indexes['drop_ship_city']];
            } else {
                $dropship = false;
            }
            if (array_key_exists($indexes['drop_ship_state'], $product)) {
                $drop_ship_state = $product[$indexes['drop_ship_state']];
            } else {
                $dropship = false;
            }
            if (array_key_exists($indexes['drop_ship_zip'], $product)) {
                $drop_ship_zip = $product[$indexes['drop_ship_zip']];
            } else {
                $dropship = false;
            }
            if (array_key_exists($indexes['drop_ship_country'], $product)) {
                $drop_ship_country = $product[$indexes['drop_ship_country']];
            } else {
                $dropship = false;
            }
            if (array_key_exists($indexes['drop_ship_nickname'], $product)) {
                $drop_ship_nickname = $product[$indexes['drop_ship_nickname']];
            } else {
                $dropship = false;
            }
            if (!($drop_ship_nickname && $drop_ship_country && $drop_ship_zip && $drop_ship_state && $drop_ship_city)) {
                $dropship = false;
            }

            if ($dropship) {
                $location = Locations::where('city', $drop_ship_city)
                    ->where('state', $drop_ship_state)
                    ->where('zip_code', $drop_ship_zip)
                    ->where('country', $drop_ship_country)
                    ->where('nickname', $drop_ship_nickname)
                    ->where('store_id', $store_id)
                    ->where('type', 2)
                    ->get()->toArray();
                if (!empty($location)) {
                    $dropShipId = $location[0]['id'] ?? false;
                } else {
                    // drop ship insert
                    $location = new Locations();
                    $location->nickname = $drop_ship_nickname;
                    $location->store_id = $store_id;
                    $location->type = 2;
                    $location->zip_code = $drop_ship_zip;
                    $location->city = $drop_ship_city;
                    $location->state = $drop_ship_state;
                    $location->country = $drop_ship_country;
                    $additionals = [
                        'instore_pickup' => '',
                        'local_delivery' => '',
                        'ld_enable_supress' => '',
                        'instore_pickup_data' => [
                            'miles' => '',
                            'postalCodes' => '',
                            'checkout_description' => '',
                        ],
                        'local_delivery_data' => [
                            'miles' => '',
                            'postalCodes' => '',
                            'local_delivery_fee' => '',
                            'checkout_description' => '',
                        ],
                    ];
                    $location->additionals = json_encode($additionals);
                    $location->save();
                    $dropShipId = $location->id;
                }

                if(Functions::isEnabledLogs("", $store_id)){
                    Log::info('Import Products Dropship: ' . $dropShipId  . " " . json_encode($location));
                }
            }
        }
        return $dropShipId;
    }

    public function updateBCProduct($source_product_id, $variant_id, $store_id, $update, $access_token, $hash)
    {
        unset($headers);
        $headers[] = 'X-Auth-Token: ' . $access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        if ($variant_id) {
            $endpoint = 'https://api.bigcommerce.com/stores/' . $hash . '/v3/catalog/products/' . $source_product_id . '/variants/' . $variant_id;
        } else {
            $endpoint = "https://api.bigcommerce.com/stores/" . $hash . "/v3/catalog/products/" . $source_product_id;
        }
        if (isset($update['length'])) {
            $update['depth'] = $update['length'];
        }
        unset($update['length']);
        $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($update), $headers, 'PUT', false);
    }

    public function splitCcvInChunks($request)
    {
        $path = public_path('import_files/' . $request['store_hash'] . '/' . $request['filename']);
        $inputFile = $path;
        $outputFile = str_replace('.csv', '', $path) . '/';
        $this->makeDirectory($outputFile, $mode = 0777, true, true);
        $splitSize = 20;

        $in = fopen($inputFile, 'r');
        $headerRow = [];
        if ($request['firstHeader'] == "true") {
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
                if (!empty($headerRow)) {
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
