<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mall_hookup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mall_hookup_id')->constrained('mall_hookups')->cascadeOnDelete();
            $table->date('log_date');
            $table->string('status');           // yes | no | na | for_accreditation
            $table->string('remark')->nullable();
            // Plain audit columns — avoid extra FK cascade paths on SQL Server.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['mall_hookup_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_hookup_logs');
    }
};
