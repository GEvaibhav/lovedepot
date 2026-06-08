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
            'makingCharge' => $existing->making_charge,
            'variants' => $this->storedTrackedVariants($existing->variants ?? []),
        ];
        $hasChanged = $existing->metafield_hash !== $incomingHash;

        Log::channel('pricesync')->info('Product metafield snapshot comparison result', [
            'product_id' => $productSnapshot['product_gid'],
            'query' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'product_snapshot' => $productSnapshot,
            'stored_row' => [
                'id' => $existing->id,
                'shopify_product_id' => $existing->shopify_product_id,
                'shopify_product_numeric_id' => $existing->shopify_product_numeric_id,
                'title' => $existing->title,
                'metafield_hash' => $existing->metafield_hash,
                'updated_at' => optional($existing->updated_at)->toDateTimeString(),
            ],
            'incoming_hash' => $incomingHash,
            'has_changed' => $hasChanged,
            'reason' => $hasChanged ? 'Stored hash differs from incoming tracked metafield hash.' : 'Stored hash matches incoming tracked metafield hash.',
            'changed_fields' => $this->diffTrackedSnapshot($storedSnapshot, $incomingTrackedSnapshot),
            'stored_tracked_snapshot' => $storedSnapshot,
            'incoming_tracked_snapshot' => $incomingTrackedSnapshot,
        ]);

        return $hasChanged;
    }

    public function upsertProductSnapshot(array $productSnapshot): CarrotJewelleryProduct
    {
        return CarrotJewelleryProduct::updateOrCreate(
            [
                'shopify_product_id' => $productSnapshot['product_gid'],
            ],
            [
                'shopify_product_numeric_id' => $this->extractNumericId($productSnapshot['product_gid']),
                'title' => $productSnapshot['product_title'],
                'gold_weight' => $this->normalizeValue($productSnapshot['goldWeight'] ?? null),
                'making_charge' => $this->normalizeValue($productSnapshot['makingCharge'] ?? null),
                'variants' => $this->normalizeVariants($productSnapshot['variants'] ?? []),
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
                    'makingCharge' => $variant['makingCharge'] ?? null,
                    'variants' => [],
                ];
            }

            $grouped[$productId]['variants'][] = [
                'gid' => $variant['gid'] ?? null,
                'title' => $variant['variant_title'] ?? '',
                'goldWeight' => $variant['variantGoldWeight'] ?? null,
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

    public function hashSnapshot(array $productSnapshot): string
    {
        return hash('sha256', json_encode($this->trackedSnapshot($productSnapshot)));
    }

    private function trackedSnapshot(array $productSnapshot): array
    {
        return [
            'goldWeight' => $this->normalizeValue($productSnapshot['goldWeight'] ?? null),
            'makingCharge' => $this->normalizeValue($productSnapshot['makingCharge'] ?? null),
            'variants' => $this->normalizeTrackedVariants($productSnapshot['variants'] ?? []),
        ];
    }

    private function storedTrackedVariants(array $variants): array
    {
        return array_map(
            fn ($variant) => [
                'gid' => $variant['gid'] ?? null,
                'goldWeight' => $variant['goldWeight'] ?? null,
                'makingCharge' => $variant['makingCharge'] ?? null,
            ],
            $variants
        );
    }

    private function diffTrackedSnapshot(array $stored, array $incoming): array
    {
        $changes = [];

        foreach (['goldWeight', 'makingCharge'] as $field) {
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
                'gid' => $variant['gid'],
                'goldWeight' => $variant['goldWeight'],
                'makingCharge' => $variant['makingCharge'],
            ],
            $this->normalizeVariants($variants)
        );
    }

    private function normalizeVariants(array $variants): array
    {
        $normalized = [];

        foreach ($variants as $variant) {
            $gid = $variant['gid'] ?? null;
            if (empty($gid)) {
                continue;
            }

            $normalized[] = [
                'gid' => $gid,
                'title' => $variant['title'] ?? $variant['variant_title'] ?? '',
                'goldWeight' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variantGoldWeight', 'goldWeight')),
                'makingCharge' => $this->normalizeValue($this->variantMetafieldValue($variant, 'variantMakingCharge', 'makingCharge')),
            ];
        }

        usort($normalized, fn ($left, $right) => strcmp($left['gid'], $right['gid']));

        return $normalized;
    }

    private function variantMetafieldValue(array $variant, string $variantKey, string $legacyKey)
    {
        if (array_key_exists($variantKey, $variant)) {
            return $variant[$variantKey];
        }

        return $variant[$legacyKey] ?? null;
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

    private function extractNumericId(string $gid): ?string
    {
        if (preg_match('/(\d+)$/', $gid, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
