<?php

namespace App\Http\Controllers;

use App\Models\BoxSize;
use App\Models\MultiplePackagingBoxes;
use App\Models\ProductSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Helpers\Helpers;
use Illuminate\Validation\Rule;

class BoxSizeController extends Controller
{
    /**
     * Display a listing of the resource..
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        //$boxes = BoxSize::get();
        $boxes = [];
        foreach (BoxSize::where('store_id', $request['store_id'])->get() as $key => $box) {

            $boxes[$key] = $box;
            $boxes[$key]['availability'] = $box['is_available'] ? 'Yes' : 'No';
            $boxes[$key]['heightWithPallet'] = $box['height'] + $box['ext_height'];
            $boxes[$key]['weightWithPallet'] = $box['max_weight'] + $box['box_weight'];
        }
        return response()->json(['error' => false, 'data' => $boxes]);
    }
    // get boxes for fdo
    public function getParcelBoxSizes(Request $request)
    {
        $boxes = optional(BoxSize::where('store_id', $request['store_id'])->where('box_type', '!=', 4)->get())->toArray() ?? [];
        if (!empty($boxes)) {
            return Helpers::sendJsonResponseFdo(false, '', $boxes);
        }
        return Helpers::sendJsonResponseFdo(true, 'Box Sizes not found', []);
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
            'nickname' => [
                'required',
                Rule::unique('box_sizes')->where(function ($query) use ($request) {
                    return $query->where('store_id', $request->store_id);
                }),
            ],
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

        $data = $request->except(['store_name', 'store_hash', 'is_test_store', 'heightWithPallet', 'weightWithPallet']);
        if (isset($data['filter_products'])) {
            $data['box_associated_to'] = json_encode($data['filter_products']);
            unset($data['filter_products']);
        }
        if (isset($data['filter_brands'])) {
            $data['box_associated_to'] = json_encode($data['filter_brands']);
            unset($data['filter_brands']);
        }
        if (isset($data['filter_categories'])) {
            $data['box_associated_to'] = json_encode($data['filter_categories']);
            unset($data['filter_categories']);
        }
        $isPalletBox = isset($request->box_name) && $request->box_name == 'Pallet Box' ? true : false;

        $boxsize = BoxSize::create($data);
        $boxsize->save();
        $boxsize->is_available = $boxsize->is_available === true ? 1 : 0;
        $boxsize->availability = $boxsize->is_available === 1 ? 'Yes' : 'No';
        $boxsize->heightWithPallet = $request->height + $request->ext_height;
        $boxsize->weightWithPallet = $request->max_weight + $request->box_weight;
        return response()->json(
            [
                'error' => false,
                'message' => ($isPalletBox ? 'Pallet' : 'Box') . " added successfully.",
                'data' => $boxsize,
            ],
            200
        );
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
        $isPalletBox = isset($request->box_name) && $request->box_name == 'Pallet Box' ? true : false;

        if ($box_size) {
            if (BoxSize::where('nickname', $request->nickname)->where('store_id', $request->store_id)->where('id', '!=', $request->id)->exists()) {
                return response()->json([
                    'error' => true,
                    'message' => "The nickname has already been taken."
                ]);
            }
            $data = $request->except(['store_name', 'store_hash', 'is_test_store', 'heightWithPallet', 'weightWithPallet']);

            if ($data['box_type'] != 4) {
                if (isset($data['filter_products'])) {
                    $data['box_associated_to'] = json_encode($data['filter_products']);
                    unset($data['filter_products']);
                }
                if (isset($data['filter_brands'])) {
                    $data['box_associated_to'] = json_encode($data['filter_brands']);
                    unset($data['filter_brands']);
                }
                if (isset($data['filter_categories'])) {
                    $data['box_associated_to'] = json_encode($data['filter_categories']);
                    unset($data['filter_categories']);
                }

                if (isset($data['availability_type']) && $data['availability_type'] == 1) {
                    $data['box_associated_to'] = null;
                    $data['apply_rule_to'] = null;
                }
            }

            $boxsize = BoxSize::where('id', $request->id)->update($data);
            $box = BoxSize::find($request->id);
            $box['availability'] = $box['is_available'] ? 'Yes' : 'No';
            $box->heightWithPallet = $request->height + $request->ext_height;
            $box->weightWithPallet = $request->max_weight + $request->box_weight;
            return response()->json(
                [
                    'error' => false,
                    'message' => ($isPalletBox ? 'Pallet' : 'Box') . " updated successfully.",
                    'data' => $box, //BoxSize::find($request->id),
                ],
                200
            );
        }

        return response()->json([
            'error' => true,
            'message' => ($isPalletBox ? 'Pallet' : 'Box') . ' could not be updated successfully.',
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
        $isPalletBox = isset($boxsize->box_name) && $boxsize->box_name == 'Pallet Box' ? true : false;
        $boxsize->delete();

        return response()->json([
            'error' => false,
            'message' => ($isPalletBox ? 'Pallet' : 'Box') . " deleted successfully",
            'data' => $id
        ]);
    }

    public function getMultiplePackagingBoxes(Request $request)
    {
        return response()->json([
            'error' => false,
            'data' => $this->multiplePackagingBoxes($request),
            'message' => 'Box added successfully.'
        ]);
    }

    public function multiplePackagingBoxes($request)
    {
        $storeId = $request['store_id'];
        $products = ProductSetting::select('product_settings.id', 'product_settings.name', 'product_settings.sku')
            ->where('product_settings.ship_multiple_package', 1)
            ->where('product_settings.store_id', $storeId)->get()->toArray();
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $multiplePackages = $this->getBoxesByProductId($product['id']);
                $products[$key]['boxes'] = $multiplePackages ?? [];
            }
        }
        return $products ?? [];
    }

    public function getBoxesByProductId($productId)
    {
        return MultiplePackagingBoxes::where('product_id', $productId)
            ->where('status', 1)
            ->get()->toArray();
    }

    public function addMultiplePackagingBox(Request $request)
    {
        $data = $request->except(['store_name', 'store_hash', 'store_id']);
        if (MultiplePackagingBoxes::where('id', '!=', $request->id)->where('product_id', $request->product_id)->where('nickname', $request->nickname)->exists()) {
            return response()->json([
                'error' => true,
                'message' => "Nickname already exists."
            ]);
        }
        if (MultiplePackagingBoxes::create($data)) {
            return response()->json([
                'error' => false,
                'data' => $this->multiplePackagingBoxes($request),
                'message' => 'Box added successfully.'
            ]);
        } else {
            return response()->json([
                'error' => true,
                'message' => "Box could not be added."
            ]);
        }
    }

    public function deleteMultiplePackagingBox(Request $request)
    {
        $id = $request->id;
        if (MultiplePackagingBoxes::find($id)->delete()) {
            return response()->json([
                'error' => false,
                'data' => $this->multiplePackagingBoxes($request),
                'message' => 'Box deleted successfully.'
            ]);
        } else {
            return response()->json([
                'error' => true,
                'message' => "Box could not be deleted."
            ]);
        }
    }

    public function updateMultiplePackagingBox(Request $request)
    {
        $id = $request->id;
        $update = $request->except(['store_name', 'store_hash', 'store_id', 'id']);
        if (MultiplePackagingBoxes::where('id', '!=', $request->id)->where('product_id', $request->product_id)->where('nickname', $request->nickname)->exists()) {
            return response()->json([
                'error' => true,
                'message' => "Nickname already exists."
            ]);
        }
        if (MultiplePackagingBoxes::find($id)->update($update)) {
            return response()->json([
                'error' => false,
                'data' => $this->multiplePackagingBoxes($request),
                'message' => 'Box updated successfully.'
            ]);
        } else {
            return response()->json([
                'error' => true,
                'message' => "Box could not be updated."
            ]);
        }
    }
}
