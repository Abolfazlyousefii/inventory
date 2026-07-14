<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use DOMDocument;
use DOMXPath;

class MyPreinvoiceWorkspaceDomStructureTest extends MyPreinvoiceWorkspaceTest
{
    public function test_empty_workspace_renders_single_wrapper_without_orphan_documents(): void
    {
        $seller = User::factory()->create();

        $content = $this->actingAs($seller)
            ->get(route('preinvoice.my.index', ['tab' => 'active']))
            ->assertOk()
            ->assertSee('فاکتورها و پیش‌فاکتورهای من')
            ->getContent();

        $xpath = $this->xpath($content);

        $this->assertSame(1, (int) $xpath->evaluate('count(//*[@class and contains(concat(" ", normalize-space(@class), " "), " my-sales-page ")])'));
        $this->assertSame(1, (int) $xpath->evaluate('count(//*[@data-sales-documents])'));
        $this->assertSame(0, (int) $xpath->evaluate('count(//*[@data-sales-documents]//article[contains(concat(" ", normalize-space(@class), " "), " my-sales-document ")])'));
        $this->assertSame(0, (int) $xpath->evaluate('count(//main/following::article[contains(concat(" ", normalize-space(@class), " "), " my-sales-document ")])'));
    }

    public function test_documents_stay_inside_documents_wrapper_and_before_layout_main_closes(): void
    {
        $seller = User::factory()->create();
        $active = $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid' => 'PI-DOM-1']);
        $converted = $this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid' => 'PI-DOM-2']);
        $this->invoice($converted, Invoice::STATUS_PENDING_COLLECTION, ['uuid' => 'INV-DOM-2']);
        for ($i = 3; $i <= 20; $i++) {
            $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid' => 'PI-DOM-' . $i]);
        }

        $content = $this->actingAs($seller)
            ->get(route('preinvoice.my.index', ['tab' => 'active']))
            ->assertOk()
            ->getContent();

        $xpath = $this->xpath($content);
        $articleCount = (int) $xpath->evaluate('count(//*[@data-sales-documents]//article[contains(concat(" ", normalize-space(@class), " "), " my-sales-document ")])');

        $this->assertSame(20, $articleCount);
        $this->assertSame($articleCount, (int) $xpath->evaluate('count(//article[contains(concat(" ", normalize-space(@class), " "), " my-sales-document ")])'));
        $this->assertSame(1, (int) $xpath->evaluate('count(//main)'));
        $this->assertStringNotContainsString('<article class="my-sales-document', substr($content, strpos($content, '</main>') ?: strlen($content)));
    }

    public function test_tabs_and_details_markup_are_accessible_without_nested_interactive_elements(): void
    {
        $seller = User::factory()->create();
        $returned = $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid' => 'PI-RETURNED-DOM']);
        $draft = $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid' => 'PI-DRAFT-DOM']);

        $content = $this->actingAs($seller)
            ->get(route('preinvoice.my.index', ['tab' => 'needs_correction']))
            ->assertOk()
            ->getContent();

        $xpath = $this->xpath($content);

        $this->assertSame(4, (int) $xpath->evaluate('count(//nav[contains(concat(" ", normalize-space(@class), " "), " my-sales-tabs ")]//a[@role="tab"])'));
        $this->assertGreaterThanOrEqual(1, (int) $xpath->evaluate('count(//*[@data-document-details][@hidden])'));
        $this->assertSame(0, (int) $xpath->evaluate('count(//button//a | //button//button | //a//button)'));
    }

    private function xpath(string $content): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
