<?php

namespace App\Http\Controllers;

use App\Services\CarrotJewelleryProductSnapshotService;
use App\Services\GoldPriceFetcherService;
use App\Services\ProductCreateService;
use App\Services\ShopifyBulkUpdaterService;
use App\Services\ShopifySingleProductPriceUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ShopifyProductPriceWebhookController extends Controller
{
    public function handleProductCreate(
        Request $request,
        ProductCreateService $productCreateService,
        CarrotJewelleryProductSnapshotService $snapshotService
    ) {
        try {
            $payload = $request->json()->all();

            if (empty($payload)) {
                $payload = $request->all();
            }

            Log::channel('pricesync')->info('=== Shopify product create webhook received ===', [
                'topic' => $request->header('X-Shopify-Topic'),
                'shop' => $request->header('X-Shopify-Shop-Domain'),
                'payload' => $payload,
            ]);

            $result = $productCreateService->handleProductCreate($payload, $snapshotService);
            $httpStatus = ($result['status'] ?? null) === 'error' ? 500 : 200;

            return response()->json($result, $httpStatus);
        } catch (\Throwable $e) {
            Log::channel('pricesync')->error('Shopify product create webhook failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Product create webhook failed.',
            ], 500);
        }
    }

    public function handleProductMetafieldUpdate(
        Request $request,
        GoldPriceFetcherService $goldPriceFetcher,
        ShopifySingleProductPriceUpdateService $priceUpdateService,
        CarrotJewelleryProductSnapshotService $snapshotService,
        ProductCreateService $productCreateService
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

            // Check if product exists in database, if not add it first
            $ensureProductExists = $this->ensureProductExistsInDatabase(
                $payload,
                $productCreateService,
                $snapshotService
            );

            if ($ensureProductExists['status'] === 'error') {
                return response()->json($ensureProductExists, 500);
            }

            $result = $priceUpdateService->updateProductPriceFromWebhook($payload, $goldPriceFetcher, $snapshotService);
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

    private function ensureProductExistsInDatabase(
        array $payload,
        ProductCreateService $productCreateService,
        CarrotJewelleryProductSnapshotService $snapshotService
    ): array {
        try {
            $productId = $this->extractProductIdFromPayload($payload);

            if ($productId === null) {
                Log::channel('pricesync')->warning('Product ensure check skipped: product id missing', [
                    'payload' => $payload,
                ]);

                return [
                    'status' => 'success',
                    'message' => 'Product id not found, proceeding without initial snapshot.',
                ];
            }

            // Check if product already exists in database
            if ($snapshotService->productExists($productId)) {
                Log::channel('pricesync')->info('Product already exists in database', [
                    'product_id' => $productId,
                ]);

                return [
                    'status' => 'success',
                    'message' => 'Product already exists.',
                ];
            }

            // Product doesn't exist, create it first
            Log::channel('pricesync')->info('Product not found in database, creating it now', [
                'product_id' => $productId,
            ]);

            $createResult = $productCreateService->handleProductCreate($payload, $snapshotService);

            if ($createResult['status'] === 'error') {
                return [
                    'status' => 'error',
                    'message' => 'Failed to create product snapshot before metafield update.',
                    'error' => $createResult['error'] ?? 'Unknown error',
                ];
            }

            return [
                'status' => 'success',
                'message' => 'Product created and ready for metafield update.',
            ];
        } catch (\Throwable $e) {
            Log::channel('pricesync')->error('Product ensure check failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Product ensure check failed.',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function extractProductIdFromPayload(array $payload): ?string
    {
        $adminGraphqlId = data_get($payload, 'admin_graphql_api_id');
        if (is_string($adminGraphqlId) && str_contains($adminGraphqlId, 'Product/')) {
            return $adminGraphqlId;
        }

        $ownerResource = data_get($payload, 'owner_resource');
        $ownerId = data_get($payload, 'owner_id');
        if ($ownerResource === 'product' && !empty($ownerId)) {
            return $this->normalizeProductGid((string) $ownerId);
        }

        $productId = data_get($payload, 'product_id');
        if (!empty($productId)) {
            return $this->normalizeProductGid((string) $productId);
        }

        if (array_key_exists('status', $payload) || array_key_exists('title', $payload)) {
            $productId = data_get($payload, 'id');

            if (!empty($productId)) {
                return $this->normalizeProductGid((string) $productId);
            }
        }

        return null;
    }

    private function normalizeProductGid(string $productId): string
    {
        if (str_starts_with($productId, 'gid://shopify/Product/')) {
            return $productId;
        }

        return 'gid://shopify/Product/' . preg_replace('/\D/', '', $productId);
    }

    public function syncCarrotJewelleryProducts(
        ShopifyBulkUpdaterService $shopifyBulkUpdater,
        CarrotJewelleryProductSnapshotService $snapshotService
    ) {
        try {
            Log::channel('pricesync')->info('=== Carrot jewellery product snapshot sync started ===');

            if (!Schema::hasTable('carrot_jewellery_products')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'carrot_jewellery_products table not found. Please run php artisan migrate first.',
                ], 500);
            }

            $products = $shopifyBulkUpdater->fetchAllProductSnapshots();
            $stored = $snapshotService->upsertProductSnapshots($products);

            Log::channel('pricesync')->info('=== Carrot jewellery product snapshot sync finished ===', [
                'fetched' => count($products),
                'stored' => $stored,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Carrot jewellery Shopify products stored successfully.',
                'fetched' => count($products),
                'stored' => $stored,
            ]);
        } catch (\Throwable $e) {
            Log::channel('pricesync')->error('Carrot jewellery product snapshot sync failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Carrot jewellery product sync failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
