<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\TypsyQuizData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class TypsyApiController extends Controller
{
    public function handleQuizCreate(Request $request)
    {
       try {
            // Inserting data
            $orderData = new TypsyQuizData();
            $orderData->looking = $request->looking;
            $orderData->mood = $request->mood;
            $orderData->drink = $request->drink;
            $orderData->weather = $request->weather;
            $orderData->scents = $request->scents;
            $orderData->favorites = $request->favorites;
            $orderData->heart = $request->heart;
            $orderData->statement = $request->statement;
            $orderData->email = $request->email;
            $orderData->mobile = $request->mobile;
            $orderData->recommendedProduct = $request->recommendedProduct;
            
            $orderData->save();
            
            $result = $this->createShopifyCustomer($request->email, $request->mobile, $request->recommendedProduct);
            
            if ($result && isset($result['success']) && $result['success'] === true) {
                return response()->json(['success' => true, 'message' => 'Quiz data saved and customer created.']);
            } else {
                return response()->json(['error' => 'Quiz data saved but failed to create customer.'], 500);
            }

        } catch (\Exception $e) {
            // Log any errors
            Log::channel('typsy')->debug('Error typsy quiz-create: ' . $e->getMessage());
            return response()->json(['error' => 'Error typsy quiz-create:'], 500);
        }
    }
    

    public function createShopifyCustomer($email, $phone, $tag)
    {
        // Shopify credentials from config
        $apiKey = env('TYPSY_API_KEY');
        $apiPassword = env('TYPSY_API_PASSWORD');
        $shopName = env('TYPSY_SHOPNAME');
        
        // Shopify API version
        $apiVersion = '2025-04';
    
        // Create Guzzle client
        $client = new Client([
            'base_uri' => "https://$shopName/admin/api/$apiVersion/",
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'auth' => [$apiKey, $apiPassword],
            'verify' => false,
        ]);
    
        // Payload
        $data = [
            'customer' => [
                'email' => $email,
                'phone' => $phone,
                'tags' => 'quiz-'.$tag,
            ]
        ];
    
        try {
            $response = $client->post('customers.json', [
                'json' => $data
            ]);

            // $responseBody = json_decode($response->getBody(), true);
            // return response()->json($responseBody, 200);

            return response()->json(['success' => true, 'message' => 'Customer created successfully']);
    
        } catch (\Exception $e) {
            Log::channel('typsy')->debug('Error Customer Create: ' . $e->getMessage());
        }
    }
    
    public function sendOTP(Request $request)
    {
        $phoneNumber = $request->input('phone');

        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => "https://control.msg91.com/api/v5/otp?otp_expiry=5&template_id=6846b65ed6fc053e240ba922&mobile=91$phoneNumber&authkey=444332AMuk0a3UaO683eeab2P1&realTimeResponse=1",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS =>'{
          "Param1": "value1",
          "Param2": "value2",
          "Param3": "value3"
        }',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/JSON',
            'content-type: application/json'
          ),
        ));
        
        $response = curl_exec($curl);
        
        curl_close($curl);
        echo $response;
    }
    
    public function verifyOTP(Request $request)
    {
        $otp = $request->input('otp');
        $phoneNumber = $request->input('phone');

        $curl = curl_init();
        
        curl_setopt_array($curl, array(
          CURLOPT_URL => "https://control.msg91.com/api/v5/otp/verify?otp=$otp&mobile=91$phoneNumber",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'authkey: 444332AMuk0a3UaO683eeab2P1',
            'Content-Type: text/plain'
          ),
        ));
        
        $response = curl_exec($curl);
        
        curl_close($curl);
        echo $response;

    }
}
