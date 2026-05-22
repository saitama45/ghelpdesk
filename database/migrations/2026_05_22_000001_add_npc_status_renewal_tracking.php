<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npc_status_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->date('validity_from');
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['npc_status_id', 'type', 'validity_from']);
        });

        Schema::create('npc_status_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('label', 120);
            $table->unsignedTinyInteger('sort_order');
            $table->boolean('is_done')->default(false);
            $table->date('completed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['npc_status_id', 'key']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('cctv_seal_notice_path')->nullable()->after('is_active');
            $table->string('cctv_seal_notice_name')->nullable()->after('cctv_seal_notice_path');
            $table->string('cctv_seal_notice_mime_type', 120)->nullable()->after('cctv_seal_notice_name');
            $table->unsignedBigInteger('cctv_seal_notice_size')->nullable()->after('cctv_seal_notice_mime_type');
            $table->timestamp('cctv_seal_notice_uploaded_at')->nullable()->after('cctv_seal_notice_size');
            $table->foreignId('cctv_seal_notice_uploaded_by')->nullable()->after('cctv_seal_notice_uploaded_at')->constrained('users')->nullOnDelete();
        });

        $now = now();
        $rows = DB::table('npc_statuses')->get();

        foreach ($rows as $row) {
            foreach ([
                'dpo_seal' => 'dpo_seal',
                'dpo_registration' => 'dpo_registration',
            ] as $type => $prefix) {
                $path = $row->{$prefix . '_path'} ?? null;

                if (!$path) {
                    continue;
                }

                DB::table('npc_status_attachments')->insert([
                    'npc_status_id' => $row->id,
                    'type' => $type,
                    'validity_from' => $row->validity_from,
                    'file_path' => $path,
                    'file_name' => $row->{$prefix . '_name'} ?? null,
                    'mime_type' => $row->{$prefix . '_mime_type'} ?? null,
                    'file_size' => $row->{$prefix . '_size'} ?? null,
                    'uploaded_by' => $row->updated_by ?? $row->created_by ?? null,
                    'created_at' => $row->updated_at ?? $now,
                    'updated_at' => $row->updated_at ?? $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['cctv_seal_notice_uploaded_by']);
            $table->dropColumn([
                'cctv_seal_notice_path',
                'cctv_seal_notice_name',
                'cctv_seal_notice_mime_type',
                'cctv_seal_notice_size',
                'cctv_seal_notice_uploaded_at',
                'cctv_seal_notice_uploaded_by',
            ]);
        });

        Schema::dropIfExists('npc_status_workflow_steps');
        Schema::dropIfExists('npc_status_attachments');
    }
};
