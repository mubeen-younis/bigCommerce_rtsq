<?php

namespace App\Http\Controllers;

use App\Models\ApiAccessTokens;
use Illuminate\Http\Request;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\ProductSetting;
use App\Models\Store;
use App\Models\Locations;
use App\Models\ShippingGroup;
use App\Http\Controllers\ExportImportProducts;
use Illuminate\Support\Facades\Log;
use App\Models\NestingItemsDetail;

class ApiAccessTokenController extends Controller
{
    /**
     * Create Api Access Token
     *
     * @return \Illuminate\Http\Response
     */

    public function create(Request $request)
    {
        if(isset($request->store_id) && $request->store_id){
            $accessToken = Helpers::getUuid();
            $ApiAccessTokens = ApiAccessTokens::firstOrCreate(['store_id' => $request->store_id]);
            $ApiAccessTokens->access_token = $accessToken;
            $ApiAccessTokens->save();

            return Helpers::sendJsonResponse(false, "Token created successfully.", $ApiAccessTokens);
        }
        
        return Helpers::sendJsonResponse(true, "Something went wrong.");
    }

    public function show(Request $request)
    {
        if(isset($request->store_id) && $request->store_id){
            $ApiAccessTokens = optional(ApiAccessTokens::where('store_id', $request->store_id)->first())->access_token ?? null;
            return Helpers::sendJsonResponse(false, "Get access token", $ApiAccessTokens);
        }
        
        return Helpers::sendJsonResponse(true, "Something went wrong.");
    }
    // Update Product Params
    public function updateProduct(Request $request)
    {
        try {
            $this->storeId = isset($request->store_id) ? $request->store_id : '';
            $isSetProduct = !empty($request->data) && !empty($request->data->attributes) ? true : false;
            $product['data'] = !empty($request->data) ? $request->data : [];
            if (!empty($product['data'])){

                $rules = [
                    'data.productId' => 'required|numeric',
                    'data.variantId' => 'required|numeric',
                    'data.attributes.sku' => 'nullable|string',
                    'data.attributes.name' => 'nullable|string',
                    'data.attributes.weight' => [
                        'nullable',
                        'numeric',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 10) {
                                $errors[] = 'The value is invalid. The value can be max 10 characters long.';
                            } 
                            if ($value < 0.01) {
                                $errors[] = 'The ' . $attribute . ' must be greater than 0.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            } 
                        },
                    ],
                    'data.attributes.length' => [
                        'nullable',
                        'numeric',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 10) {
                                $errors[] = 'The value is invalid. The value can be max 10 characters long.';
                            } 
                            if ($value < 0.01) {
                                $errors[] = 'The ' . $attribute . ' must be greater than 0.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            } 
                        },
                    ],
                    'data.attributes.width' => [
                        'nullable',
                        'numeric',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 10) {
                                $errors[] = 'The value is invalid. The value can be max 10 characters long.';
                            } 
                            if ($value < 0.01) {
                                $errors[] = 'The ' . $attribute . ' must be greater than 0.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            } 
                        },
                    ],
                    'data.attributes.height' => [
                        'nullable',
                        'numeric',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 10) {
                                $errors[] = 'The value is invalid. The value can be max 10 characters long.';
                            } 
                            if ($value < 0.01) {
                                $errors[] = 'The ' . $attribute . ' must be greater than 0.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            } 
                        },
                    ],
                    'data.attributes.nmfc' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 10) {
                                $errors[] = 'The ' . $attribute . ' must not be greater than 10 characters.';
                            } 
                            if (!preg_match('/^[0-9\-]+$/', $value)) {
                                $errors[] = 'The format is invalid. The value should contain numbers or hyphen(-) example: -123456-23';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            } 
                        },
                    ],
                    'data.attributes.HSCode' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 20) {
                                $errors[] = 'The value is invalid. The value can be max 20 characters long.';
                            }
                            if (!preg_match('/^[0-9\.]+$/', $value)) {
                                $errors[] = 'The format is invalid. The value should contain numbers or dot(.) example: 1234.56.2312';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            } 
                        },
                    ],
                    'data.attributes.productMarkup' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            $errors = [];
                            if (strlen($value) > 7) {
                                $errors[] = 'The ' . $attribute . ' must not be greater than 7 characters.';
                            } 
                            if (!preg_match('/^-?\d+(\.\d{1,2})?%?$/', $value)) {
                                $errors[] = 'The format is invalid. The value should contain numbers or percentage(%) example: Currency 1.00 or percentage 5%';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        },
                    ],
                    'data.attributes.quoteMethod' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            // Define the valid values
                            $validValues = ['S', 'L', 'PD'];
                            // Check if the value is not in the valid values array
                            if (!in_array($value, $validValues, true)) {
                                $fail("The value is invalid. The value can only be 'S', 'L', 'PD'");
                            }
                        },
                    ],
                    'data.attributes.freightClass' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            // Define the valid values
                            $validValues = [50, 55, 60, 65, 70, 77.5, 85, 92.5, 100, 110, 125, 150, 175, 200, 250, 300, 400, 500, 'DensityBased'];
                            // Check if the value is not in the valid values array
                            if (!in_array($value, $validValues, true)) {
                                $fail("The value is invalid. The value can only be 50, 55, 60, 65, 70, 77.5, 85, 92.5, 100, 110, 125, 150, 175, 200, 250, 300, 400, 500, 'DensityBased'");
                            }
                        },
                    ],
                    'data.attributes.hazardousEnabled' => 'required|boolean',
                    'data.attributes.insuranceEnabled' => 'required|boolean',
                    'data.attributes.boxingProperties' => [
                        'nullable',
                        'integer',
                        'numeric',
                        function ($attribute, $value, $fail) {
                            // Define the valid values
                            $validValues = [1, 2, 3];
                            // Check if the value is not in the valid values array
                            if (!in_array($value, $validValues, true)) {
                                $fail("The value is invalid. The value can only be 1, 2 or 3");
                            }
                        },
                    ],
                    'data.attributes.palletProperties' => [
                        'nullable',
                        'integer',
                        'numeric',
                        function ($attribute, $value, $fail) {
                            // Define the valid values
                            $validValues = [1, 2];
                            // Check if the value is not in the valid values array
                            if (!in_array($value, $validValues, true)) {
                                $fail("The value is invalid. The value can only be 1 or 2");
                            }
                        },
                    ],
                
                    'data.attributes.dropship.enabled' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (!in_array($value, [0, 1, '0', '1'], true)) {
                                $fail("The value is invalid. The value can only be 0 or 1");
                            }
                        }
                    ],
                    'data.attributes.dropship.nickname' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.dropship.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_string($value)) {
                                    $errors[] = "The " . $attribute . ' must be a string.';
                                }
                                if (strlen($value) > 20) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 20 characters.';
                                }
                            } elseif (request('data.attributes.dropship.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.dropship.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.dropship.zipcode' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.dropship.enabled') == 1 && $value !== null) {
                                $errors = [];
                                if (request('data.attributes.dropship.country') == 'US') {
                                    if (strlen($value) > 5) {
                                        $errors[] = 'The ' . $attribute . ' must not be greater than 5 characters for US country example: US => 10003.';
                                    } 
                                } elseif (request('data.attributes.dropship.country') == 'CA') {
                                    if (strlen($value) > 6) {
                                        $errors[] = 'The ' . $attribute . ' must not be greater than 6 characters for CA country example: CA => H2V1H9.';
                                    } 
                                }
                            } elseif (request('data.attributes.dropship.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.dropship.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.dropship.city' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.dropship.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_string($value)) {
                                    $errors[] = "The " . $attribute . ' must be a string.';
                                }
                                if (strlen($value) > 20) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 20 characters.';
                                } 
                            } elseif (request('data.attributes.dropship.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.dropship.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.dropship.state' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.dropship.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_string($value)) {
                                    $errors[] = "The " . $attribute . ' must be a string.';
                                }
                                if (strlen($value) > 2) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 2 characters example: New York => NY, California => CA';
                                } 
                                
                            } elseif (request('data.attributes.dropship.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.dropship.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.dropship.country' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.dropship.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_string($value)) {
                                    $errors[] = "The " . $attribute . ' must be a string.';
                                }
                                if (!in_array($value, ['US', 'CA'], true)) {
                                    $errors[] = "The value is invalid. The value can only be US, CA.";
                                } 
                            } elseif (request('data.attributes.dropship.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.dropship.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],

                    'data.attributes.shippingGroup.enabled' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.dropship.enabled') == 1 && !in_array($value, [0,'0'], true)) {
                                $fail('The shipping group is not enable. To enable please disable data.attributes.dropship.enabled.');
                            } elseif (!in_array($value, [0, 1, '0', '1'], true)) {
                                $fail("The value is invalid. The value can only be 0 or 1");
                            }
                        }
                    ],
                    'data.attributes.shippingGroup.nickname' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.shippingGroup.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_string($value)) {
                                    $errors[] = "The " . $attribute . ' must be a string.';
                                }
                                if (strlen($value) > 20) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 20 characters.';
                                }
                            } elseif (request('data.attributes.shippingGroup.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.shippingGroup.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.shippingGroup.labelAs' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.shippingGroup.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_string($value)) {
                                    $errors[] = "The " . $attribute . ' must be a string.';
                                }
                                if (strlen($value) > 20) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 20 characters.';
                                }
                            } elseif (request('data.attributes.shippingGroup.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.shippingGroup.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.shippingGroup.rate' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.shippingGroup.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_numeric($value) || intval($value) != $value) {
                                    $errors[] = "The " . $attribute . ' must be an integer.';
                                }
                                if ($value < 0.01) {
                                    $errors[] = 'The ' . $attribute . ' must be greater than 0.';
                                }
                            } elseif (request('data.attributes.shippingGroup.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.shippingGroup.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.shippingGroup.rateXquantity' => [
                        function ($attribute, $value, $fail) {
                            if (!in_array($value, [0, 1, '0', '1'], true)) {
                                $fail("The value is invalid. The value can only be 0 or 1");
                            }
                        }
                    ],
                    'data.attributes.nesting.enabled' => [
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (!in_array($value, [0, 1, '0', '1'], true)) {
                                $fail("The value is invalid. The value can only be 0 or 1");
                            }
                        }
                    ],
                    'data.attributes.nesting.dimensionType' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.nesting.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!in_array($value, ['length', 'width', 'height'], true)) {
                                    $errors[] = "The value is invalid. The value can only be length, width, height.";
                                }

                                
                            } elseif (request('data.attributes.nesting.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.nesting.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.nesting.percentage' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.nesting.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_numeric($value) || intval($value) != $value) {
                                    $errors[] = "The " . $attribute . ' must be an integer.';
                                }
                                if ($value > 100) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 100.';
                                }
                                if ($value < 0) {
                                    $errors[] = 'The ' . $attribute . ' must be at least 0.';
                                }
                            } elseif (request('data.attributes.nesting.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.nesting.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                            
                        }
                    ],
                    'data.attributes.nesting.maximumNestedItems' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.nesting.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!is_numeric($value) || intval($value) != $value) {
                                    $errors[] = "The " . $attribute . ' must be an integer.';
                                }
                                if ($value > 10000) {
                                    $errors[] = 'The ' . $attribute . ' must not be greater than 10000.';
                                }
                                if ($value < 0.01) {
                                    $errors[] = 'The ' . $attribute . ' must be greater than 0.';
                                }
                            } elseif (request('data.attributes.nesting.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.nesting.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],
                    'data.attributes.nesting.stackingProperty' => [
                        function ($attribute, $value, $fail) {
                            if (request('data.attributes.nesting.enabled') == 1 && $value !== null) {
                                $errors = [];

                                if (!in_array($value, ['evenly', 'maximized'], true)) {
                                    $errors[] = "The value is invalid. The value can only be evenly or maximized.";
                                }
                            } elseif (request('data.attributes.nesting.enabled') == 1 && empty($value)) {
                                $errors[] = 'The ' . $attribute . ' is required when data.attributes.nesting.enabled is 1.';
                            }

                            if(!empty($errors)){
                                $fail($errors);
                            }
                        }
                    ],

                ];
                

                $validator = Validator::make($product, $rules);

                if ($validator->fails()) {
                    $errors = $validator->errors();

                    // Create a custom error response
                    $formattedErrors = [
                        'message' => $errors->first(), // First error message
                        'errors' => []
                    ];
                   // Format all errors with their corresponding field names
                    foreach ($errors->messages() as $field => $messageArray) {
                        $formattedErrors['errors']['data.' . $field] = $messageArray;
                    }
                    
                    return response()->json($formattedErrors, 422);
                } else {
                    $product = $product['data'];
                    if ($product['variantId'] == null && ProductSetting::where('source_product_id', $product['productId'])->where('store_id', $this->storeId)->exists()) 
                    {
                        $variants = ProductSetting::where('source_product_id', $product['productId'])->where('store_id', $this->storeId)->get()->toArray();
                        if(!empty($variants)){
                            foreach($variants as $variant){
                                $product['variantId'] = $variant['variant_id'];
                                $this->updateData($product);
                            }
                        }

                    } elseif (isset($product['variantId']) && $product['variantId'] != null && ProductSetting::where('source_product_id', $product['productId'])
                        ->where('variant_id', $product['variantId'])
                        ->where('store_id', $this->storeId)->exists()) 
                    {
                        $this->updateData($product);
                    } else {
                        $updateddata[] = $product;
                    }
                    
                }

                if (!empty($updateddata)){
                    Log::info('Product not found errors: ' . json_encode($updateddata));
                    $error['errors'] = ['message' => 'Product Error / Not Found'];
                    return response()->json($error, 404);
                } else {
                    return self::toSendJsonResponse(200, 'Product Data updated successfully', [], 200);
                }

            } elseif (!$isSetProduct){
                return self::toSendJsonResponse(400, 'Invalid request format.', [], 400);
            } else {
                $error['errors'] = ['message' => 'Product Error / Not Found'];
                return response()->json($error, 404);
            }

        } catch (\Exception $exception) {
            Log::info('Exception on update product using API: ' . json_encode([$exception->getMessage(), $exception->getLine()]));
            return ['message' => $exception->getMessage(), 'line' => $exception->getLine()];
        }
    }

    public static function toSendJsonResponse($status, $message, $data = [], $code = 200)
    {
        $response = [
            'message' => $message,
            'status' => $status,
        ];
        if (!blank($data)) {
            $response['data'] = $data;
        }
        return response()->json($response
            , $code);
    }

    public function updateData($product)
    {   
        $update = [];
        $store = Store::where('id', $this->storeId)->first();

        if (isset($product['variantId']) && !empty($product['variantId'])) {
            $oldSettings = ProductSetting::where('source_product_id', $product['productId'])
                ->where('variant_id', $product['variantId'])
                ->where('store_id', $this->storeId)->pluck('settings')->toArray();
        } else {
            $oldSettings = ProductSetting::where('source_product_id', $product['productId'])
                ->whereNull('variant_id')
                ->where('store_id', $this->storeId)->pluck('settings')->toArray();
        }

        $prodAttributes = isset($product['attributes']) ? $product['attributes'] : [];
        
        $settings = $this->getSettings($oldSettings, $prodAttributes, $this->storeId);
        $shipMultiPackage = isset($settings->ship_multi_package) ? $settings->ship_multi_package : null;
        $update['settings'] = json_encode($settings);

        if(isset($prodAttributes['sku']) && $prodAttributes['sku']){
            $update['sku'] = $prodAttributes['sku'];
        }

        if(isset($prodAttributes['weight']) && $prodAttributes['weight']){
            $update['weight'] = $prodAttributes['weight'];
        }

        if(isset($prodAttributes['length']) && $prodAttributes['length']){
            $update['length'] = $prodAttributes['length'];
        }

        if(isset($prodAttributes['width']) && $prodAttributes['width']){
            $update['width'] = $prodAttributes['width'];
        }

        if(isset($prodAttributes['height']) && $prodAttributes['height']){
            $update['height'] = $prodAttributes['height'];
        }
                     
        if(isset($prodAttributes['nmfc']) && $prodAttributes['nmfc']){
            $update['nmfc'] = $prodAttributes['nmfc'];
        }

        if(isset($prodAttributes['productMarkup']) && $prodAttributes['productMarkup']){
            $update['product_markup'] = $prodAttributes['productMarkup'];
        } else {
            $update['product_markup'] = '';
        }

        if ($shipMultiPackage){
            $update['ship_multiple_package'] = true;    
        } else if ($shipMultiPackage === null){
            $update['ship_multiple_package'] = null;
        } else {
            $update['ship_multiple_package'] = false;
        }

        /*Start -  For Dropship CHange*/
        $dropshipLocation = isset($prodAttributes['dropship']) ? $prodAttributes['dropship'] : [];

        if (isset($dropshipLocation['nickname']) && $dropshipLocation['nickname']
        && isset($dropshipLocation['city']) && $dropshipLocation['city']
        && isset($dropshipLocation['state']) && $dropshipLocation['state']
        && isset($dropshipLocation['zipcode']) && $dropshipLocation['zipcode']
        && isset($dropshipLocation['country']) && $dropshipLocation['country'])
        {
            $dropShipId = $this->updateDropShip($dropshipLocation);
            if ($dropShipId != false && $dropshipLocation['enabled']) {
                $update['dropship_enabled'] = $dropshipLocation['enabled'] ?? true;
                $update['dropship_location'] = $dropShipId;
                $update['shipping_group_enabled'] = false;
                $update['shipping_group'] = null;
            } else {
                $update['dropship_enabled'] = false;
                $update['dropship_location'] = null;
            }
        }
        // END //

        /*Start -  For Shipping Group CHange*/
        $shippingGroup = isset($prodAttributes['shippingGroup']) ? $prodAttributes['shippingGroup'] : [];

        if (isset($shippingGroup['nickname']) && $shippingGroup['nickname']
        && isset($shippingGroup['labelAs']) && $shippingGroup['labelAs']
        && isset($shippingGroup['rate']) && $shippingGroup['rate']
        && isset($shippingGroup['rateXquantity']) && $shippingGroup['rateXquantity'])
        {
            $shippingGroupId = $this->updateShippingGroup($shippingGroup);
            if ($shippingGroupId != false && $shippingGroup['enabled']) {
                $update['shipping_group_enabled'] = $shippingGroup['enabled'] ?? true;
                $update['shipping_group'] = $shippingGroupId;
                $update['dropship_enabled'] = false;
                $update['dropship_location'] = null;
            } else {
                $update['shipping_group_enabled'] = false;
                $update['shipping_group'] = null;
            }
        }
        // END //

        /*Start -  For Nesting Setting Change*/
        $nestingSetting = !empty($prodAttributes['nesting']) ? $prodAttributes['nesting'] : [];

        if (!empty($nestingSetting))
        {
            $nestingItemsDetails = $this->updateNestingItemsDetail($product, $nestingSetting);
        }
        // END //

        if (!empty($update)) {
            $exportImportProducts = new ExportImportProducts();
            if ($product['variantId']) {
                ProductSetting::where('source_product_id', $product['productId'])
                    ->where('variant_id', $product['variantId'])
                    ->where('store_id', $this->storeId)->update($update);
            } else {
                ProductSetting::where('source_product_id', $product['productId'])
                    ->whereNull('variant_id')
                    ->where('store_id', $this->storeId)->update($update);
            }
            unset($update['settings']);
            $exportImportProducts->updateBCProduct($product['productId'], $product['variantId'], $this->storeId, $update, $store->access_token, $store->hash);

            return true;
        }

        return false;
    }

    public function updateDropShip($productDropship)
    {
        $dropShipId = false;
        $isDropShip = isset($productDropship['nickname']) && $productDropship['nickname']
            && isset($productDropship['city']) && $productDropship['city']
            && isset($productDropship['state']) && $productDropship['state']
            && isset($productDropship['zipcode']) && $productDropship['zipcode']
            && isset($productDropship['country']) && $productDropship['country'];
        if ($isDropShip) {
            $dropship = true;
            if (array_key_exists('city', $productDropship)) {
                $city = $productDropship['city'];
            } else {
                $dropship = false;
            }
            if (array_key_exists('state', $productDropship)) {
                $state = $productDropship['state'];
            } else {
                $dropship = false;
            }
            if (array_key_exists('zipcode', $productDropship)) {
                $zipcode = $productDropship['zipcode'];
            } else {
                $dropship = false;
            }
            if (array_key_exists('country', $productDropship)) {
                $country = $productDropship['country'];
            } else {
                $dropship = false;
            }
            if (array_key_exists('nickname', $productDropship)) {
                $nickname = $productDropship['nickname'];
            } else {
                $dropship = false;
            }
            if (!($nickname && $country && $zipcode && $state && $city)) {
                $dropship = false;
            }

            if ($dropship) {
                $location = Locations::where('city', $city)
                    ->where('state', $state)
                    ->where('zip_code', $zipcode)
                    ->where('country', $country)
                    ->where('nickname', $nickname)
                    ->where('store_id', $this->storeId)
                    ->where('type', 2)
                    ->get()->toArray();
                if (!empty($location)) {
                    $dropShipId = $location[0]['id'] ?? false;
                } else {
                    // drop ship insert
                    $location = new Locations();
                    $location->nickname = $nickname;
                    $location->store_id = $this->storeId;
                    $location->type = 2;
                    $location->zip_code = $zipcode;
                    $location->city = $city;
                    $location->state = $state;
                    $location->country = $country;
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

    public function updateShippingGroup($productShippingGroup)
    {
        $shippingGroupId = false;
        $isShippingGroup = isset($productShippingGroup['nickname']) && $productShippingGroup['nickname']
            && isset($productShippingGroup['labelAs']) && $productShippingGroup['labelAs']
            && isset($productShippingGroup['rate']) && $productShippingGroup['rate']
            && isset($productShippingGroup['rateXquantity']) && $productShippingGroup['rateXquantity'];
        if ($isShippingGroup) {
            $shippingGroup = true;
            if (array_key_exists('labelAs', $productShippingGroup)) {
                $labelAs = $productShippingGroup['labelAs'];
            } else {
                $shippingGroup = false;
            }
            if (array_key_exists('rate', $productShippingGroup)) {
                $rate = $productShippingGroup['rate'];
            } else {
                $shippingGroup = false;
            }
            if (array_key_exists('rateXquantity', $productShippingGroup)) {
                $rateXquantity = $productShippingGroup['rateXquantity'];
            } else {
                $shippingGroup = false;
            }
            
            if (array_key_exists('nickname', $productShippingGroup)) {
                $nickname = $productShippingGroup['nickname'];
            } else {
                $shippingGroup = false;
            }
            if (!($nickname && $rateXquantity && $rate && $labelAs)) {
                $shippingGroup = false;
            }

            if ($shippingGroup) {
                $shippingGroupDetails = ShippingGroup::where('checkout_description', $labelAs)
                    ->where('rate', $rate)
                    ->where('rate_x_quantity', $rateXquantity)
                    ->where('nickname', $nickname)
                    ->where('store_id', $this->storeId)
                    ->get()->toArray();
                if (!empty($shippingGroupDetails)) {
                    $shippingGroupId = $shippingGroupDetails[0]['id'] ?? false;
                } else {
                    // shipping group insert
                    $shippingGroupDetails = new ShippingGroup();
                    $shippingGroupDetails->nickname = $nickname;
                    $shippingGroupDetails->store_id = $this->storeId;
                    $shippingGroupDetails->rate_x_quantity = $rateXquantity;
                    $shippingGroupDetails->checkout_description = $labelAs;
                    $shippingGroupDetails->rate = $rate;
                    
                    $shippingGroupDetails->save();
                    $shippingGroupId = $shippingGroupDetails->id;
                }
            }
        }
        return $shippingGroupId;
    }

    public function getSettings($oldSettings, $product, $store_id)
    {
        $settings = isset($oldSettings[0]) && $oldSettings[0] ? json_decode($oldSettings[0]) : new \stdClass();

        if (isset($product['quoteMethod']) && $product['quoteMethod']) {
            $quoteMethod = strtolower($product["quoteMethod"]);
            // Added instore and local delivery quoting method here as well Instore-local
            if ($quoteMethod === 's') {
                $settings->parcel_enabled = true;
                $settings->freight_enabled = false;
                $settings->quote_as_local = false;
            } else if ($quoteMethod === 'l') {
                $settings->parcel_enabled = false;
                $settings->freight_enabled = true;
                $settings->quote_as_local = false;
            } else if ($quoteMethod === 'pd') {
                $settings->parcel_enabled = false;
                $settings->freight_enabled = false;
                $settings->quote_as_local = true;
            }
        }
        
        if (isset($product['freightClass']) && $product['freightClass']) {
            $freightClass = (string)$product["freightClass"];

            if ($freightClass == '' || $this->isFreightClass($freightClass)) {
                $settings->freight_class = (string)$product["freightClass"];
            }
        }
        
        if (isset($product['boxingProperties'])) {
            $boxingProperty = strtolower($product["boxingProperties"]);

            // Added Boxing Properties
            if ($boxingProperty === '1') {
                $settings->ship_own_package = true;
                $settings->allow_vertical = false;
                $settings->ship_multi_package = false;
            } else if ($boxingProperty === '2') {
                $settings->allow_vertical = true;
                $settings->ship_own_package = false;
                $settings->ship_multi_package = false;
            } else if ($boxingProperty === '3') {
                $settings->ship_multi_package = true;
                $settings->ship_own_package = false;
                $settings->allow_vertical = false;
            } else if ($boxingProperty === '' || $boxingProperty == 0) {
                $settings->ship_own_package = null;
                $settings->allow_vertical = null;
                $settings->ship_multi_package = null;
            }
        }

        if (isset($product['nmfc']) && $product['nmfc']) {
            $settings->nmfc = (string)$product["nmfc"];
        } else{
            $settings->nmfc = '';
        }

        if (isset($product['HSCode']) && $product['HSCode']) {
            $settings->hs_code = $product["HSCode"];
        } else {
            $settings->hs_code = '';
        }

        if (isset($product['palletProperties'])) {
            $palletProperty = strtolower($product["palletProperties"]);

            // Added Pallet Properties
            if ($palletProperty === '1') {
                $settings->own_pallet = true;
                $settings->pallet_vertical_rotation = false;
            } else if ($palletProperty === '2') {
                $settings->pallet_vertical_rotation = true;
                $settings->own_pallet = false;
            } else if ($palletProperty === '' || $boxingProperty == 0) {
                $settings->own_pallet = null;
                $settings->pallet_vertical_rotation = null;
            }
        }

        if (isset($product['insuranceEnabled'])) {
            $settings->insurance = ($product["insuranceEnabled"] == 1) ? true : false;
        }
        if (isset($product['hazardousEnabled'])) {
            $settings->hazardous_enabled = ($product["hazardousEnabled"] == 1) ? true : false;
        }

        return $settings;
    }

    function isFreightClass($freigtClass)
    {
        $allFreightClass = ['50', '55', '60', '65', '70', '77.5', '85', '92.5', '100', '125', '150', '175', '200', '250', '300', '400', '500', 'DensityBased'];
        return in_array($freigtClass, $allFreightClass);
    }

    public function updateNestingItemsDetail($product, $nestingSetting)
    { 
        $product = ProductSetting::where('source_product_id', $product['productId'])->where('variant_id', $product['variantId'])->where('store_id', $this->storeId)->first();
        $nestingItemsDetails = NestingItemsDetail::firstOrNew(['product_settings_id' => $product['id'], 'store_id' => $this->storeId]);
        if($nestingSetting['dimensionType'] == 'length'){
            $nestingItemsDetails->dimension_type = 0;
        } elseif($nestingSetting['dimensionType'] == 'width'){
            $nestingItemsDetails->dimension_type = 1;
        } elseif($nestingSetting['dimensionType'] == 'height'){
            $nestingItemsDetails->dimension_type = 2;
        }

        if(strtolower($nestingSetting['stackingProperty']) == 'evenly'){
            $nestingItemsDetails->stacked_type = 0;
        } elseif(strtolower($nestingSetting['stackingProperty']) == 'maximized'){
            $nestingItemsDetails->stacked_type = 1;
        }
        
        $nestingItemsDetails->nesting_percentage = $nestingSetting['percentage'] ?? null;
        $nestingItemsDetails->max_nested_items = $nestingSetting['maximumNestedItems'] ?? null;
        $nestingItemsDetails->is_nesting_enabled = $nestingSetting['enabled'] ? 1 : 0;
        $nestingItemsDetails->save();

        return $nestingItemsDetails;
    }

    
}
