<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manup_order_data', function (Blueprint $table) {
            $table->id();

            $table->string('order_id')->unique();

            // Payload sent to Meradoc
            $table->json('request_payload');

            // Webhook payload received later
            $table->json('webhook_payload')->nullable();

            // ASSIGNED, success, failed, etc.
            $table->string('status')->nullable();

            // ASSIGNED, success, failed, etc.
            $table->text('prescription_image')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manup_order_data');
    }
};
