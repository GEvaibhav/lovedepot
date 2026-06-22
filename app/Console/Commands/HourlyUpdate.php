<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Mail;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\ShopifyOrderData;


class HourlyUpdate extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hour:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Every hour check shopify order data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        try {
            // $this->info('Custom task executed successfully!');

            // Get the current time 2 hours ago
            $twoHoursAgo = Carbon::now()->subMinutes(30)->toDateTimeString();

            // Retrieve entries where order_date is 2 hours old
            // $orderItem = ShopifyOrderData::where('order_date', '<', $twoHoursAgo)
            //     ->where('status', 0)
            //     ->get();

            $orderProcessed = false;

            ShopifyOrderData::where('order_date', '<', $twoHoursAgo)
                ->where('status', 0)
                ->chunk(50, function ($orders) use (&$orderProcessed) {

                    if ($orders->count()) {
                        $orderProcessed = true;
                    }

                    foreach ($orders as $order) {
                        $this->fetchOrders(
                            $order->order_id,
                            $order->order_name,
                            $order->order_date
                        );
                    }
                });

            if (!$orderProcessed) {

                $emails = [
                    'vaibhav@gradienteye.com',
                    'chirag@gradienteye.com'
                ];

                Mail::raw('No new orders were found in the last 4 hours.', function ($message) use ($emails) {
                    $message->to($emails)
                        ->subject('No New Orders Alert');
                });

                Log::channel('lovedepot')->debug(
                    'No orders found. Email sent to: ' . implode(', ', $emails)
                );
            }

            DB::disconnect();
        } catch (\Exception $e) {
            Log::channel('lovedepot')->debug('Error get order on cronjob: ' . $e->getMessage());
            // echo 'Error: ' . $e->getMessage();
        }
    }

    public function fetchOrders($order_id, $orderName, $orderDate) {
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
                $ordercancelled = $data['order']['cancelled_at'];

                // Convert the string to a Carbon instance
                $dateTime = Carbon::parse($orderDate);

                // Get the current date and time
                $now = Carbon::now();

                if ($dateTime->diffInHours($now) >= 48) {

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
                } elseif ($ordercancelled) {

                    DB::table('shopify_order_data')
                        ->where('order_id', $order_id)
                        ->update([
                            'status' => 1,
                            'delete_order_Shopify' => 1,
                            'delete_order_wareiq' => 1,
                        ]);

                    // $wareiqOrderCancel = $this->fetchWareIqOrders($orderName, false);

                    // if (isset($wareiqOrderCancel['status']) && $wareiqOrderCancel['status'] === 'success') {
                    //     $order_delete_Wareiq = 1;

                    //     $sfOrderCancel = $client->request('POST', "orders/$order_id/cancel.json");
                    //     if ($sfOrderCancel->getStatusCode() == 200) {
                    //         $order_delete_Shopify = 1;

                    //         DB::table('shopify_order_data')
                    //             ->where('order_id', $order_id)
                    //             ->update([
                    //                 'status' => 1,
                    //                 'delete_order_Shopify' => $order_delete_Shopify,
                    //                 'delete_order_wareiq' => $order_delete_Wareiq,
                    //             ]);
                    //     }
                    // }
                } elseif (Str::contains($orderTags, "COD Confirmed")) {

                    $wareiqOrderCancel = $this->fetchWareIqOrders($orderName, true);

                    if (isset($wareiqOrderCancel['status']) && $wareiqOrderCancel['status'] === 'success') {
                        $cod_verification_wareiq = 1;

                        DB::table('shopify_order_data')
                            ->where('order_id', $order_id)
                            ->update([
                                'status' => 1,
                                'cod_verification_wareiq' => $cod_verification_wareiq
                            ]);
                    } elseif (isset($wareiqOrderCancel['isCodConfirm']) && $wareiqOrderCancel['isCodConfirm']) {
                        DB::table('shopify_order_data')
                            ->where('order_id', $order_id)
                            ->update([
                                'status' => 1,
                            ]);
                    }
                }
            } else {
                Log::channel('lovedepot')->debug('Error order api: order No: ' . $orderName . $response->getStatusCode());
                // echo 'Error: ' . $response->getStatusCode();
            }
        } catch (\Exception $e) {
            Log::channel('lovedepot')->debug('Error order processing webhook- order No: ' . $orderName . $e->getMessage());
            Log::channel('lovedepot')->debug('order DATA: ' . json_encode($data ?? []));
            Log::channel('lovedepot')->debug('cancel order DATA: ' . ($sfOrderCancel->getStatusCode() ?? 'N/A'));
            Log::channel('lovedepot')->debug('wareIq order DATA: ' . json_encode($wareiqOrderCancel ?? []));

            // echo 'Error: ' . $e->getMessage();
        }
    }

    public function fetchWareIqOrders($order_name, $cod_verification) {
        $client = new Client([
            'base_uri' => 'https://track.wareiq.com', // WareIQ API base URL
        ]);

        $apiKey = config('app.wareiqApiKey');

        $header = [
            'Authorization' => 'Token ' . $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

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
                        Log::channel('lovedepot')->debug('ready_to_ship Cod confirm ' . $order_name . ' result1 ' . json_encode($result1));
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
}
