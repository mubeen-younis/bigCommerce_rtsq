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

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
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
                return response()->json(['error' => false,
                    'data' => [],
                    'message' => 'The import CSV template will be emailed to ' . $request['email'],
                ], 200);
            }
        } else {
            $this->createExportData($request);
        }
    }

    public function createExportData($request)
    {
        $locations = Locations::where('store_id', $request['store_id'])->where('type', 2)->get()->toArray();
        $storeHash = $request['store_hash'] ?? null;
        $weightUnit = $this->getWeightUnitOfStore($storeHash);
        $dropShips = [];
        foreach ($locations as $location) {
            $dropShips[$location['id']] = $location;
        }

        $productsChunk = ProductSetting::where('store_id', $request['store_id']);
        if (!$productsChunk->count()) {
            return [];
        }
        $comma = ",";
        if (!isset($request['rerunrequest'])) {
            $fileName = '/export_files/' . $request['store_hash'] . '/' . time();
            $request['folderName'] = public_path() . $fileName;
            $hash = md5($request['store_id'] . time());
            $this->makeDirectory($request['folderName'], $mode = 0777, true, true);
            $request['exportProductsId'] = ExportProductsModel::insertGetId(['store_id' => $request['store_id'], 'foldername' => $fileName . '.zip', 'hash' => $hash, 'request_time' => time(), 'email' => $request['email'], 'status' => 0]);
        }
        $folderName = $request['folderName'];
        $folderNamePath = [];
        try {
            $productsChunk->chunk(2500, function ($products, $chunkCount = 0) use ($comma, $folderName, $dropShips, $weightUnit) {
                $fileName = $chunkCount++ . '-export.csv';
                $filename = $folderName . '/' . $fileName;
                $folderNamePath[] = $filename;
                $fp = fopen($filename, "w");
                if (true) {
                    $line = 'Product Id, Variant Id, Product Name, Product SKU, Weight (' . $weightUnit . '), Length (in), Width (in), Height (in), Quote Method, Freight Class, Hazmat, Insurance, Dropship Nickname, Dropship ZIP Code, Dropship City, Dropship State, Dropship Country, Ships Alone, Vertical Rotation';
                    $line .= "\n";
                    fputs($fp, $line);
                }
                foreach ($products as $key => $product) {
                    $productLine = [];
                    $productLine[] = 'P' . $product->source_product_id;
                    $productLine[] = 'V' . $product->variant_id;
                    $productLine[] = $product->name ?? '';
                    $productLine[] = $product->sku ?? '';
                    $productLine[] = $product->weight ?? '';
                    $productLine[] = $product->length ?? '';
                    $productLine[] = $product->width ?? '';
                    $productLine[] = $product->height ?? '';

                    $settings = json_decode($product->settings);
                    $quoteMethod = '';
                    // Added INstore and local quoting methods
                    if (isset($settings->freight_enabled) && $settings->freight_enabled) {
                        $quoteMethod = 'L';
                    } else if (isset($settings->parcel_enabled) && $settings->parcel_enabled) {
                        $quoteMethod = 'S';
                    } else if (isset($settings->quote_as_instore) && $settings->quote_as_instore) {
                        $quoteMethod = 'IS';
                    } else if (isset($settings->quote_as_local) && $settings->quote_as_local) {
                        $quoteMethod = 'LD';
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
                    $productLine[] = $nickname;
                    $productLine[] = $zip;
                    $productLine[] = $city;
                    $productLine[] = $state;
                    $productLine[] = $country;
                    $productLine[] = isset($settings->ship_own_package) && $settings->ship_own_package ? 1 : 0;
                    $productLine[] = isset($settings->allow_vertical) && $settings->allow_vertical ? 1 : 0;
                    fputcsv($fp, $productLine);
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

    public function getWeightUnitOfStore($storeHash)
    {
        if (blank($storeHash)) {
            return 'lbs';
        }
        $storeDetails = BigCommerceFunctions::getStoreSettings($storeHash);
        $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
        $response = json_decode($storeDetails['response'], true);
        $weightUnit = $response['weight_units'] ?? null;
        if (!blank($weightUnit)) {
            return strtolower($weightUnit);
        }
        return 'lbs';
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
        foreach ($chunks as $key => $path) {
            $data['path'] = $path;
            $delay = ($key + 1) * 10;
            ImportProductsJob::dispatch($data)->delay(Carbon::now()->addSecond($delay));
            //$this->importProductCsvJob($request);
        }
        ImportProductsNotification::dispatch($data['importEmailAddress'])->delay(Carbon::now()->addSecond($delay + 10));
        // start running queue
        \Artisan::call('queue:work');
        return response()->json([
            'error' => false,
            'data' => $data,
            'delay' => $delay,
        ], 200);
    }

    public function importProductCsvJob($request)
    {
        $indexes = $request['indexes'];
        $store_id = $request['store_id'];
        $store = Store::where('id', $store_id)->first();
        $emailNotify = $request['importEmailAddress'] ?? '';
        $path = $request['path'];//public_path('import_files/'.$request['store_hash'].'/'.$request['filename']);
        $csv = array_map('str_getcsv', file($path));
        $headerRow = array_slice(range('A', 'Z'), 0, count($csv[0]));
        if ($request['firstHeader'] == "true") {
            $headerRow = $csv[0];
            unset($csv[0]);
        }
        array_walk($csv, function (&$a) use ($csv, $headerRow) {
            $a = array_combine(array_map('trim', $headerRow), array_map('trim', $a));
        });
        foreach ($csv as $key => $product) {
            $this->getUpdateData($product, $indexes, $store_id, $store->access_token, $request['store_hash']);
        }
        unlink($path);
    }

    function getUpdateData($product, $indexes, $store_id, $access_token, $hash)
    {
        $update = [];
        if (isset($indexes['id']) && $indexes['id'] && isset($indexes['variantid']) && $indexes['variantid']) {
            $key = $indexes['id'];
            $variant_key = $indexes['variantid'];
            //$source_product_id = (int) $product["$key"];
            $source_product_id = (int)filter_var($product["$key"], FILTER_SANITIZE_NUMBER_INT);

            $variant_id = (int)filter_var($product["$variant_key"], FILTER_SANITIZE_NUMBER_INT);
            /*if(!ProductSetting::where('source_product_id', $source_product_id)
                ->where('variant_id', $variant_id)
                ->where('store_id', $store_id)->exists()) {
                return true; // no action perform if product not exist
            }*/
            if ($variant_id) {
                $oldSettings = ProductSetting::where('source_product_id', $source_product_id)
                    ->where('variant_id', $variant_id)
                    ->where('store_id', $store_id)->pluck('settings')->toArray();
            } else {
                $oldSettings = ProductSetting::where('source_product_id', $source_product_id)
                    ->whereNull('variant_id')
                    ->where('store_id', $store_id)->pluck('settings')->toArray();
            }
            $update['settings'] = json_encode($this->getSettings($oldSettings, $product, $indexes, $store_id));
        }
        if (isset($indexes['name']) && $indexes['name']) {
            $key = $indexes['name'];
            $update['name'] = $product["$key"];
        }
        if (isset($indexes['weight']) && $indexes['weight']) {
            $key = $indexes['weight'];
            $data = (float)$product["$key"];
            if ($data >= 0) {
                $update['weight'] = (float)$product["$key"];
            }
        }
        if (isset($indexes['length']) && $indexes['length']) {
            $key = $indexes['length'];
            $data = (float)$product["$key"];
            if ($data >= 0) {
                $update['length'] = (float)$product["$key"];
            }
        }
        if (isset($indexes['width']) && $indexes['width']) {
            $key = $indexes['width'];
            $data = (float)$product["$key"];
            if ($data >= 0) {
                $update['width'] = (float)$product["$key"];
            }
        }
        if (isset($indexes['height']) && $indexes['height']) {
            $key = $indexes['height'];
            $data = (float)$product["$key"];
            if ($data >= 0) {
                $update['height'] = (float)$product["$key"];
            }
        }

        /*Start -  For Dropship CHange*/
        $dropShipId = $this->updateDropShip($product, $indexes, $store_id);
        if ($dropShipId != false) {
            $update['dropship_enabled'] = true;
            $update['dropship_location'] = $dropShipId;
        } else {
            $update['dropship_enabled'] = false;
            $update['dropship_location'] = null;
        }
        // END //

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
        $settings = $oldSettings[0] ? json_decode($oldSettings[0]) : new \stdClass();
        /*$freightUpdate = false;
        if(isset($indexes['freight_enabled']) && $indexes['freight_enabled']){
            $key = $indexes['freight_enabled'];
            if(array_key_exists($key, $product)){
                $settings->freight_enabled = (bool) $product["$key"];
                $freightUpdate = true;
            }
        }
        if(isset($indexes['parcel_enabled']) && $indexes['parcel_enabled']){
            $key = $indexes['parcel_enabled'];
            if(array_key_exists($key, $product)){
                $settings->parcel_enabled = (bool) $product["$key"];;
            }
            if(isset($settings->parcel_enabled) && $settings->parcel_enabled === true && isset($settings->freight_enabled) && $settings->freight_enabled === true) {
                $settings->parcel_enabled = false;
            }
            $freightUpdate = false;
        }
        if($freightUpdate && isset($settings->parcel_enabled) && $settings->parcel_enabled === true){
            $settings->freight_enabled = false;
        }*/
        if (isset($indexes['quote_method']) && $indexes['quote_method']) {
            $key = $indexes['quote_method'];
            $quoteMethod = strtolower($product["$key"]);
            if (array_key_exists($key, $product)) {
                $settings->parcel_enabled = false;
                $settings->freight_enabled = false;
                $settings->quote_as_instore = false;
                $settings->quote_as_local = false;
                // Added instore and local delivery quoting method here as well Instore-local

                if ($quoteMethod === 's') {
                    $settings->parcel_enabled = true;
                } else if ($quoteMethod === 'l') {
                    $settings->freight_enabled = true;
                } else if ($quoteMethod === 'is') {
                    $settings->quote_as_instore = true;
                } else if ($quoteMethod === 'ld') {
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
        if (isset($indexes['ship_alone']) && $indexes['ship_alone']) {
            $key = $indexes['ship_alone'];
            if (array_key_exists($key, $product)) {
                $settings->ship_own_package = ($product["$key"] == 1) ? true : false;
            }
        }
        if (isset($indexes['vertical_rotation']) && $indexes['vertical_rotation']) {
            $key = $indexes['vertical_rotation'];
            if (array_key_exists($key, $product)) {
                $settings->allow_vertical = ($product["$key"] == 1) ? true : false;;
            }
        }

        $allowVert = optional($settings)->allow_vertical ?? false;
        $shipOwn = optional($settings)->ship_own_package ?? false;
        if ($allowVert && $shipOwn) {
            $settings->ship_own_package = false;
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


        /*
         * Commented Dropship code*/

        /*   $dropShipId = $this->updateDropShip($oldSettings, $product, $indexes, $store_id);
           $settings->dropship_enabled = false;
           $settings->dropship_location = false;
           if ($dropShipId) {
               $settings->dropship_enabled = true;
               $settings->dropship_location = $dropShipId;
           }*/


        // END ////


        /*if(isset($indexes['dropship_enabled']) && $indexes['dropship_enabled']){
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
        }*/
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
