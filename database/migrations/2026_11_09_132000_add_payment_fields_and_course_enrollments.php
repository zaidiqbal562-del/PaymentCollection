<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('INR')->after('amount');
            $table->string('failure_code')->nullable()->after('status');
            $table->text('failure_description')->nullable()->after('failure_code');
            $table->timestamp('captured_at')->nullable()->after('failure_description');
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'currency',
                'failure_code',
                'failure_description',
                'captured_at',
            ]);
        });
    }
};
