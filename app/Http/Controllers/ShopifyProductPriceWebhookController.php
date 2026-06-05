<?php

namespace App\Http\Controllers;

use App\Services\GoldPriceFetcherService;
use App\Services\ShopifySingleProductPriceUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyProductPriceWebhookController extends Controller
{
    public function handleProductMetafieldUpdate(
        Request $request,
        GoldPriceFetcherService $goldPriceFetcher,
        ShopifySingleProductPriceUpdateService $priceUpdateService
    ) {
        try {
            $payload = $request->json()->all();

            if (empty($payload)) {
                $payload = $request->all();
            }

            Log::channel('pricesync')->info('=== Shopify product metafield webhook received ===', [
                'topic' => $request->header('X-Shopify-Topic'),
                'shop' => $request->header('X-Shopify-Shop-Domain'),
                'payload' => $payload,
            ]);

            $result = $priceUpdateService->updateProductPriceFromWebhook($payload, $goldPriceFetcher);
            $httpStatus = ($result['status'] ?? null) === 'error' ? 500 : 200;

            return response()->json($result, $httpStatus);
        } catch (\Throwable $e) {
            Log::channel('pricesync')->error('Shopify product metafield webhook failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Product price update webhook failed.',
            ], 500);
        }
    }
}
