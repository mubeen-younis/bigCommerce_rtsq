<?php

namespace App\Http\Controllers;

use App\CustomClasses\BigCommerceFunctions;
use App\Jobs\ImportProducts as ImportProductsJob;
use App\Jobs\UpdateBCProductsJob;
use App\Jobs\ExportProductsFromBCStore;
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
use App\Http\Controllers\GetRatesController as ProductSettings;
use App\Http\Controllers\GetRatesController;
use App\Models\CSVimportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ExportImportProducts extends Controller
{
    public $curlRequest;
    public $csvChunksLength;
    public $mainController;
    public $productSetting;
    public $fileSize;
    public $totalChunks;
    public $BCProductsBatches;
    public $processedChunks;
    public $errorProducts;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->csvChunksLength = 250;
        $this->batchLength = 50;
        $this->mainController = new MainController();
        $this->productSetting = new ProductSettingController();
        $this->fileSize = 0;
        $this->totalChunks = 0;
        $this->BCProductsBatches = [];
        $this->errorProducts = null;
        $this->processedChunks = null;
    }

    public function exportProductsTemplate(Request $request)
    {
        // return back due to store plan expired
        $GetRatesController = new GetRatesController();
        if (!$GetRatesController->storePlanStatus($request['store_id'])) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Your current plan has expired. Please renew your plan.',
            ], 200);
        }
        $request['store_token'] = $this->mainController->getCustAccessTok($request['store_id']);
        $request['perpage'] = 2000;
        $totalpages = $this->productSetting->importProductsGetPages($request);

        if (isset($request['onlyResponse']) && $request['onlyResponse'] === true) {
            if ($totalpages < 1) {
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

            if ($totalpages < 1) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Products not available for import template',
                ], 200);
            }
            if (Functions::isEnabledLogs($request['store_hash'])) {
                Log::info('CSV Export Products Job Start.');
            }
            $data = $request->all() ?? [];
            ExportProductsFromBCStore::dispatch($data);

            $CSVDownloadLink = $this->createCSVDownloadLink($request);

            if (isset($CSVDownloadLink['status']) && $CSVDownloadLink['status']) {
                return response()->json([
                    "error" => false,
                    'message' => $CSVDownloadLink['message'],
                    'data' => $CSVDownloadLink['data'],
                ], 200);
            }
        }
    }

    public function createExportData($request)
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', '0');
        $locations = Locations::where('store_id', $request['store_id'])->where('type', 2)->get()->toArray();
        $storeHash = $request['store_hash'] ?? null;
        $weightDimensionUnits = $this->getweightDimensionUnits($storeHash);
        $weightUnit = isset($weightDimensionUnits['weight_units']) && !blank($weightDimensionUnits['weight_units']) ? strtolower($weightDimensionUnits['weight_units']) : 'lbs' ?? 'lbs';
        $dimensionsUnit = isset($weightDimensionUnits['dimension_units']) && $weightDimensionUnits['dimension_units'] === 'Centimeters' ? 'cm' : 'in' ?? 'in';

        $dropShips = [];
        foreach ($locations as $location) {
            $dropShips[$location['id']] = $location;
        }

        $totalpages = $this->productSetting->importProductsGetPages($request);
        $headers = BigCommerceFunctions::getHeaders($request['store_hash']);

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
            $this->chunkCount = 0;
            if (Functions::isEnabledLogs($request['store_hash'])) {
                Log::info('CSV Export Products Job Inprogress.');
            }

            for ($page = 1; $page <= $totalpages; $page++) {

                $this->products = [];
                $endpoint = BigCommerceFunctions::$initalUrl . $request['store_hash'] . "/v3/catalog/products?limit=" . $request['perpage'] . "&page=" . $page;
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);

                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    $response = json_decode($response['response'], true);
                    $this->products = collect($response['data']);
                }

                if (!$this->products->count()) {
                    return [];
                }

                $comma = ",";

                $ProductSettings = new ProductSettings();
                foreach ($this->products as $key => $product) {
                    if ($this->fileSize < 1) {
                        $fp = $this->setCSVfileSize($folderName, $weightUnit, $dimensionsUnit);
                    }
                    // Check: if product variant id is null then the null variant id product will not add in CSV file.
                    if ($product['base_variant_id'] == null) {
                        $variantEndPoint = BigCommerceFunctions::$initalUrl . $request['store_hash'] . '/v3/catalog/products/' . $product['id'] . '/variants?limit=250';
                        $response = $this->curlRequest->enSingleCurlRequest($variantEndPoint, [], $headers, 'GET', false);
                        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                            $response = json_decode($response['response'], true);

                            if (empty($response['data'])) {
                                Log::info('empty response from BC .' . json_encode($response));
                                sleep(30);
                                Log::info('recalled bc request again .');
                                $response = $this->curlRequest->enSingleCurlRequest($variantEndPoint, [], $headers, 'GET', false);
                                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                                    $response = json_decode($response['response'], true);
                                }
                                Log::info('logged request which was empty .' . json_encode($response));
                                //continue;
                            }

                            $productsVar = collect($response['data'] ?? []);

                            if (empty($productsVar)) {
                                continue;
                            }


                            foreach ($productsVar as $variant) {
                                if ($this->fileSize < 1) {
                                    $fp = $this->setCSVfileSize($folderName, $weightUnit, $dimensionsUnit);
                                }
                                $this->fileSize--;
                                $variant['base_variant_id'] = $variant['id'];
                                $variant['id'] = $product['id'];
                                $variant['name'] = $product['name'];
                                $DBProductSettings = $ProductSettings->getProductSetting($variant['id'], $variant['base_variant_id'], $request['store_id']);
                                $productLine = $this->createDataSet($variant, $DBProductSettings, $dropShips);
                                fputcsv($fp, $productLine);
                            }

                        }
                    } else {
                        $this->fileSize--;
                        $DBProductSettings = $ProductSettings->getProductSetting($product['id'], $product['base_variant_id'], $request['store_id']);
                        $productLine = $this->createDataSet($product, $DBProductSettings, $dropShips);
                        fputcsv($fp, $productLine);
                    }
                }
            }

            $isupdate = ExportProductsModel::find($request['exportProductsId'])->update(['status' => 1]);
            $this->makeZipWithFiles($folderName);
            $this->sendEmail($request['email'], $hash);
            if (Functions::isEnabledLogs($request['store_hash'])) {
                Log::info('CSV Export Products Job Ended.');
            }

        } catch (\Exception $exception) {

            if (Functions::isEnabledLogs($request['store_hash'])) {
                Log::info(json_encode([
                    'line' => $exception->getLine(),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                ]));
            }

            return response()->json([
                'error' => true,
                'message' => [
                    'line' => $exception->getLine(),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                ]
            ], 200);
        }
    }

    public function setCSVfileSize($folderName, $weightUnit, $dimensionsUnit)
    {
        $fileName = $this->chunkCount++ . '-export.csv';
        $filename = $folderName . '/' . $fileName;
        $folderNamePath[] = $filename;
        $fp = fopen($filename, "w");
        if (true) {
            $line = 'Product Id, Variant Id, Product Name, Product SKU, Weight (' . $weightUnit . '), Length (' . $dimensionsUnit . '), Width (' . $dimensionsUnit . '), Height (' . $dimensionsUnit . '), NMFC, Markup, Quote Method, Freight Class, Hazmat, Insurance, Dropship Nickname, Dropship ZIP Code, Dropship City, Dropship State, Dropship Country, Boxing Properties, Ships Own Pallet, Pallet Vertical Rotation';
            $line .= "\n";
            
            fputs($fp, $line);
        }
        // Set minimium file size
        $this->fileSize = 2500;

        return $fp;
    }

    public function createDataSet($product, $DBProductSettings, $dropShips)
    {
        $productLine = [];
        $productLine[] = 'P' . $product['id'];
        $productLine[] = 'V' . $product['base_variant_id'];
        $productLine[] = $product['name'] ?? '';
        $productLine[] = $product['sku'] ?? '';
        $productLine[] = $product['weight'] ?? '';
        $productLine[] = $product['depth'] ?? '';
        $productLine[] = $product['width'] ?? '';
        $productLine[] = $product['height'] ?? '';
        $productLine[] = $DBProductSettings['nmfc'] ?? '';
        $productLine[] = $DBProductSettings['product_markup'] ?? '';

        $quoteMethod = '';
        // Added INstore and local quoting methods
        if (isset($DBProductSettings['freight_enabled']) && $DBProductSettings['freight_enabled']) {
            $quoteMethod = 'L';
        } else if (isset($DBProductSettings['parcel_enabled']) && $DBProductSettings['parcel_enabled']) {
            $quoteMethod = 'S';
        } else if (isset($DBProductSettings['quote_as_local']) && $DBProductSettings['quote_as_local']) {
            $quoteMethod = 'PD';
        }

        $productLine[] = $quoteMethod ?? '';
        $productLine[] = $DBProductSettings['freight_class'] ?? '';
        $productLine[] = isset($DBProductSettings['hazardous_enabled']) && $DBProductSettings['hazardous_enabled'] ? 1 : 0;
        $productLine[] = isset($DBProductSettings['insurance']) && $DBProductSettings['insurance'] ? 1 :
            $nickname = '';
        $zip = '';
        $city = '';
        $state = '';
        $country = '';

        if (isset($DBProductSettings['dropship_enabled']) && $DBProductSettings['dropship_enabled']) {
            $location = $DBProductSettings['dropship_location'] ?? false;
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
        if (isset($DBProductSettings['ship_own_package']) && $DBProductSettings['ship_own_package']) {
            $boxingProperty = '1';
        } else if (isset($DBProductSettings['allow_vertical']) && $DBProductSettings['allow_vertical']) {
            $boxingProperty = '2';
        } else if (isset($DBProductSettings['ship_multiple_package']) && $DBProductSettings['ship_multiple_package']) {
            $boxingProperty = '3';
        } else if (isset($DBProductSettings['ship_multiple_package']) && !$DBProductSettings['ship_multiple_package'] &&
            isset($DBProductSettings['ship_own_package']) && !$DBProductSettings['ship_own_package'] &&
            isset($DBProductSettings['allow_vertical']) && !$DBProductSettings['allow_vertical']) {
            $boxingProperty = '0';
        }

        $productLine[] = $nickname ?? '';
        $productLine[] = $zip ?? '';
        $productLine[] = $city ?? '';
        $productLine[] = $state ?? '';
        $productLine[] = $country ?? '';
        $productLine[] = $boxingProperty ?? '';
        $productLine[] = isset($DBProductSettings['own_pallet']) && $DBProductSettings['own_pallet'] ? 1 : 0;
        $productLine[] = isset($DBProductSettings['pallet_vertical_rotation']) && $DBProductSettings['pallet_vertical_rotation'] ? 1 : 0;

        return $productLine;
    }

    // Create CSV export download link for display on the dashboard of the app
    public function createCSVDownloadLink($request)
    {
        $status = ExportProductsModel::where('store_id', $request['store_id'])->latest()->first() ?? [];

        if (empty($status) || $status->status == 0 || $status->is_link_invisible == 1 || ($status->request_time <= time() - 24 * 3600)) {
            return [
                "status" => false,
                'message' => 'Download link has been expired',
                'data' => ''
            ];
        }

        if (!empty($status)) {
            $url = URL::to('api/downloadcsv/' . $status['hash']);
            $message = "The export CSV template has been finished.";

            return [
                "status" => true,
                'message' => $message,
                'data' => $url
            ];
        }
    }

    public function getCSVDownloadLink(Request $request)
    {
        $status = ExportProductsModel::where('store_id', $request['store_id'])->latest()->first() ?? [];

        if (isset($request['is_link_invisible']) && $request['is_link_invisible'] == 'true') {
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

        if (!empty($status)) {
            $url = URL::to('api/downloadcsv/' . $status['hash']);
            $message = "The export CSV template has been finished.";
            return response()->json([
                "error" => false,
                'message' => $message,
                'data' => $url,
            ], 200);
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

    public function ImportNotifyEmail($email, $data)
    {
        Mail::to($email)->send(new ImportProductsEmail($data));
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

            // return back due to store plan expired
            $GetRatesController = new GetRatesController();
            if (!$GetRatesController->storePlanStatus($request['store_id'])) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Your current plan has expired. Please renew your plan.',
                ], 200);
            }

            $data['filename'] = $request['filename'];
            $data['firstHeader'] = $request['firstHeader'];
            $data['importEmailAddress'] = $request['importEmailAddress'];
            $data['indexes'] = $request['indexes'];
            $data['store_hash'] = $request['store_hash'];
            $data['store_id'] = $request['store_id'];
            $data['store_name'] = $request['store_name'];
            $data['path'] = public_path('import_files/' . $request['store_hash'] . '/' . $request['filename']);

            $this->importProductCsvProcess($data);

            return response()->json([
                'error' => false,
                'data' => $data,
            ], 200);

        } catch (\Exception $exception) {

            $this->createImportCsvStatusInDB($request, $exception);

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

    public function importProductCsvProcess($request)
    {
        try {
            $indexes = $request['indexes'];
            $store_id = $request['store_id'];
            $store = Store::where('id', $store_id)->first();
            $emailNotify = $request['importEmailAddress'] ?? '';
            $path = $request['path'];
            $delay = 1;

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
            $request['CSV_count'] = count($csvArray);
            $request['csv_chunk_count'] = count($csvChunks) ?? 0;

            $CSVimportPrdModel = new CSVimportExport();
            $CSVimportPrdModel->store_id = $request['store_id'];
            $CSVimportPrdModel->file_name = $request['filename'];
            $CSVimportPrdModel->total_rows = $request['CSV_count'];
            $CSVimportPrdModel->save();
            $request['CSVinsertedId'] = $CSVimportPrdModel->id;

            // Initialize the counter and dispatch jobs
            Cache::put('chunks_processed', 0, now()->addHours(2));

            foreach ($csvChunks as $chunk) {
                // Dispatch a job for each chunk
                ImportProductsJob::dispatch($chunk, $request, $headerRow)->delay(Carbon::now()->addSeconds($delay++));
            }

        } catch (\Exception $exception) {
            $this->createImportCsvStatusInDB($request, $exception);

            Log::info(json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
            ]));
        }

    }

    public function createImportCsvStatusInDB($request, $exception)
    {
        $CSVimportPrdModel = new CSVimportExport();
        $CSVimportPrdModel->store_id = $request['store_id'];
        $CSVimportPrdModel->file_name = $request['filename'];
        $CSVimportPrdModel->error_at_rows = json_encode([
            'line' => $exception->getLine(),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
        ]);
        $CSVimportPrdModel->status = 3;
        $CSVimportPrdModel->save();
    }

    public function importProductCsvJob($chunk, $request, $headerRow)
    {
        try {

            $indexes = $request['indexes'];
            $store_id = $request['store_id'];
            $store = Store::where('id', $store_id)->first();
            $emailNotify = $request['importEmailAddress'] ?? '';
            $path = $request['path'];
            $this->BCProductsBatches = $this->Batches = [];
            $data = [];
            $this->count = 0;
            $exceptionProducts = [];
            $delay = 1;
            $this->totalChunks = $request['csv_chunk_count'];

            foreach ($chunk as $key => $product) {
                try {
                    $data[] = $this->getUpdateData($product, $indexes, $store_id, $store->access_token, $request['store_hash']);

                } catch (\Exception $exception) {
                    if (isset($product['Product Id']) && isset($product['Variant Id'])) {
                        $exceptionProducts[] = $this->formatError($product, $exception);
                    }

                    if (Functions::isEnabledLogs($request['store_hash'])) {
                        Log::info('CSV Products Exception Array: ' . json_encode($exceptionProducts));
                        Log::info(json_encode([
                            'line' => $exception->getLine(),
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                        ]));
                    }
                }
            }

            foreach ($data as $record) {
                unset($record['updated_at']);
                if (!empty($record) && !empty($record['variant_id'])) {
                    $this->createBCProductsUpdateBatches($record);
                }
            }
            // if $this->Batches array is less then batch size records
            $this->BCProductsBatches[] = $this->Batches;

            // Optionally, handle successful API calls
            if (!empty($this->BCProductsBatches)) {
                UpdateBCProductsJob::dispatch($this->BCProductsBatches, $request, $exceptionProducts)->delay(Carbon::now()->addSeconds($delay++));
            }

        } catch (\Exception $exception) {
            CSVimportExport::where('id', $request['CSVinsertedId'])->update([
                'total_rows' => $request['CSV_count'],
                'error_at_rows' => json_encode([
                    'line' => $exception->getLine(),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                ]),
                'status' => 3,
            ]);
            Log::info(json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
            ]));
        }

    }

    public function importBCProductCsvJob($batches, $request, $exceptionProducts)
    {
        $this->updateBCProductBatches($batches, $request, $exceptionProducts);
    }

    protected function formatError($product, $exception)
    {
        return 'Product ' . $product['Product Id'] . ' : ' . $product['Variant Id'] . ' => ' . $exception->getMessage();
    }

    public function createBCProductsUpdateBatches($product)
    {
        if (count($this->Batches) == $this->batchLength) {
            $this->BCProductsBatches[] = $this->Batches;
            $this->Batches = [];
            $this->count++;
        }

        $this->Batches[] = [
            "id" => $product['variant_id'],
        ];

        if (!empty($product['weight'])) {
            $this->Batches[count($this->Batches) - 1]["weight"] = $product['weight'];
        }
        if (!empty($product['length'])) {
            $this->Batches[count($this->Batches) - 1]["depth"] = $product['length'];
        }
        if (!empty($product['width'])) {
            $this->Batches[count($this->Batches) - 1]["width"] = $product['width'];
        }
        if (!empty($product['height'])) {
            $this->Batches[count($this->Batches) - 1]["height"] = $product['height'];
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
            if (is_numeric($data) || empty($data)) {
                $update['weight'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['length']) && $indexes['length']) {
            $key = $indexes['length'];
            $data = $product["$key"];
            if (is_numeric($data) || empty($data)) {
                $update['length'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['width']) && $indexes['width']) {
            $key = $indexes['width'];
            $data = $product["$key"];
            if (is_numeric($data) || empty($data)) {
                $update['width'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['height']) && $indexes['height']) {
            $key = $indexes['height'];
            $data = $product["$key"];
            if (is_numeric($data) || empty($data)) {
                $update['height'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['nmfc']) && $indexes['nmfc']) {
            $key = $indexes['nmfc'];
            $data = $product["$key"];
            if (is_numeric($data) || empty($data)) {
                $update['nmfc'] = $data != '' ? round($data, 2) : '';
            }
        }
        if (isset($indexes['product_markup']) && $indexes['product_markup']) {
            $key = $indexes['product_markup'];
            $data = $product["$key"];
            if (is_numeric($data) || empty($data)) {
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

        if ($shipMultiPackage) {
            $update['ship_multiple_package'] = true;
        } else if ($shipMultiPackage === null) {
            $update['ship_multiple_package'] = null;
        } else {
            $update['ship_multiple_package'] = false;
        }

        /*Start -  For Dropship CHange*/
        if (isset($indexes['drop_ship_nickname']) && $indexes['drop_ship_nickname']
            && isset($indexes['drop_ship_city']) && $indexes['drop_ship_city']
            && isset($indexes['drop_ship_state']) && $indexes['drop_ship_state']
            && isset($indexes['drop_ship_zip']) && $indexes['drop_ship_zip']
            && isset($indexes['drop_ship_country']) && $indexes['drop_ship_country']) {
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

        if (!empty($update)) {

            $recordExists = ProductSetting::where('source_product_id', $source_product_id)
                ->where('variant_id', $variant_id)
                ->where('store_id', $store_id)->exists();

            if ($recordExists) {
                ProductSetting::where('source_product_id', $source_product_id)
                    ->where('variant_id', $variant_id)
                    ->where('store_id', $store_id)->update($update);
            } else {
                $settings = $this->getSettings([], $product, $indexes, $store_id);
                $createProduct = new ProductSetting();
                $createProduct->source_product_id = $source_product_id;
                $createProduct->variant_id = $variant_id;
                $createProduct->name = isset($update['name']) ? $update['name'] : '' ?? '';
                $createProduct->sku = isset($update['sku']) ? $update['sku'] : null ?? null;
                $createProduct->weight = isset($update['weight']) ? $update['weight'] : null ?? null;
                $createProduct->length = isset($update['length']) ? $update['length'] : null ?? null;
                $createProduct->width = isset($update['width']) ? $update['width'] : null ?? null;
                $createProduct->height = isset($update['height']) ? $update['height'] : null ?? null;
                $createProduct->nmfc = isset($update['nmfc']) ? $update['nmfc'] : null ?? null;
                $createProduct->product_markup = isset($update['product_markup']) ? $update['product_markup'] : null ?? null;
                $createProduct->own_pallet = isset($update['own_pallet']) ? $update['own_pallet'] : null ?? null;
                $createProduct->pallet_vertical_rotation = isset($update['pallet_vertical_rotation']) ? $update['pallet_vertical_rotation'] : null ?? null;
                $createProduct->settings = json_encode($settings);
                $createProduct->ship_multiple_package = isset($update['ship_multiple_package']) ? $update['ship_multiple_package'] : '{}' ?? '{}';
                $createProduct->store_id = $store_id;
                $createProduct->dropship_enabled = isset($update['dropship_enabled']) ? $update['dropship_enabled'] : null ?? null;
                $createProduct->dropship_location = isset($update['dropship_location']) ? $update['dropship_location'] : null ?? null;
                $createProduct->brand_id = isset($update['brand_id']) && !empty($update['brand_id']) ? $update['brand_id'] : null ?? null;
                $createProduct->categories_id = isset($update['categories']) && !empty($update['categories']) ? json_encode($update['categories']) : null ?? null;
                $createProduct->save();
            }
            $update['variant_id'] = $variant_id;
        }
        return $update;
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

                if (Functions::isEnabledLogs("", $store_id)) {
                    Log::info('Import Products Dropship: ' . $dropShipId . " " . json_encode($location));
                }
            }
        }
        return $dropShipId;
    }

    public function updateBCProductBatches($batches, $request, $exceptionProducts)
    {
        unset($headers);
        $headers[] = 'X-Auth-Token: ' . $this->mainController->getCustAccessTok($request['store_id']);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = BigCommerceFunctions::$initalUrl . $request['store_hash'] . "/v3/catalog/variants";

        foreach ($batches as $batch) {
            try {
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($batch), $headers, 'PUT');
                $response = json_decode($response['response'], true);
                if (!empty($response['status']) || !empty($response['errors']['status'])) {
                    if (Functions::isEnabledLogs($request['store_hash'])) {
                        Log::info(json_encode([
                            'message' => 'CSV batch update BC Failed.',
                            'response' => $response,
                            'batch' => $batch,
                        ]));
                    }
                }
            } catch (\Exception $exception) {

                if (Functions::isEnabledLogs($request['store_hash'])) {
                    Log::info('CSV batch update BC Exception Array: ' . json_encode($batch));
                    Log::info(json_encode([
                        'line' => $exception->getLine(),
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                    ]));
                }
            }

        }

        $maxRetries = 3; // Retry 3 times if lock is not acquired
        $retries = 0;

        while ($retries < $maxRetries) {
            $lock = Cache::lock('chunks_processed_lock', 5);

            if ($lock->get()) {

                try {

                    $processedChunks = Cache::increment('chunks_processed');

                    if ($processedChunks >= $request['csv_chunk_count']) {

                        CSVimportExport::where('id', $request['CSVinsertedId'])->update([
                            'error_at_rows' => json_encode($exceptionProducts),
                            'status' => count($exceptionProducts) == $request['CSV_count'] ? 3 : (empty($exceptionProducts) ? 1 : 2),
                        ]);

                        $this->ImportNotifyEmail($request['importEmailAddress'], $exceptionProducts);
                        if (Functions::isEnabledLogs($request['store_hash'])) {
                            Log::info('CSV Import Poducts Email Send.');
                            Log::info('ended import products process');
                        }
                        Cache::forget('chunks_processed');
                    }

                    break;

                } finally {
                    $lock->release();
                }
                break; // Exit loop if increment was successful
            } else {
                // Wait briefly before retrying to acquire the lock
                usleep(100000); // Wait for 0.1 seconds
                $retries++;
            }
        }
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
