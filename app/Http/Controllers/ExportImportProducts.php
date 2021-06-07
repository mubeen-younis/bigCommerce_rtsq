<?php

namespace App\Http\Controllers;

use App\Models\ProductSetting;
use App\Models\ExportProducts as ExportProductsModel;
use App\Mail\ExportProducts as ExportProductsEmail;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\URL;
use ZipArchive;
use Illuminate\Filesystem\Filesystem;

class ExportImportProducts extends Controller
{
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
            $fileName = '/export_files/' . $request['store_hash'] . '/store-' . $request['store_id'] . '-' . time();
            $request['folderName'] = public_path() . $fileName;
            $hash = md5($request['store_id'] . time());
            $this->makeDirectory($request['folderName'], $mode = 0777, true, true);
            $request['exportProductsId'] = ExportProductsModel::insertGetId(['store_id' => $request['store_id'], 'foldername' => $fileName.'.zip', 'hash' => $hash, 'request_time' => time(), 'email' => $request['email'], 'status' => 0]);
        }
        $folderName = $request['folderName'];
        $folderNamePath = [];
        try {
            $productsChunk->chunk(3000, function ($products, $chunkCount = 0) use ($comma, $folderName) {
                $fileName = $chunkCount++ . '-export.csv';
                $filename = $folderName . '/' . $fileName;
                $folderNamePath[] = $filename;
                $fp = fopen($filename, "w");
                if (true) {
                    $line = 'Product Id, Product Name, Product Cat, Product SKU, Product Weight, Product Height, Product Length, Product Width';
                    $line .= "\n";
                    fputs($fp, $line);
                }
                foreach ($products as $key => $product) {
                    $line = $product->id;
                    $line .= $comma . $product->name . ' dummy' . rand(0, 100000);
                    $line .= $comma . 'Cat';
                    $line .= $comma . $product->sku . rand(0, 100000);
                    $line .= $comma . $product->weight;
                    $line .= $comma . $product->height;
                    $line .= $comma . $product->length;
                    $line .= $comma . $product->width;
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
            //dd($folderName, $files);
            $zipFileName = $folderName.'.zip';
            if ($zip->open($zipFileName, ZipArchive::CREATE) === TRUE) {
                // Add File in ZipArchive
                foreach($files as $file) {
                    $name = explode('/',$file);
                    $name = $name[count($name)-1];
                    $zip->addFile($file, $name);
                }
                // Close ZipArchive
                $zip->close();
            }
            File::deleteDirectory($folderName);
            return $zipFileName;
            // Set Header
           /* $headers = array(
                'Content-Type' => 'application/octet-stream',
            );
            $filetopath=$public_dir.'/'.$zipFileName;
            // Create Download Response
            if(file_exists($filetopath)){
                return response()->download($filetopath,$zipFileName,$headers);
            }*/
    }

    public function downloadCsv($hash){
        $status = ExportProductsModel::where('hash', $hash)->first();
        if(empty($status) || $status->status !== 1 || ($status->request_time <= time()-24*3600) ){
            echo "Download link has been expired";
        }else{
            $status->status = 2;
            $status->save();
            /*$headers = array(
                'Content-Type' => 'application/octet-stream',
            );*/
            $foldername = explode('/', $status->foldername);
            //$zipFileName = $foldername[count($foldername)-1];
           // unset($foldername[count($foldername)-1]);
            $foldername = implode('/', $foldername);
            $filetopath = asset('public'.$foldername);
            header('Location: '. $filetopath); exit;
            /*
            //$zipFileName = explode('/',asset($status->foldername) );
            // Create Download Response
            //if(file_exists($filetopath)){
                echo "test";
               return response()->download($filetopath,$zipFileName,$headers);
            //}
            dd($filetopath, $zipFileName);
            echo "Download is ready";
            */
        }
    }
}
