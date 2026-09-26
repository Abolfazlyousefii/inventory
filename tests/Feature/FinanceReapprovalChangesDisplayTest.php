<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Invoice;
use App\Models\SalesHavalehHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('shows recorded additions removals and before-after money values instead of current invoice items', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $invoice = Invoice::query()->create([
        'uuid' => 'REAPPROVAL-DIFF-1',
        'customer_name' => 'مشتری تست',
        'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
        'total' => 18000,
        'items_updated_at' => now(),
    ]);
    $revisionId = DB::table('invoice_collection_revisions')->insertGetId([
        'invoice_id' => $invoice->id,
        'revision_number' => 1,
        'old_total' => 20000,
        'new_total' => 18000,
        'reason_type' => 'warehouse_queue_items',
        'reason_note' => 'اصلاح انبار',
        'changed_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([
        ['change_type' => 'removed', 'product_name_snapshot' => 'کالای حذف‌شده', 'old_quantity' => 2, 'old_price' => 5000, 'old_discount' => 0, 'old_line_total' => 10000],
        ['change_type' => 'added', 'product_name_snapshot' => 'کالای افزوده‌شده', 'new_quantity' => 1, 'new_price' => 8000, 'new_discount' => 0, 'new_line_total' => 8000],
    ] as $row) {
        DB::table('invoice_collection_revision_items')->insert($row + [
            'invoice_collection_revision_id' => $revisionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $secondRevisionId = DB::table('invoice_collection_revisions')->insertGetId([
        'invoice_id' => $invoice->id,
        'revision_number' => 2,
        'old_total' => 18000,
        'new_total' => 18000,
        'reason_type' => 'price_correction',
        'changed_by' => $actor->id,
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);
    DB::table('invoice_collection_revision_items')->insert([
        'invoice_collection_revision_id' => $secondRevisionId,
        'change_type' => 'multiple_changes',
        'product_name_snapshot' => 'کالای اصلاح‌شده',
        'old_quantity' => 2,
        'new_quantity' => 2,
        'old_price' => 9000,
        'new_price' => 9500,
        'old_discount' => 1000,
        'new_discount' => 2000,
        'old_line_total' => 17000,
        'new_line_total' => 17000,
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    $this->get(route('preinvoice.draft.index', ['tab' => 'reapprovals']))
        ->assertOk()
        ->assertSee('کالای حذف‌شده')
        ->assertSee('کالای افزوده‌شده')
        ->assertSee('کالای اصلاح‌شده')
        ->assertSee('ویرایش 2')
        ->assertSee('اضافه‌شده')
        ->assertSee('حذف‌شده')
        ->assertSee('diff-item is-added')
        ->assertSee('diff-item is-removed')
        ->assertSee('قبل: 2')
        ->assertSee('بعد: 1')
        ->assertSee('20,000')
        ->assertSee('18,000')
        ->assertDontSee('snapshot قبل از تغییر برای مقایسه دقیق وجود ندارد');

    $this->get(route('preinvoice.draft.index', ['tab' => 'expired']))
        ->assertOk();
});

it('labels older text-only audits as incomplete rather than inventing previous values', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->actingAs(User::factory()->create());
    $invoice = Invoice::query()->create([
        'uuid' => 'REAPPROVAL-DIFF-LEGACY',
        'customer_name' => 'مشتری تست',
        'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
        'total' => 7000,
    ]);
    SalesHavalehHistory::query()->create([
        'invoice_id' => $invoice->id,
        'action_type' => 'item_removed',
        'field_name' => 'items',
        'description' => 'کالای قدیمی حذف شد.',
        'done_at' => now(),
    ]);

    $this->get(route('preinvoice.draft.index', ['tab' => 'reapprovals']))
        ->assertOk()
        ->assertSee('کالای قدیمی حذف شد.')
        ->assertSee('قابل بازسازی دقیق نیست');
});
