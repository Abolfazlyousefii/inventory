<?php
// database/migrations/xxxx_xx_xx_create_product_variants_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('variant_name');         // مدل: iphone 16 pro
            $table->unsignedBigInteger('variety_id')->nullable();  // آیدیش در CRM
            $table->string('unique_key')->nullable(); // اگر CRM می‌دهد

            $table->unsignedBigInteger('sell_price')->default(0);
            $table->unsignedBigInteger('buy_price')->nullable();   // اگر داری/بعداً داری
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reserved')->default(0);

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variety_id']); // اگر variety_id همیشه یکتا است
            // یا اگر variety_id همیشه نیست:
            // $table->unique(['product_id', 'unique_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
