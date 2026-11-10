<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('razorpay_order_id')
                ->nullable()
                ->unique()
                ->after('order_number');

            $table->string('payment_id')
                ->nullable()
                ->after('razorpay_order_id');

            $table->string('receipt')
                ->unique()
                ->after('payment_id');

            $table->text('failure_reason')
                ->nullable()
                ->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['razorpay_order_id']);
            $table->dropUnique(['receipt']);

            $table->dropColumn([
                'razorpay_order_id',
                'payment_id',
                'receipt',
                'failure_reason',
            ]);
        });
    }
};