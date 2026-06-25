<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\ManupOrderData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Services\MeradocService;

class ManupWebhookController extends Controller {
    public function status(
        Request $request,
        MeradocService $meradocService
    ) {
        try {

            $accessToken = $request->header('access-token');

            if (
                empty($accessToken) ||
                $accessToken !== config('services.meradoc.manup_access_token')
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid access token'
                ], 401);
            }

            $payload = $request->all();

            Log::channel('manup')->info(
                'Webhook Received',
                $payload
            );

            $order = ManupOrderData::where(
                'order_id',
                $payload['orderId'] ?? null
            )->first();

            if (!$order) {

                Log::channel('manup')->warning(
                    'Order Not Found',
                    [
                        'order_id' => $payload['orderId'] ?? null
                    ]
                );

                return response()->json([
                    'status' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $prescriptionImage = null;
            $localImageUrl = null;

            if (
                !empty($payload['validPrescriptions']) &&
                isset($payload['validPrescriptions'][0]['prescriptionFile'])
            ) {

                $prescriptionImage = $payload['validPrescriptions'][0]['prescriptionFile'];

                try {

                    $imageData = $meradocService->getPrescriptionImage(
                        $prescriptionImage
                    );

                    $localImageUrl = $meradocService->savePrescriptionImageLocally(
                        $imageData
                    );

                    Log::channel('manup')->info(
                        'Prescription Image Saved',
                        [
                            'url' => $localImageUrl
                        ]
                    );
                } catch (\Exception $e) {

                    Log::channel('manup')->error(
                        'Prescription Image Save Failed',
                        [
                            'message' => $e->getMessage()
                        ]
                    );
                }
            }

            $order->update([
                'webhook_payload' => $payload,
                'status' => $payload['orderRevalidationStatus'] ?? null,
                'prescription_image' => $localImageUrl,
            ]);

            $orderStatus = $payload['orderRevalidationStatus'] ?? null;

            if (!empty($orderStatus)) {

                $meradocService->updateOrderTag(
                    (int) $order->order_id,
                    $orderStatus
                );
            }

            if (!empty($localImageUrl)) {

                $meradocService->updateOrderPrescriptionMetafield(
                    (int) $order->order_id,
                    $localImageUrl
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Webhook data updated successfully'
            ]);
        } catch (\Throwable $e) {

            Log::channel('manup')->error(
                'Webhook Error',
                [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }
}
