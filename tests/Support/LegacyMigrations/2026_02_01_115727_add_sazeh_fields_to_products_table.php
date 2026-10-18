<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // کد کالا در سازه
            $table->string('code')->nullable()->index()->after('id');

            // باركد/اختصاري
            $table->string('short_barcode')->nullable()->after('code');

            // واحد
            $table->string('unit')->nullable()->after('stock');

            // فروش / خرید
            $table->unsignedBigInteger('sale_retail')->nullable()->after('price');     // ف جزئي
            $table->unsignedBigInteger('sale_wholesale')->nullable()->after('sale_retail'); // ف كلي
            $table->unsignedBigInteger('buy_retail')->nullable()->after('sale_wholesale');  // خ جزئي
            $table->unsignedBigInteger('buy_wholesale')->nullable()->after('buy_retail');   // خ كلي

            // رزرو
            $table->unsignedInteger('reserved')->default(0)->after('buy_wholesale');

            // بارکد
            $table->string('barcode')->nullable()->index()->after('reserved');

            // رنگ
            $table->string('color')->nullable()->after('barcode');

            // اگر این ستون رو قبلاً برای کم‌موجودی ساخته بودی و دیگه نمی‌خوای:
            if (Schema::hasColumn('products', 'low_stock_threshold')) {
                $table->dropColumn('low_stock_threshold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'code','short_barcode','unit',
                'sale_retail','sale_wholesale','buy_retail','buy_wholesale',
                'reserved','barcode','color'
            ]);
            // برگشت low_stock_threshold لازم نیست
        });
    }
};
