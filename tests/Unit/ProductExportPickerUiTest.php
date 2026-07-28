<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductExportPickerUiTest extends TestCase
{
    public function test_picker_keeps_live_search_race_safety_and_keyboard_contract(): void
    {
        $view = file_get_contents(resource_path('views/product-exports/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('setTimeout(() => requestProducts(term, true), 275)', $view);
        $this->assertStringContainsString('new AbortController()', $view);
        $this->assertStringContainsString('requestSequence', $view);
        $this->assertStringContainsString("event.key === 'ArrowDown'", $view);
        $this->assertStringContainsString("event.key === 'ArrowUp'", $view);
        $this->assertStringContainsString("event.key === 'Enter'", $view);
        $this->assertStringContainsString("event.key === 'Escape'", $view);
        $this->assertStringContainsString("event.key === 'Backspace'", $view);
    }

    public function test_picker_renders_search_results_without_injecting_server_html(): void
    {
        $view = file_get_contents(resource_path('views/product-exports/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("document.createElement('mark')", $view);
        $this->assertStringContainsString('document.createTextNode(', $view);
        $this->assertStringContainsString('product.matched_variant', $view);
        $this->assertStringContainsString('product.availability', $view);
        $this->assertStringContainsString('محصولی پیدا نشد.', $view);
        $this->assertStringContainsString('دریافت محصولات با خطا روبه‌رو شد. دوباره تلاش کنید.', $view);
    }
}
