<?php

namespace App\Http\Controllers;

use App\Models\ProductSetting;
use Faker\Provider\File;
use Illuminate\Http\Request;


class ExportImportProducts extends Controller
{
    public function exportProductsTemplate(Request $request){

        $productsChunk = ProductSetting::where('store_id', $request['store_id']);

        $comma = ",";
        $folderName = public_path().'/export_files/store-'.$request['store_id'].'-'.time();
        $this->makeDirectory($folderName, $mode = 0777, true, true);

        $productsChunk->chunk(3000, function($products, $chunkCount = 0) use ($comma, $folderName){
            $fileName = $chunkCount++.'-export.csv';
            $filename = $folderName.'/'.$fileName;
            $fp = fopen($filename, "w");
            if(true){
                $line = 'Product Id, Product Name, Product Cat, Product SKU, Product Weight, Product Height, Product Length, Product Width';
                $line .= "\n";
                fputs($fp, $line);
            }
            foreach ($products as $key => $product){
                $line = $product->id;
                $line .= $comma . $product->name.' dummy'.rand(0, 100000);
                $line .= $comma . 'Cat';
                $line .= $comma . $product->sku.rand(0, 100000);
                $line .= $comma . $product->weight;
                $line .= $comma . $product->height;
                $line .= $comma . $product->length;
                $line .= $comma . $product->width;
                $line .= "\n";
                fputs($fp, $line);
            }
        });
       // echo $line;
        exit;
        return response()->json(['error' => false,
            'data' => [],
            'message' => 'The import CSV template will be emailed to '.$request['email'],
        ], 200);
    }

    public function makeDirectory($path, $mode = 0777, $recursive = false, $force = false)
    {
        if ($force)
        {
            return @mkdir($path, $mode, $recursive);
        }
        else
        {
            return mkdir($path, $mode, $recursive);
        }
    }
}
