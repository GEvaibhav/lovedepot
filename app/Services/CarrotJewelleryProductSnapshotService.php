<?php

namespace App\Services;

use App\Models\CarrotJewelleryProduct;
use Illuminate\Support\Facades\Log;

class CarrotJewelleryProductSnapshotService
{
    public function productExists(string $shopifyProductId): bool
    {
        return CarrotJewelleryProduct::where('shopify_product_id', $shopifyProductId)->exists();
    }

    public function hasChanged(array $productSnapshot): bool
    {
        $query = CarrotJewelleryProduct::where('shopify_product_id', $productSnapshot['product_gid']);
        $existing = $query->first();
        $incomingHash = $this->hashSnapshot($productSnapshot);
        $incomingTrackedSnapshot = $this->trackedSnapshot($productSnapshot);

        if ($existing === null) {
            Log::channel('pricesync')->info('Product metafield snapshot comparison: no stored snapshot found', [
                'product_id' => $productSnapshot['product_gid'],
                'query' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'product_snapshot' => $productSnapshot,
                'incoming_hash' => $incomingHash,
                'incoming_tracked_snapshot' => $incomingTrackedSnapshot,
                'has_changed' => false,
                'reason' => 'No existing carrot_jewellery_products row; using this webhook as baseline.',
            ]);

            return false;
        }

        $storedSnapshot = [
            'goldWeight' => $existing->gold_weight,
            '9kGoldWeight' => $existing->{'9k_gold_weight'},
            'makingCharge' => $existing->making_charge,
            'variants' => $this->storedTrackedVariants($existing->variants ?? []),
        ];
        $hasChanged = $existing->metafield_hash !== $incomingHash;

        // Log::channel('pricesync')->info('Product metafield snapshot comparison result', [
        //     'product_id' => $productSnapshot['product_gid'],
        //     'query' => $query->toSql(),
        //     'bindings' => $query->getBindings(),
        //     'product_snapshot' => $productSnapshot,
        //     'stored_row' => [
        //         'id' => $existing->id,
        //         'shopify_product_id' => $existing->shopify_product_id,
        //         'shopify_product_numeric_id' => $existing->shopify_product_numeric_id,
        //         'title' => $existing->title,
        //         'metafield_hash' => $existing->metafield_hash,
        //         'updated_at' => optional($existing->updated_at)->toDateTimeString(),
        //     ],
        //     'incoming_hash' => $incomingHash,
        //     'has_changed' => $hasChanged,
        //     'reason' => $hasChanged ? 'Stored hash differs from incoming tracked metafield hash.' : 'Stored hash matches incoming tracked metafield hash.',
        //     'changed_fields' => $this->diffTrackedSnapshot($storedSnapshot, $incomingTrackedSnapshot),
        //     'stored_tracked_snapshot' => $storedSnapshot,
        //     'incoming_tracked_snapshot' => $incomingTrackedSnapshot,
        // ]);

        return $hasChanged;
    }

    public function upsertProductSnapshot(array $productSnapshot): CarrotJewelleryProduct
    {
        // Diamond fields are only written when the snapshot carries them, so flows
        // that don't fetch diamond metafields keep the values already stored.
        $stored = CarrotJewelleryProduct::where('shopify_product_id', $productSnapshot['product_gid'])->first();

        return CarrotJewelleryProduct::updateOrCreate(
            [
                'shopify_product_id' => $productSnapshot['product_gid'],
            ],
            [
                'shopify_product_numeric_id' => $this->extractNumericId($productSnapshot['product_gid']),
                'title' => $productSnapshot['product_title'],
                'gold_weight' => $this->normalizeValue($productSnapshot['goldWeight'] ?? null),
                '9k_gold_weight' => $this->normalizeValue($productSnapshot['9kGoldWeight'] ?? null),
                'making_charge' => $this->normalizeValue($productSnapshot['makingCharge'] ?? null),
                'diamond_weight' => $this->normalizeValue(
                    array_key_exists('diamond_weight', $productSnapshot) ? $productSnapshot['diamond_weight'] : $stored?->diamond_weight
                ),
                'diamond_count' => $this->normalizeValue(
                    array_key_exists('diamond_count', $productSnapshot) ? $productSnapshot['diamond_count'] : $stored?->diamond_count
                ),
                'tags' => array_key_exists('tags', $productSnapshot) ? $productSnapshot['tags'] : $stored?->tags,
                'variants' => $this->normalizeVariants($productSnapshot['variants'] ?? [], $stored?->variants ?? []),
                'metafield_hash' => $this->hashSnapshot($productSnapshot),
            ]
        );
    }

    public function upsertFromVariantRows(array $variants): int
    {
        $grouped = [];

        foreach ($variants as $variant) {
            $productId = $variant['product_gid'] ?? null;
            if (empty($productId)) {
                continue;
            }

            if (!isset($grouped[$productId])) {
                $grouped[$productId] = [
                    'product_gid' => $productId,
                    'product_title' => $variant['product_title'] ?? '',
                    'goldWeight' => $variant['goldWeight'] ?? null,
                    '9kGoldWeight' => $variant['9kGoldWeight'] ?? null,
                    'makingCharge' => $variant['makingCharge'] ?? null,
                    'variants' => [],
                ];
            }

            $grouped[$productId]['variants'][] = [
                'gid' => $variant['gid'] ?? null,
                'title' => $variant['variant_title'] ?? '',
                'goldWeight' => $variant['variantGoldWeight'] ?? null,
                '9kGoldWeight' => $variant['variant9KGoldWeight'] ?? null,
                'makingCharge' => $variant['variantMakingCharge'] ?? null,
            ];
        }

        foreach ($grouped as $productSnapshot) {
            $this->upsertProductSnapshot($productSnapshot);
        }

        return count($grouped);
    }

    public function upsertProductSnapshots(array $productSnapshots): int
    {
        $count = 0;

        foreach ($productSnapshots as $productSnapshot) {
            if (empty($productSnapshot['product_gid'])) {
                continue;
            }

            $this->upsertProductSnapshot($productSnapshot);
            $count++;
        }

        return $count;
    }

    public function updatePackageWeights(array $productWeights): int
    {
        $count = 0;

        foreach ($productWeights as $productWeight) {
            $productId = $productWeight['product_gid'] ?? null;
            if (empty($productId)) {
                continue;
            }

            $variants = $this->normalizePackageWeightVariants($productWeight['variants'] ?? []);
            $productPackageWeight = $this->firstVariantPackageWeight($variants);
            $storeVariantWeights = !$this->isDefaultOnlyProduct($variants);

            $existing = CarrotJewelleryProduct::firstOrNew([
                'shopify_product_id' => $productId,
            ]);

            $existingVariants = is_array($existing->variants) ? $existing->variants : [];

            $existing->fill([
                'shopify_product_numeric_id' => $existing->shopify_product_numeric_id ?: $this->extractNumericId($productId),
                'title' => $productWeight['product_title'] ?? $existing->title,
                '9k_gold_weight' => $productPackageWeight,
                'variants' => $this->mergePackageWeightVariants($existingVariants, $variants, $storeVariantWeights),
            ]);

            if (empty($existing->metafield_hash)) {
                $existing->metafield_hash = $this->hashSnapshot([
                    'goldWeight' => $existing->gold_weight,
                    '9kGoldWeight' => $existing->{'9k_gold_weight'},
                    'makingCharge' => $existing->making_charge,
                    'variants' => $existing->variants ?? [],
                ]);
            }

            $existing->save();
            $count++;
        }

        return $count;
    }

    public function buildPackageWeightMetafields(array $productWeights): array
    {
        $metafields = [];

        foreach ($productWeights as $productWeight) {
            $productId = $productWeight['product_gid'] ?? null;
            if (empty($productId)) {
                continue;
            }

            $variants = $this->normalizePackageWeightVariants($productWeight['variants'] ?? []);
            $productPackageWeight = $this->firstVariantPackageWeight($variants);
            $storeVariantWeights = !$this->isDefaultOnlyProduct($variants);

            if ($productPackageWeight !== null) {
                $metafields[] = $this->netWeightMetafieldInput($productId, $productPackageWeight);
            }

            if (!$storeVariantWeights) {
                continue;
            }

            foreach ($variants as $variant) {
                if (($variant['9kGoldWeight'] ?? null) === null) {
                    continue;
                }

                $metafields[] = $this->netWeightMetafieldInput($variant['gid'], $variant['9kGoldWeight']);
            }
        }

        return $metafields;
    }

    public function hashSnapshot(array $productSnapshot): string
    {
        return hash('sha256', json_encode($this->trackedSnapshot($productSnapshot)));
    }

    private function trackedSnapshot(array $productSnapshot): array
    {
        $tags = $productSnapshot['tags'] ?? [];
        sort($tags);

        return [
            'goldWeight' => $this->normalizeValue($productSnapshot['goldWeight'] ?? null),
            '9kGoldWeight' => $this->normalizeValue($productSnapshot['9kGoldWeight'] ?? null),
            'makingCharge' => $this->normalizeValue($productSnapshot['makingCharge'] ?? null),
            'diamond_weight' => $this->normalizeValue($productSnapshot['diamond_weight'] ?? null),
            'diamond_count' => $this->normalizeValue($productSnapshot['diamond_count'] ?? null),
            'tags' => $tags,
            'variants' => $this->normalizeTrackedVariants($productSnapshot['variants'] ?? []),
        ];
    }

    private function storedTrackedVariants(array $variants): array
    {
        return array_map(
            fn ($variant) => [
                'gid' => $variant['gid'] ?? null,
                'goldWeight' => $variant['goldWeight'] ?? null,
                '9kGoldWeight' => $variant['9kGoldWeight'] ?? null,
                'makingCharge' => $variant['makingCharge'] ?? null,
            ],
            $variants
        );
    }

    private function diffTrackedSnapshot(array $stored, array $incoming): array
    {
        $changes = [];

        foreach (['goldWeight', '9kGoldWeight', 'makingCharge'] as $field) {
            if (($stored[$field] ?? null) !== ($incoming[$field] ?? null)) {
                $changes[$field] = [
                    'stored' => $stored[$field] ?? null,
                    'incoming' => $incoming[$field] ?? null,
                ];
            }
        }

        $storedVariants = collect($stored['variants'] ?? [])->keyBy('gid');
        $incomingVariants = collect($incoming['variants'] ?? [])->keyBy('gid');

        foreach ($storedVariants->keys()->merge($incomingVariants->keys())->unique()->sort()->values() as $gid) {
            $storedVariant = $storedVariants->get($gid);
            $incomingVariant = $incomingVariants->get($gid);

            if ($storedVariant !== $incomingVariant) {
                $changes['variants'][$gid] = [
                    'stored' => $storedVariant,
                    'incoming' => $incomingVariant,
                ];
            }
        }

        return $changes;
    }

    private function normalizeTrackedVariants(array $variants): array
    {
        return array_map(
            fn ($variant) => [
                'gid' => $variant['gid'] ?? null,
                'goldWeight' => $variant['goldWeight'] ?? null,
                '9kGoldWeight' => $variant['9kGoldWeight'] ?? null,
                'makingCharge' => $variant['makingCharge'] ?? null,
                'diamond_weight' => $variant['diamond_weight'] ?? null,
                'diamond_count' => $variant['diamond_count'] ?? null,
            ],
            $this->normalizeVariants($variants)
        );
    }

    private function normalizeVariants(array $variants, array $storedVariants = []): array
    {
        $normalized = [];
        $storedByGid = collect($storedVariants)->keyBy('gid');

        foreach ($variants as $variant) {
            $gid = $variant['gid'] ?? null;
            if (empty($gid)) {
                continue;
            }

            $stored = $storedByGid->get($gid, []);

            $normalized[] = [
                'gid' => $gid,
                'title' => $variant['title'] ?? $variant['variant_title'] ?? '',
                'goldWeight' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variantGoldWeight', 'goldWeight')),
                'makingCharge' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variantMakingCharge', 'makingCharge')),
                '9kGoldWeight' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variant9KGoldWeight', '9kGoldWeight')),
                'diamond_weight' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variantDiamondWeight', 'diamond_weight', $stored['diamond_weight'] ?? null)),
                'diamond_count' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variantDiamondCount', 'diamond_count', $stored['diamond_count'] ?? null)),
            ];
        }

        usort($normalized, fn ($left, $right) => strcmp($left['gid'], $right['gid']));

        return $normalized;
    }

    private function variantMetafieldValue(array $variant, string $variantKey, string $legacyKey, $fallback = null)
    {
        if (array_key_exists($variantKey, $variant)) {
            return $variant[$variantKey];
        }

        return $variant[$legacyKey] ?? $fallback;
    }

    private function normalizeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }

        return $value;
    }

    private function normalizePackageWeightVariants(array $variants): array
    {
        $normalized = [];

        foreach ($variants as $variant) {
            $gid = $variant['gid'] ?? null;
            if (empty($gid)) {
                continue;
            }

            $weight = $this->normalizePackageWeightValue(
                $variant['packageWeight'] ?? null,
                $variant['packageWeightUnit'] ?? null
            );

            $normalized[] = [
                'gid' => $gid,
                'title' => $variant['title'] ?? '',
                '9kGoldWeight' => $weight,
            ];
        }

        usort($normalized, fn ($left, $right) => strcmp($left['gid'], $right['gid']));

        return $normalized;
    }

    private function firstVariantPackageWeight(array $variants): ?string
    {
        foreach ($variants as $variant) {
            if (($variant['9kGoldWeight'] ?? null) !== null) {
                return $variant['9kGoldWeight'];
            }
        }

        return null;
    }

    private function normalizePackageWeightValue($value, ?string $unit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return $this->normalizeValue($value);
        }

        $weight = (float) $value;

        switch (strtoupper((string) $unit)) {
            case 'KILOGRAMS':
            case 'KILOGRAM':
            case 'KG':
                $weight *= 1000;
                break;
            case 'POUNDS':
            case 'POUND':
            case 'LB':
                $weight *= 453.59237;
                break;
            case 'OUNCES':
            case 'OUNCE':
            case 'OZ':
                $weight *= 28.349523125;
                break;
        }

        return $this->normalizeValue($weight);
    }

    private function isDefaultOnlyProduct(array $variants): bool
    {
        return count($variants) === 1
            && (($variants[0]['title'] ?? '') === 'Default Title');
    }

    private function netWeightMetafieldInput(string $ownerId, string $value): array
    {
        return [
            'ownerId' => $ownerId,
            'namespace' => 'custom',
            'key' => 'net_weight',
            'type' => 'number_decimal',
            'value' => $value,
        ];
    }

    private function mergePackageWeightVariants(array $existingVariants, array $packageWeightVariants, bool $storeVariantWeights): array
    {
        $merged = collect($existingVariants)->keyBy('gid')->all();

        foreach ($packageWeightVariants as $variant) {
            $gid = $variant['gid'];
            $existing = $merged[$gid] ?? [];

            $merged[$gid] = [
                'gid' => $gid,
                'title' => $variant['title'] ?: ($existing['title'] ?? ''),
                'goldWeight' => $existing['goldWeight'] ?? null,
                'makingCharge' => $existing['makingCharge'] ?? null,
                '9kGoldWeight' => $storeVariantWeights ? $variant['9kGoldWeight'] : null,
                'diamond_weight' => $existing['diamond_weight'] ?? null,
                'diamond_count' => $existing['diamond_count'] ?? null,
            ];
        }

        $merged = array_values($merged);
        usort($merged, fn ($left, $right) => strcmp($left['gid'] ?? '', $right['gid'] ?? ''));

        return $merged;
    }

    private function extractNumericId(string $gid): ?string
    {
        if (preg_match('/(\d+)$/', $gid, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
