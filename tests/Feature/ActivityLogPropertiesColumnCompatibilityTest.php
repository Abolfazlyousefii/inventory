<?php

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use App\Support\ActivityLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('persists activity events into a legacy properties2 column without modifying its large table', function (): void {
    Schema::dropIfExists('activity_logs');

    Schema::create('activity_logs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('action');
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->text('description');
        $table->longText('properties2')->nullable();
        $table->timestamp('occurred_at')->nullable();
        $table->timestamps();
    });

    $subject = new ProductVariant;
    $subject->id = 22973;

    ActivityLogger::logForActor(null, 'purchase_sale_price_snapshot_corrected', $subject, 'Test event', [
        'purchase_id' => 803,
        'variant_id' => 22973,
    ]);

    $row = DB::table('activity_logs')->first();
    expect($row)->not->toBeNull()
        ->and(json_decode($row->properties2, true))->toMatchArray([
            'purchase_id' => 803,
            'variant_id' => 22973,
        ])
        ->and(ActivityLog::first()->properties)->toMatchArray([
            'purchase_id' => 803,
            'variant_id' => 22973,
        ]);
});

it('continues to persist events to the standard properties column', function (): void {
    Schema::dropIfExists('activity_logs');

    Schema::create('activity_logs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('action');
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->text('description');
        $table->json('properties')->nullable();
        $table->timestamp('occurred_at')->nullable();
        $table->timestamps();
    });

    $subject = new ProductVariant;
    $subject->id = 22973;

    ActivityLogger::logForActor(null, 'purchase_sale_price_snapshot_corrected', $subject, 'Test event', [
        'purchase_id' => 803,
    ]);

    $row = DB::table('activity_logs')->first();
    expect($row)->not->toBeNull()
        ->and(json_decode($row->properties, true))->toMatchArray(['purchase_id' => 803])
        ->and(ActivityLog::first()->properties)->toMatchArray(['purchase_id' => 803]);
});
