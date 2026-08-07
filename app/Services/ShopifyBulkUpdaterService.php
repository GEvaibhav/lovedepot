<?php

namespace App\Services;

use App\Models\CarrotJewelleryProduct;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyBulkUpdaterService {
    private const MAKING_CHARGE_PERCENT = 1.45;
    private const CHARGE_GST = 1.03;

    private string $endpoint;
    private array $headers;
    private bool $verifySsl;

    public function __construct() {
        $domain = config('shopify.store_domain');
        $version = config('shopify.api_version');
        $this->verifySsl = (bool) config('shopify.verify_ssl', !app()->environment('local'));

        $this->endpoint = "https://{$domain}/admin/api/{$version}/graphql.json";
        $this->headers = [
            'X-Shopify-Access-Token' => config('shopify.access_token'),
            'Content-Type' => 'application/json',
        ];
    }

    private function shopifyRequest(): PendingRequest {
        return Http::withHeaders($this->headers)->withOptions([
            'verify' => $this->verifySsl,
        ]);
    }

    /**
     * All product : get all product data using SHOPIFY API
     * This function use for "price_update" api and prices:sync cronjob
     *
     * NO MORE USED
     */

    public function fetchAllVariants(): array {
        $variants = [];
        $cursor = null;
        $page = 1;

        do {
            $afterClause = $cursor ? ', after: "' . $cursor . '"' : '';

            $query = <<<GQL
            {
                productVariants(first: 250{$afterClause}) {
                    edges {
                        cursor
                        node {
                            id
                            title
                            price
                            variantGoldWeight: metafield(namespace: "custom", key: "net_fine") {
                                value
                            }
                            variant9KGoldWeight: metafield(namespace: "custom", key: "net_weight") {
                                value
                            }
                            variantMakingCharge: metafield(namespace: "custom", key: "making") {
                                value
                            }
                            product {
                                id
                                title
                                status
                                goldWeight: metafield(namespace: "custom", key: "net_fine") {
                                    value
                                }
                                nineKGoldWeight: metafield(namespace: "custom", key: "net_weight") {
                                    value
                                }
                                makingCharge: metafield(namespace: "custom", key: "making") {
                                    value
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GQL;

            $response = $this->shopifyRequest()
                ->post($this->endpoint, ['query' => $query]);

            if ($response->failed()) {
                Log::channel('pricesync')->error('Failed to fetch variants', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $this->endpoint,
                    'ssl_verification' => $this->verifySsl,
                ]);
                break;
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                Log::channel('pricesync')->error('Shopify GraphQL returned errors while fetching variants', [
                    'errors' => $errors,
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);
                break;
            }

            $data = $response->json('data.productVariants');
            if ($data === null) {
                Log::channel('pricesync')->error('Shopify productVariants payload missing', [
                    'response' => $response->json(),
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);
                break;
            }

            $edges = $data['edges'] ?? [];

            if ($page === 1 && empty($edges)) {
                Log::channel('pricesync')->warning('Shopify returned zero variants on first page', [
                    'store_domain' => config('shopify.store_domain'),
                    'endpoint' => $this->endpoint,
                    'response' => $response->json(),
                ]);
            }

            foreach ($edges as $edge) {
                $node = $edge['node'];
                $productStatus = data_get($node, 'product.status');

                $cursor = $edge['cursor'];

                if ($productStatus !== 'ACTIVE') {
                    continue;
                }

                $variants[] = [
                    'gid' => $node['id'],
                    'product_gid' => $node['product']['id'] ?? null,
                    'product_title' => $node['product']['title'] ?? '',
                    'variant_title' => $node['title'] ?? '',
                    'current_price' => $node['price'],
                    'variantGoldWeight' => data_get($node, 'variantGoldWeight.value'),
                    'variant9KGoldWeight' => data_get($node, 'variant9KGoldWeight.value'),
                    'variantMakingCharge' => data_get($node, 'variantMakingCharge.value'),
                    'goldWeight' => data_get($node, 'product.goldWeight.value'),
                    '9kGoldWeight' => data_get($node, 'product.nineKGoldWeight.value'),
                    'makingCharge' => data_get($node, 'product.makingCharge.value'),
                ];
            }

            $hasNext = $data['pageInfo']['hasNextPage'] ?? false;
            $page++;
        } while ($hasNext);

        Log::channel('pricesync')->info('Fetched Shopify variants', [
            'count' => count($variants),
            'store_domain' => config('shopify.store_domain'),
            'endpoint' => $this->endpoint,
        ]);

        return $variants;
    }

    /**
     * All product : get all product data using SHOPIFY API
     * This function use for "carrot_jewellery_products_sync" api
     * and store this product data in database (Table: carrot_jewellery_products)
     */

    public function fetchAllProductSnapshots(): array {
        $products = [];
        $cursor = null;
        $page = 1;

        do {
            $afterClause = $cursor ? ', after: "' . $cursor . '"' : '';

            $query = <<<GQL
            {
                products(first: 250{$afterClause}) {
                    edges {
                        cursor
                        node {
                            id
                            title
                            goldWeight: metafield(namespace: "custom", key: "net_fine") {
                                value
                            }
                            nineKGoldWeight: metafield(namespace: "custom", key: "net_weight") {
                                value
                            }
                            makingCharge: metafield(namespace: "custom", key: "making") {
                                value
                            }
                            variants(first: 250) {
                                edges {
                                    node {
                                        id
                                        title
                                        variantGoldWeight: metafield(namespace: "custom", key: "net_fine") {
                                            value
                                        }
                                        variant9KGoldWeight: metafield(namespace: "custom", key: "net_weight") {
                                            value
                                        }
                                        variantMakingCharge: metafield(namespace: "custom", key: "making") {
                                            value
                                        }
                                    }
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GQL;

            $response = $this->shopifyRequest()
                ->post($this->endpoint, ['query' => $query]);

            if ($response->failed()) {
                Log::channel('pricesync')->error('Failed to fetch products for carrot jewellery snapshot', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $this->endpoint,
                    'ssl_verification' => $this->verifySsl,
                ]);

                throw new \RuntimeException('Shopify product fetch failed with HTTP status ' . $response->status() . '.');
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                Log::channel('pricesync')->error('Shopify GraphQL returned errors while fetching product snapshots', [
                    'errors' => $errors,
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);

                throw new \RuntimeException('Shopify GraphQL error: ' . json_encode($errors));
            }

            $data = $response->json('data.products');
            if ($data === null) {
                Log::channel('pricesync')->error('Shopify products payload missing', [
                    'response' => $response->json(),
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);

                throw new \RuntimeException('Shopify products payload missing.');
            }

            $edges = $data['edges'] ?? [];

            if ($page === 1 && empty($edges)) {
                Log::channel('pricesync')->warning('Shopify returned zero products on first page', [
                    'store_domain' => config('shopify.store_domain'),
                    'endpoint' => $this->endpoint,
                    'response' => $response->json(),
                ]);
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $cursor = $edge['cursor'];
                $variants = [];

                foreach (data_get($node, 'variants.edges', []) as $variantEdge) {
                    $variant = $variantEdge['node'] ?? [];

                    $variants[] = [
                        'gid' => $variant['id'] ?? null,
                        'title' => $variant['title'] ?? '',
                        'goldWeight' => data_get($variant, 'variantGoldWeight.value'),
                        '9kGoldWeight' => data_get($variant, 'variant9KGoldWeight.value'),
                        'makingCharge' => data_get($variant, 'variantMakingCharge.value'),
                    ];
                }

                $products[] = [
                    'product_gid' => $node['id'] ?? null,
                    'product_title' => $node['title'] ?? '',
                    'goldWeight' => data_get($node, 'goldWeight.value'),
                    '9kGoldWeight' => data_get($node, 'nineKGoldWeight.value'),
                    'makingCharge' => data_get($node, 'makingCharge.value'),
                    'variants' => $variants,
                ];
            }

            $hasNext = $data['pageInfo']['hasNextPage'] ?? false;
            $page++;
        } while ($hasNext);

        Log::channel('pricesync')->info('Fetched Shopify product snapshots', [
            'count' => count($products),
            'store_domain' => config('shopify.store_domain'),
            'endpoint' => $this->endpoint,
        ]);

        return array_values(array_filter($products, fn($product) => !empty($product['product_gid'])));
    }

    /**
     * All product : get all product package_weight data using SHOPIFY API
     * This function use for "update_cj_data" api
     * and store this product data in "9k_gold_weight" field in database (Table: carrot_jewellery_products)
     */

    public function fetchAllProductPackageWeights(?int $limit = null): array {
        $products = [];
        $cursor = null;
        $page = 1;

        do {
            $afterClause = $cursor ? ', after: "' . $cursor . '"' : '';

            $query = <<<GQL
            {
                products(first: 250{$afterClause}) {
                    edges {
                        cursor
                        node {
                            id
                            title
                            status
                            variants(first: 250) {
                                edges {
                                    node {
                                        id
                                        title
                                        inventoryItem {
                                            measurement {
                                                weight {
                                                    value
                                                    unit
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GQL;

            $response = $this->shopifyRequest()
                ->post($this->endpoint, ['query' => $query]);

            if ($response->failed()) {
                Log::channel('pricesync')->error('Failed to fetch Shopify package weights', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $this->endpoint,
                    'ssl_verification' => $this->verifySsl,
                ]);

                throw new \RuntimeException('Shopify package weight fetch failed with HTTP status ' . $response->status() . '.');
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                Log::channel('pricesync')->error('Shopify GraphQL returned errors while fetching package weights', [
                    'errors' => $errors,
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);

                throw new \RuntimeException('Shopify GraphQL error: ' . json_encode($errors));
            }

            $data = $response->json('data.products');
            if ($data === null) {
                Log::channel('pricesync')->error('Shopify products package weight payload missing', [
                    'response' => $response->json(),
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);

                throw new \RuntimeException('Shopify products package weight payload missing.');
            }

            $edges = $data['edges'] ?? [];

            if ($page === 1 && empty($edges)) {
                Log::channel('pricesync')->warning('Shopify returned zero products while fetching package weights', [
                    'store_domain' => config('shopify.store_domain'),
                    'endpoint' => $this->endpoint,
                    'response' => $response->json(),
                ]);
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $cursor = $edge['cursor'];

                if (data_get($node, 'status') !== 'ACTIVE') {
                    continue;
                }

                $variants = [];
                foreach (data_get($node, 'variants.edges', []) as $variantEdge) {
                    $variant = $variantEdge['node'] ?? [];
                    $weight = data_get($variant, 'inventoryItem.measurement.weight');

                    $variants[] = [
                        'gid' => $variant['id'] ?? null,
                        'title' => $variant['title'] ?? '',
                        'packageWeight' => data_get($weight, 'value'),
                        'packageWeightUnit' => data_get($weight, 'unit'),
                    ];
                }

                $products[] = [
                    'product_gid' => $node['id'] ?? null,
                    'product_title' => $node['title'] ?? '',
                    'variants' => array_values(array_filter($variants, fn($variant) => !empty($variant['gid']))),
                ];

                if ($limit !== null && count($products) >= $limit) {
                    break;
                }
            }

            $hasNext = $data['pageInfo']['hasNextPage'] ?? false;
            $page++;
        } while ($hasNext && ($limit === null || count($products) < $limit));

        Log::channel('pricesync')->info('Fetched Shopify product package weights', [
            'count' => count($products),
            'store_domain' => config('shopify.store_domain'),
            'endpoint' => $this->endpoint,
        ]);

        $products = array_values(array_filter($products, fn($product) => !empty($product['product_gid'])));

        return $limit === null ? $products : array_slice($products, 0, $limit);
    }

    /**
     * All product : Update NetWight metafield  (9k gold weight) using SHOPIFY API
     */
    public function updateNetWeightMetafields(array $metafields): array {
        $mutation = <<<'GQL'
        mutation UpdateNetWeightMetafields($metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $metafields) {
                metafields {
                    id
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $summary = [
            'requested' => count($metafields),
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach (array_chunk($metafields, 25) as $chunk) {
            $response = $this->shopifyRequest()->post($this->endpoint, [
                'query' => $mutation,
                'variables' => [
                    'metafields' => $chunk,
                ],
            ]);

            if ($response->failed()) {
                $summary['failed'] += count($chunk);
                $summary['errors'][] = [
                    'type' => 'http',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
                continue;
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                $summary['failed'] += count($chunk);
                $summary['errors'][] = [
                    'type' => 'graphql',
                    'errors' => $errors,
                ];
                continue;
            }

            $result = $response->json('data.metafieldsSet');
            if ($result === null) {
                $summary['failed'] += count($chunk);
                $summary['errors'][] = [
                    'type' => 'missing_payload',
                    'response' => $response->json(),
                ];
                continue;
            }

            $userErrors = $result['userErrors'] ?? [];
            if (!empty($userErrors)) {
                $summary['failed'] += count($chunk);
                $summary['errors'][] = [
                    'type' => 'user_errors',
                    'errors' => $userErrors,
                ];
                continue;
            }

            $summary['updated'] += count($result['metafields'] ?? []);
        }

        Log::channel('pricesync')->info('Shopify net_weight metafield update finished', $summary);

        return $summary;
    }

    // public function buildPriceUpdates(array $variants, float $goldRate): array {
    //     $skipped = 0;
    //     $noWeight = 0;
    //     $groupedUpdates = [];

    //     foreach ($variants as $variant) {
    //         $weightInGrams = (float) (
    //              $variant['variant9KGoldWeight']
    //             ?? $variant['9kGoldWeight']
    //             ?? 0
    //         );

    //         $makingPercent = (float) (
    //             $variant['variantMakingCharge']
    //             ?? $variant['makingCharge']
    //             ?? 45
    //         );

    //         if ($weightInGrams <= 0) {
    //             $noWeight++;
    //             Log::channel('pricesync')->debug('Skipped variant without variant or product gold weight metafield', [
    //                 'product_title' => $variant['product_title'],
    //                 'variant_title' => $variant['variant_title'],
    //             ]);
    //             continue;
    //         }

    //         $goldPrice = $weightInGrams * $goldRate;
    //         $makingCharge = $goldPrice * (1 + $makingPercent / 100);
    //         $finalPrice = $makingCharge * (self::CHARGE_GST);

    //         $newPrice = number_format($finalPrice, 2, '.', '');
    //         $currentPrice = number_format((float) $variant['current_price'], 2, '.', '');

    //         if ($currentPrice === $newPrice) {
    //             $skipped++;
    //             continue;
    //         }

    //         if (empty($variant['product_gid'])) {
    //             Log::channel('pricesync')->warning('Skipped variant without product id', [
    //                 'variant_id' => $variant['gid'],
    //                 'product_title' => $variant['product_title'],
    //                 'variant_title' => $variant['variant_title'],
    //                 'weight_in_grams' => round($weightInGrams, 4),
    //                 'gold_rate_24kt' => round($goldRate, 4),
    //                 'gold_price' => round($goldPrice, 2),
    //                 'making_charge' => round($makingCharge, 2),
    //                 'final_price' => $newPrice,
    //             ]);
    //             continue;
    //         }

    //         Log::channel('pricesync')->debug('Prepared variant price update', [
    //             'product_title' => $variant['product_title'],
    //             'variant_title' => $variant['variant_title'],
    //             'weight_in_grams' => round($weightInGrams, 4),
    //             'gold_rate_24kt' => round($goldRate, 4),
    //             'gold_price' => round($goldPrice, 2),
    //             'making_charge' => round($makingCharge, 2),
    //             'final_price' => $newPrice,
    //         ]);

    //         $groupedUpdates[$variant['product_gid']][] = [
    //             'id' => $variant['gid'],
    //             'price' => $newPrice,
    //         ];
    //     }

    //     Log::channel('pricesync')->info('Price updates prepared', [
    //         'updates' => array_sum(array_map('count', $groupedUpdates)),
    //         'products' => count($groupedUpdates),
    //         'skipped' => $skipped,
    //         'no_weight' => $noWeight,
    //     ]);

    //     return $groupedUpdates;
    // }

    /**
     * All product : Prepared product price
     */
    public function buildPriceUpdates(float $goldRate): array {
        $groupedUpdates = [];

        $processed = 0;
        $skipped = 0;
        $noWeight = 0;

        CarrotJewelleryProduct::chunk(500, function ($products) use (
            &$groupedUpdates,
            &$processed,
            &$skipped,
            &$noWeight,
            $goldRate
        ) {

            foreach ($products as $product) {

                $variants = is_array($product->variants)
                    ? $product->variants
                    : json_decode($product->variants, true);

                if (empty($variants)) {
                    continue;
                }

                foreach ($variants as $variant) {

                    $processed++;

                    $weightInGrams = (float) (
                        $variant['9kGoldWeight']
                        ?? $product->{'9k_gold_weight'}
                        ?? 0
                    );

                    $makingPercent = (float) (
                        $variant['makingCharge']
                        ?? $product->making_charge
                        ?? 45
                    );

                    if ($weightInGrams <= 0) {

                        $noWeight++;

                        Log::channel('pricesync')->debug(
                            'Skipped variant without 9K gold weight',
                            [
                                'product_title' => $product->title,
                                'variant_title' => $variant['title'] ?? '',
                            ]
                        );

                        continue;
                    }

                    $goldPrice = $weightInGrams * $goldRate;
                    $makingCharge = $goldPrice * (1 + $makingPercent / 100);
                    $finalPrice = $makingCharge * self::CHARGE_GST;

                    $newPrice = number_format($finalPrice, 2, '.', '');

                    $variantId = $variant['gid'] ?? null;

                    if (!$variantId) {

                        Log::channel('pricesync')->warning(
                            'Variant missing Shopify GID',
                            [
                                'product_title' => $product->title,
                                'variant_title' => $variant['title'] ?? '',
                            ]
                        );

                        continue;
                    }

                    Log::channel('pricesync')->debug(
                        'Prepared variant price update',
                        [
                            'product_id' => $product->shopify_product_id,
                            'product_title' => $product->title,
                            'variant_title' => $variant['title'] ?? '',
                            'weight_in_grams' => round($weightInGrams, 4),
                            'making_percent' => $makingPercent,
                            'gold_rate_9kt' => round($goldRate, 4),
                            'final_price' => $newPrice,
                        ]
                    );

                    $groupedUpdates[$product->shopify_product_id][] = [
                        'id' => $variantId,
                        'price' => $newPrice,
                    ];
                }
            }
        });

        Log::channel('pricesync')->info('Bulk Products Price updates prepared', [
            'processed_variants' => $processed,
            'updates' => array_sum(array_map('count', $groupedUpdates)),
            'products' => count($groupedUpdates),
            'skipped' => $skipped,
            'no_weight' => $noWeight,
        ]);

        return $groupedUpdates;
    }

    /**
     * All product : Update product price using SHOPIFY API
     */
    public function updatePrices(array $groupedUpdates): array {
        $mutation = <<<'GQL'
        mutation UpdateProductVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                product {
                    id
                }
                productVariants {
                    id
                    price
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $summary = [
            'updated' => 0,
            'failed' => 0,
            'products' => count($groupedUpdates),
            'errors' => [],
        ];

        foreach ($groupedUpdates as $productId => $variants) {
            $response = $this->shopifyRequest()->post($this->endpoint, [
                'query' => $mutation,
                'variables' => [
                    'productId' => $productId,
                    'variants' => $variants,
                ],
            ]);

            if ($response->failed()) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'http',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
                continue;
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'graphql',
                    'errors' => $errors,
                ];
                continue;
            }

            $result = $response->json('data.productVariantsBulkUpdate');
            if ($result === null) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'missing_payload',
                    'response' => $response->json(),
                ];
                continue;
            }

            if (!empty($result['userErrors'])) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'user_errors',
                    'errors' => $result['userErrors'],
                ];
                continue;
            }

            $updatedCount = count($result['productVariants'] ?? []);
            $summary['updated'] += $updatedCount;

            Log::channel('pricesync')->info('Updated product variants', [
                'product_id' => $productId,
                'updated_count' => $updatedCount,
            ]);
        }

        Log::channel('pricesync')->info('Bulk product price update finished', $summary);

        return $summary;
    }
}
