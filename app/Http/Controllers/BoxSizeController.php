<?php

namespace App\Http\Controllers;

use App\Models\BoxSize;
use App\Models\MultiplePackagingBoxes;
use App\Models\ProductSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BoxSizeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //$boxes = BoxSize::get();
        $boxes = [];
        foreach (BoxSize::get() as $key => $box){

            $boxes[$key] = $box;
            $boxes[$key]['availability'] = $box['is_available'] ? 'Yes' : 'No';

        }
        return response()->json(['error' => false, 'data' => $boxes]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $rules = [
            'nickname' => 'required|unique:box_sizes',
            'length' => 'required',
            'width' => 'required',
            'height' => 'required',
            'max_weight' => 'required',
            'box_weight' => 'required',
            'is_available' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['error' => true, 'message' => $validator->errors()], 200);
        }

        $data = $request->except(['store_name', 'store_hash']);

        $boxsize = BoxSize::create($data);
        $boxsize->save();
        $boxsize->is_available = $boxsize->is_available === true ? 1:0;
        $boxsize->availability = $boxsize->is_available ===1 ? 'Yes' : 'No';
        return response()->json(
            [
                'error' => false,
                'message' => "Box added successfully.",
                'data' => $boxsize,
            ], 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function show(BoxSize $boxSize)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function edit(BoxSize $boxSize)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, BoxSize $boxSize)
    {
        if (!$request->id || empty($request->id)) {
            return response()->json([
                'error' => true,
                'message' => "Box Size Id is empty!",
            ], 200);
        }

        $box_size = BoxSize::find($request->id);

        if ($box_size) {
            if(BoxSize::where('nickname', $request->nickname)->where('store_id', $request->store_id)->where('id','!=',$request->id)->exists()){
                return response()->json([
                        'error' => true,
                        'message' => "The nickname has already been taken."
                    ]);
            }
            $data = $request->except(['store_name', 'store_hash']);

            $boxsize = BoxSize::where('id', $request->id)->update($data);
            $box = BoxSize::find($request->id);
            $box['availability'] = $box['is_available']? 'Yes':'No';
            return response()->json(
                [
                    'error' => false,
                    'message' => "Box updated successfully.",
                    'data' => $box,//BoxSize::find($request->id),
                ], 200);
        }

        return response()->json([
            'error' => true,
            'message' => 'Box could not be updated successfully.',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $boxsize = BoxSize::find($id);
        $boxsize->delete();

        return response()->json(['error' => false,
            'message' => "Box deleted Successfully",
            'data' => $id]);
    }

    public function getMultiplePackagingBoxes(Request $request){
        $storeId = $request['store_id'];
        $products = ProductSetting::select('product_settings.id','product_settings.name', 'product_settings.sku')
            ->where('product_settings.ship_multiple_package', 1)
            ->where('product_settings.store_id', $storeId)->get()->toArray();
        if(!empty($products)) {
            foreach ($products as $key => $product) {
                $multiplePackages = $this->getBoxesByProductId($product['id']);
                $products[$key]['boxes'] = $multiplePackages ?? [];
            }
        }
        return response()->json([
            'error' => false,
            'data' => $products,
            'message' => 'Box added succesfully.'
        ]);
    }

    public function getBoxesByProductId($productId){
        return MultiplePackagingBoxes::where('product_id', $productId)
            ->where('status', 1)
            ->get()->toArray();
    }

    public function addMultiplePackagingBox(Request $request){
        $data = $request->except(['store_name', 'store_hash', 'store_id']);
        if(MultiplePackagingBoxes::create($data)){
            return $this->getMultiplePackagingBoxes($request);
        }else{
            return response()->json([
                'error' => true,
                'message' => "Box could not be added."
            ]);
        }
    }

    public function deleteMultiplePackagingBox(Request $request){
        $id = $request->id;
        if(MultiplePackagingBoxes::find($id)->delete()){
            return $this->getMultiplePackagingBoxes($request);
        }else{
            return response()->json([
                'error' => true,
                'message' => "Box could not be deleted."
            ]);
        }
    }

    public function updateMultiplePackagingBox(Request $request){
        $id = $request->id;
        $update = $request->except(['store_name', 'store_hash', 'store_id', 'id']);
        if(MultiplePackagingBoxes::find($id)->update($update)){
            return $this->getMultiplePackagingBoxes($request);
        }else{
            return response()->json([
                'error' => true,
                'message' => "Box could not be updated."
            ]);
        }
    }
}
