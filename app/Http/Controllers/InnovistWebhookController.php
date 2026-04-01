<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class InnovistWebhookController extends Controller
{
    public function handleOrderUpdateWebhook(Request $request)
    {
        try {
            // echo "order update webhook call";
            $data = json_decode($request->getContent(), true);

            Log::channel('innovist')->debug('Innovist Order Update Webhook: ', $data);
        } catch (\Exception $e) {
            // Log any errors
            Log::error('Error processing Order Update webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing Order Update webhook'], 500);
        }
    }

    public function handleOrderFullfillWebhook(Request $request)
    {
        try {
            // echo "order fullfill webhook call";
            $data = json_decode($request->getContent(), true);

            Log::channel('innovist')->debug('Innovist Order Fullfill Webhook: ', $data);
        } catch (\Exception $e) {
            // Log any errors
            Log::error('Error processing Order Fullfill webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing Order Fullfill webhook'], 500);
        }
    }
    
    public function handleOrderFullfillCreate(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true);

            Log::channel('innovist')->debug('Innovist Fullfill Create Webhook: ', $data);
        } catch (\Exception $e) {
            // Log any errors
            Log::error('Error processing Fullfill Create webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing Order Fullfill webhook'], 500);
        }
    }
    
    public function handleOrderFullfillUpdate(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true);

            Log::channel('innovist')->debug('Innovist Order Fullfill Update Webhook: ', $data);
        } catch (\Exception $e) {
            // Log any errors
            Log::error('Error processing Fullfill Update webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing Order Fullfill webhook'], 500);
        }
    }
    
    public function handleCreateEvent(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true);

            if ($data['shipment_status'] === "delivered") {
                $client = new Client();

                $username = env('DATA_API_ID');
                $password = env('DATA_API_KEY');
                $app_id = env('APP_ID');
                $x = env('X');

                $base64EncodedCredentials = base64_encode($username . ':' . $password);

                $response = $client->request('POST', 'https://api-'.$x.'.moengage.com/v1/event/'.$app_id.'?app_id='.$app_id, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . $base64EncodedCredentials,
                    ],
                    'json' => [
                        'type' => 'event',
                        'customer_id' => $data['email'],
                        'actions' => [
                            [
                                'action' => 'Package Delivered',
                                'attributes' => [
                                    'Order Id' => $data['order_id'],
                                    'Order Name' => explode('.', str_replace('#', '', $data['name']))[0]
                                ]
                            ]
                        ]
                    ]
                ]);

                // Handle response
                if ($response->getStatusCode() == 200) {
                    Log::channel('innovist')->debug('Innovist Event create successfully: ', $data['order_id']);
                }
            }
        } catch (\Exception $e) {
            // Log any errors
            Log::channel('innovist')->debug('Innovist Event not create: ' . $data['order_id'] . 'Error Message: ' . $e->getMessage());
            return response()->json(['error' => 'Innovist Event not create'], 500);
        }
    }
}
