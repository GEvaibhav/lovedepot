<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\ShopifyOrderData;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Mail;


class ShopifyWebhookController extends Controller
{
    // public function handleOrderCreate(Request $request)
    // {
    //     try {
    //         $data = json_decode($request->getContent(), true);
    //         Log::channel('lovedepot')->debug('lovedepot order-create webhook: ' . json_encode($data));

    //         $strings_to_check = array('Cash on Delivery (COD)', 'COD');
    //         $intersection = array_intersect($strings_to_check, $data['payment_gateway_names']);

    //         if ((!empty($intersection))) {
    //             // Inserting data
    //             $orderData = new ShopifyOrderData();
    //             $orderData->order_id = $data['id'];
    //             $orderData->order_name = str_replace('#', '', $data['name']);
    //             $orderData->order_date = Carbon::parse($data['created_at'])->toDateTimeString();
    //             $orderData->save();
    //         }
    //     } catch (\Exception $e) {
    //         // Log any errors
    //         Log::channel('lovedepot')->debug('Error lovedepot order-create webhook: ' . $e->getMessage());
    //         return response()->json(['error' => 'Error lovedepot order-create webhook:'], 500);
    //     }
    // }
    
    public function handleOrderCreate(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true);
            Log::channel('lovedepot')->debug('lovedepot order-create webhook: ' . json_encode($data));
    
            $strings_to_check = ['Cash on Delivery (COD)', 'COD'];
            $intersection = array_intersect($strings_to_check, $data['payment_gateway_names'] ?? []);
    
            if (!empty($intersection)) {
                DB::table('shopify_order_data')->insert([
                    'order_id' => $data['id'],
                    'order_name' => str_replace('#', '', $data['name']),
                    'order_date' => Carbon::parse($data['created_at'])->toDateTimeString(),
                ]);
            }
    
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::channel('lovedepot')->debug('Error lovedepot order-create webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error lovedepot order-create webhook'], 500);
        }
    }

    public function handleCronJob()
    {
       // shopify credentials
        $apiKey = config('app.apiKey');
        $apiPassword = config('app.apiPassword');
        $shopName = config('app.shopName');

        // Create a Guzzle HTTP client
        $client = new Client([
            'base_uri' => "https://$shopName/admin/api/2024-01/",
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'auth' => [$apiKey, $apiPassword],
            'verify' => false,
        ]);
        
        $order_id = 5880304861253;
        
        try {
            // Make a GET request to the Shopify API
            $response = $client->request('GET', "orders/$order_id.json");

            // Check if the request was successful
            if ($response->getStatusCode() == 200) {
                // Process the response data
                $data =  json_decode($response->getBody()->getContents(), true);

                $orderTags = $data['order']['tags'];
                $ordercancelled = $data['order']['cancelled_at'];

                
                if($ordercancelled) {
                     echo "order  cancelled";
                } else {
                    echo "order not cancelled";
                }

              
            } else {
                echo 'Error response: ' . $response->getStatusCode();
            }
        } catch (\Exception $e) {
            echo 'Error API: ' . $e->getMessage();
        }
        

    }

    public function fetchWareIqOrders()
    {
        $client = new Client([
            'base_uri' => 'https://track.wareiq.com', // WareIQ API base URL
        ]);

        $apiKey = config('app.wareiqApiKey');
        
        $header = [
            'Authorization' => 'Token ' . $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        
         $order_name = "LD32298";

        $searchBody = [
            "search" => [
                "order_details_bulk" => $order_name
            ],
            "page" => 1,
            "per_page" => 10
        ];

        try {
            $response = $client->post('/orders/v2/orders/b2c/new', [
                'headers' => $header,
                'json' => $searchBody
            ]);

            $result = json_decode($response->getBody(), true);
            
            return json_encode($result);
            
            $uniqueId =  count($result['data']) ?  $result['data'][0]['unique_id'] : '';
            $isCodConfirm =  count($result['data']) ?  $result['data'][0]['cod_verification']['confirmed'] : '';

            if ($isCodConfirm) {
                Log::channel('lovedepot')->debug('Cod confirm ' . $order_name . ' Result ' . json_encode($result));
                return ['isCodConfirm' => true];
            } elseif ($uniqueId) {
                $updateBody = [
                    "unique_ids" => [$uniqueId]
                ];
                $response = $client->post('/orders/v2/actions/cod/accept', [
                    'headers' => $header,
                    'json' => $updateBody
                ]);

                return json_decode($response->getBody(), true);
            } else {
                try {
                    $response = $client->post('/orders/v2/orders/b2c/ready_to_ship', [
                        'headers' => $header,
                        'json' => $searchBody
                    ]);

                    $result1 = json_decode($response->getBody(), true);
                    $isCodConfirm =  count($result1['data']) ?  $result1['data'][0]['cod_verification']['confirmed'] : '';

                    if ($isCodConfirm) {
                        Log::channel('lovedepot')->debug('Cod confirm ' . $order_name . ' result1 ' . json_encode($result1));
                        return ['isCodConfirm' => true];
                    }
                } catch (\Exception $e) {
                    // Handle exceptions
                    Log::channel('lovedepot')->debug('Error on wareIQ "/orders/v2/orders/b2c/ready_to_ship" API - order No: ' . $order_name . $e->getMessage());
                    return ['error' => $e->getMessage()];
                }

                Log::channel('lovedepot')->debug('Not get unique_id for order no: ' . $order_name . ' Result ' . json_encode($result));
                return ['status' => 'failed'];
            }
        } catch (\Exception $e) {
            // Handle exceptions
            Log::channel('lovedepot')->debug('Error on wareIQ API - order No: ' . $order_name . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function fetchOrders($order_id, $orderName, $orderDate)
    {
        // shopify credentials
        $apiKey = config('app.apiKey');
        $apiPassword = config('app.apiPassword');
        $shopName = config('app.shopName');

        // Create a Guzzle HTTP client
        $client = new Client([
            'base_uri' => "https://$shopName/admin/api/2024-01/",
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'auth' => [$apiKey, $apiPassword],
            'verify' => false,
        ]);

        try {
            // Make a GET request to the Shopify API
            $response = $client->request('GET', "orders/$order_id.json");

            // Check if the request was successful
            if ($response->getStatusCode() == 200) {
                // Process the response data
                $data =  json_decode($response->getBody()->getContents(), true);

                $orderTags = $data['order']['tags'];

                // Convert the string to a Carbon instance
                $dateTime = Carbon::parse($orderDate);

                // Get the current date and time
                $now = Carbon::now();

                if (Str::contains($orderTags, "cod_cancellation_initiated")) {
                    $sfOrderCancel = $client->request('POST', "orders/$order_id/cancel.json");

                    if ($sfOrderCancel->getStatusCode() == 200) {
                        $order_delete_Shopify = 1;

                        $wareiqOrderCancel = $this->fetchWareIqOrders($orderName, false);
                        $order_delete_Wareiq = 0;
                        if ($wareiqOrderCancel['status'] === 'success') {
                            $order_delete_Wareiq = 1;
                        }

                        DB::table('shopify_order_data')
                            ->where('order_id', $order_id)
                            ->update([
                                'status' => 1,
                                'delete_order_Shopify' => $order_delete_Shopify,
                                'delete_order_wareiq' => $order_delete_Wareiq,
                            ]);
                    }
                } elseif ((Str::contains($orderTags, "cod_order_confirmed"))) {
                    $order_delete_Shopify = 1;

                    $wareiqOrderCancel = $this->fetchWareIqOrders($orderName, true);
                    $cod_verification_wareiq = 0;
                    if ($wareiqOrderCancel['status'] === 'success') {
                        $cod_verification_wareiq = 1;
                    }

                    DB::table('shopify_order_data')
                        ->where('order_id', $order_id)
                        ->update([
                            'status' => 1,
                            'cod_verification_wareiq' => $cod_verification_wareiq
                        ]);
                    } elseif ($dateTime->diffInHours($now) >= 48) {
                        
                        DB::table('shopify_order_data')
                            ->where('order_id', $order_id)
                            ->update([
                                'status' => 1,
                            ]);
                            
                    // $sfOrderCancel = $client->request('POST', "orders/$order_id/cancel.json");

                    // if ($sfOrderCancel->getStatusCode() == 200) {
                    //     $order_delete_Shopify = 1;

                    //     $wareiqOrderCancel = $this->fetchWareIqOrders($orderName, false);
                    //     $order_delete_Wareiq = 0;
                    //     if ($wareiqOrderCancel['status'] === 'success') {
                    //         $order_delete_Wareiq = 1;
                    //     }

                    //     DB::table('shopify_order_data')
                    //         ->where('order_id', $order_id)
                    //         ->update([
                    //             'status' => 1,
                    //             'delete_order_Shopify' => $order_delete_Shopify,
                    //             'delete_order_wareiq' => $order_delete_Wareiq,
                    //         ]);
                    // }
                }
            } else {
                Log::channel('lovedepot')->debug('Error order api: order No: ' . $orderName . $response->getStatusCode());
                // echo 'Error: ' . $response->getStatusCode();
            }
        } catch (\Exception $e) {
            Log::channel('lovedepot')->debug('Error order processing webhook- order No: ' . $orderName . $e->getMessage());
            Log::channel('lovedepot')->debug('order DATA: ' . json_encode($data));
            Log::channel('lovedepot')->debug('cancel order DATA: ' . $sfOrderCancel->getStatusCode());
            Log::channel('lovedepot')->debug('wareIq order DATA: ' . json_encode($wareiqOrderCancel));
            // echo 'Error: ' . $e->getMessage();
        }
    }


}
