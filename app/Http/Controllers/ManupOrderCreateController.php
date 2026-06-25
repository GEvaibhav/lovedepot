<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\MeradocService;
use App\Models\ManupOrderData;

class ManupOrderCreateController extends Controller {
    public function handleOrderCreate(
        Request $request,
        MeradocService $meradocService
    ) {
        try {

            $order = json_decode($request->getContent(), true);

            $payload = [
                'orderId' => (string) $order['id'],
                'patientName' => trim(
                    ($order['customer']['first_name'] ?? '') . ' ' .
                        ($order['customer']['last_name'] ?? '')
                ),
                'patientMobile' =>
                $order['shipping_address']['phone'] ?? '',
                'patientMobileSecondary' => null,
                'orderReValidationReason' => [],
                'alternateLanguagePreference' => null,
                'orderMedicines' => collect($order['line_items'])
                    ->map(function ($item) {
                        return [
                            'medicineName' => $item['name'],
                            'medicineId' => (string) $item['product_id'],
                            'medicineQuantityOrdered' => $item['quantity'],
                            'medicineType' => 'Strips',
                            'genericName' => $item['title'],
                        ];
                    })
                    ->toArray(),
            ];

            $response = $meradocService->validateOrder($payload);

            if (
                isset($response['status']) &&
                $response['status'] == 200
            ) {

                ManupOrderData::updateOrCreate(
                    [
                        'order_id' => (string) $order['id']
                    ],
                    [
                        'request_payload' => $payload
                    ]
                );

                Log::channel('manup')->info(
                    'Meradoc Order Created Successfully',
                    [
                        'shopify_order' => $order['id'],
                        'response' => $response
                    ]
                );
            } else {

                Log::channel('manup')->error(
                    'Meradoc Order Creation Failed',
                    [
                        'shopify_order' => $order['id'],
                        'response' => $response
                    ]
                );
            }
        } catch (\Throwable $e) {

            Log::channel('manup')->error(
                'Order Create Error',
                [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
