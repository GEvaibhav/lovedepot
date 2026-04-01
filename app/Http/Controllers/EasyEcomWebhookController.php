<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EasyEcomWebhookController extends Controller
{
    public function handleOrderCreateWebhook(Request $request)
    {
        try {
            // echo "order create webhook call";
            $eeData = json_decode($request->getContent(), true);
            Log::channel('easyecom')->debug('EasyEcom Order create Webhook: ', $eeData);

            if (isset($eeData['orders']) && is_array($eeData['orders']) && count($eeData['orders']) > 0) {
                $data = $eeData['orders'][0];

                $strings_to_check = array('Cash on Delivery (COD)', 'COD');
                $intersection = array_intersect($strings_to_check, $data['payment_mode']);
    
                if ((!empty($intersection))) {
                    $ctEvent = $this->setCleverTapCODEvent($data, 'COD Verification');
                    if ($ctEvent['status'] === 'success') {
                        Log::channel('easyecom')->debug('COD Event successfull: ', $data['order_id']);
                    }
                }
            } else {
                Log::channel('easyecom')->debug('No orders found or invalid JSON data.');
            }
        } catch (\Exception $e) {
            // Log any errors
            Log::channel('easyecom')->debug('Error processing Order create webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing Order create webhook'], 500);
        }
    }

    public function handleOrderTrackWebhook(Request $request)
    {
        try {
            // echo "order track webhook call";
            $eeData = json_decode($request->getContent(), true);
            Log::channel('easyecom')->debug('EasyEcom Order track Webhook: ', $eeData);

            if (is_array($eeData) && count($eeData) > 0) {
                $data = $eeData[0];
                    
                $ctEvent = $this->setCleverTapTrackEvent($data, 'COD Verification');
                if ($ctEvent['status'] === 'success') {
                    Log::channel('easyecom')->debug('Track Event successfull: ', $data['orderId']);
                }
            } else {
                Log::channel('easyecom')->debug('No orders found or invalid JSON data.');
            }
        
            
        } catch (\Exception $e) {
            // Log any errors
            Log::channel('easyecom')->debug('Error processing Order Track webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing Order Track webhook'], 500);
        }
    }


    public function setCleverTapCODEvent($order, $name) {
        $client = new Client();
        $url = 'https://in1.api.clevertap.com/1/upload';

        $accountId = env('ACCOUNT_ID');
        $passCode = env('PASSCODE');

        $body = [
            'd' => [
                [
                    'identity'=> $order['email'],
                    'type' => 'event',
                    'evtName' => $name,
                    'evtData' => [
                        'Order Id' => $order['order_id'],
                        'Customer Name' => $order['customer_name'],
                        'Contact Num' => $order['contact_num'],
                        'Price' => $order['total_amount'],
                    ],
                ],
            ],
        ];

        $headers = [
            'X-CleverTap-Account-Id' => $accountId,
            'X-CleverTap-Passcode' => $passCode,
            'Content-Type' => 'application/json; charset=utf-8',
        ];

        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $body,
            ]);

            Log::channel('easyecom')->debug('Data CleaverTap COD Event API: ' . $response->getBody());
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::channel('easyecom')->debug('Error CleaverTap COD Event API: ' . $e->getMessage());
        }
    }

    public function setCleverTapTrackEvent($order) {
        $client = new Client();
        $url = 'https://in1.api.clevertap.com/1/upload';

        $accountId = env('ACCOUNT_ID');
        $passCode = env('PASSCODE');

        $body = [
            'd' => [
                [
                    'identity'=> $order['customer_mobile_num'],
                    'type' => 'event',
                    'evtName' => $order['orderStatus'],
                    'evtData' => [
                        'Order Id' => $order['orderId'],
                        'Customer Name' => $order['customer_name'],
                        'Contact Num' => $order['customer_mobile_num'],
                        'Carrier Name' => $order['carrierName'],
                    ],
                ],
            ],
        ];

        $headers = [
            'X-CleverTap-Account-Id' => $accountId,
            'X-CleverTap-Passcode' => $passCode,
            'Content-Type' => 'application/json; charset=utf-8',
        ];

        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $body,
            ]);

            Log::channel('easyecom')->debug('Data CleaverTap Track Event API: ' . $response->getBody());
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::channel('easyecom')->debug('Error CleaverTap Track Event API: ' . $e->getMessage());
        }
    }
}
