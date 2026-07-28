<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Services\SalesReturnNewProductPayloadNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnNewProductSnapshotNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_or_null_category_snapshot_is_rebuilt_without_an_undefined_key_error(): void
    {
        $root = Category::query()->create(['name' => 'ریشه', 'code' => '10']);
        $child = Category::query()->create(['name' => 'نهایی', 'code' => '20', 'parent_id' => $root->id]);
        $normalizer = app(SalesReturnNewProductPayloadNormalizer::class);

        foreach ([[], ['category_path_snapshot' => null], ['category_path_snapshot' => 'invalid']] as $extra) {
            $payload = $normalizer->normalize(array_replace([
                'schema_version' => 2,
                'category_id' => $child->id,
            ], $extra));

            $this->assertSame([$root->id, $child->id], collect($payload['category_path_snapshot'])->pluck('id')->all());
        }
    }

    public function test_valid_existing_category_snapshot_is_preserved(): void
    {
        $category = Category::query()->create(['name' => 'نهایی', 'code' => '20']);
        $snapshot = [['id' => 999, 'name' => 'Snapshot قدیمی', 'code' => '99']];

        $payload = app(SalesReturnNewProductPayloadNormalizer::class)->normalize([
            'schema_version' => 2,
            'category_id' => $category->id,
            'category_path_snapshot' => $snapshot,
        ]);

        $this->assertSame($snapshot, $payload['category_path_snapshot']);
    }
}
