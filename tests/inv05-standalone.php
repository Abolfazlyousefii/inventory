<?php
/**
 * INV-05 focused executable regression tests that do not require Laravel's vendor tree.
 * Run: php tests/inv05-standalone.php
 * The complete integration tests still require the full Laravel checkout.
 */
namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException
    {
        public function __construct(public array $errors) { parent::__construct('Validation failed'); }
        public static function withMessages(array $messages): self { return new self($messages); }
    }
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class FakeCollection
    {
        public function __construct(private array $rows) {}
        public function pluck(string $field): self { return new self(array_map(fn($r) => is_array($r) ? ($r[$field] ?? null) : ($r->$field ?? null), $this->rows)); }
        public function unique(): self { return new self(array_values(array_unique($this->rows))); }
        public function get(int $id, mixed $default = null): mixed { return $this->rows[$id] ?? $default; }
        public function keyBy(string $field): self { $out=[]; foreach ($this->rows as $r) $out[$r->$field] = $r; return new self($out); }
        public function all(): array { return $this->rows; }
    }
    class FakeQuery
    {
        public function __construct(private array $rows) {}
        public function whereIn(string $field, mixed $ids): self { $ids = $ids instanceof FakeCollection ? $ids->all() : $ids; $this->rows = array_values(array_filter($this->rows, fn($r) => in_array($r->$field, $ids, true))); return $this; }
        public function orderBy(string $field): self { usort($this->rows, fn($a,$b) => $a->$field <=> $b->$field); return $this; }
        public function lockForUpdate(): self { return $this; }
        public function get(array $cols = []): FakeCollection { return new FakeCollection($this->rows); }
        public function pluck(string $field, string $key): FakeCollection { $out=[]; foreach ($this->rows as $r) $out[$r->$key] = $r->$field; return new FakeCollection($out); }
    }
    class Product
    {
        public static array $rows = [];
        public static function query(): FakeQuery { return new FakeQuery(self::$rows); }
    }
    class ProductVariant
    {
        public static array $rows = [];
        public static function query(): FakeQuery { return new FakeQuery(self::$rows); }
    }
}
namespace {
    function collect(array $rows): \App\Models\FakeCollection { return new \App\Models\FakeCollection($rows); }
    require __DIR__.'/../app/Http/Controllers/PreinvoiceController.php';

    function verify(string $name, ?int $catalogPrice, ?int $draftPrice, bool $shouldReject): void
    {
        \App\Models\Product::$rows = [(object)['id'=>100, 'name'=>'Example', 'is_sellable'=>true]];
        \App\Models\ProductVariant::$rows = [(object)['id'=>200, 'product_id'=>100, 'variant_name'=>'Sample', 'is_active'=>true, 'sales_enabled'=>true, 'sell_price'=>$catalogPrice]];
        $controller = (new \ReflectionClass(\App\Http\Controllers\PreinvoiceController::class))->newInstanceWithoutConstructor();
        $row = ['id'=>100, 'variety_id'=>200] + ($draftPrice === null ? [] : ['price'=>$draftPrice]);
        $rejected = false;
        try {
            (new \ReflectionMethod($controller, 'assertDraftItemsSellableForSubmission'))->invoke($controller, [$row]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $rejected = true;
            if (!isset($e->errors['products.0.price'])) throw new \RuntimeException($name.' rejected for wrong reason: '.json_encode($e->errors));
        }
        if ($rejected !== $shouldReject) throw new \RuntimeException("{$name}: expected rejection=".($shouldReject?'true':'false').', observed='.($rejected?'true':'false'));
        echo "PASS {$name}\n";
    }
    verify('positive draft cannot override zero catalog price', 0, 7500, true);
    verify('positive draft cannot override negative catalog price', -1, 7500, true);
    verify('positive draft cannot override missing catalog price', null, 7500, true);
    verify('positive draft is valid with positive catalog price', 7500, 7500, false);
    verify('zero draft rejected with positive catalog price', 7500, 0, true);
    verify('fallback catalog price can be used for missing draft price', 7500, null, false);
    verify('missing draft and zero catalog rejected', 0, null, true);
    // Even with a positive price, a previously disabled variant cannot be submitted.
    \App\Models\Product::$rows = [(object)['id'=>100,'name'=>'Example','is_sellable'=>true]];
    \App\Models\ProductVariant::$rows = [(object)['id'=>200,'product_id'=>100,'variant_name'=>'Sample','is_active'=>true,'sales_enabled'=>false,'sell_price'=>7500]];
    $controller = (new \ReflectionClass(\App\Http\Controllers\PreinvoiceController::class))->newInstanceWithoutConstructor();
    try {
        (new \ReflectionMethod($controller,'assertDraftItemsSellableForSubmission'))->invoke($controller, [['id'=>100,'variety_id'=>200,'price'=>7500]]);
        throw new \RuntimeException('disabled variant passed submission');
    } catch (\Illuminate\Validation\ValidationException $e) {
        if (!isset($e->errors['products.0.variety_id'])) throw $e;
        echo "PASS disabled variant cannot be submitted even with a positive price\n";
    }
    echo "All INV-05 price-submission regression cases passed.\n";
}
