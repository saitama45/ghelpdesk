<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npc_status_attachments', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('npc_status_id')
                ->constrained('stores')
                ->nullOnDelete();
            $table->index(['npc_status_id', 'type', 'store_id', 'validity_from'], 'npc_status_attachment_store_idx');
        });

        // CCTV attachments created before this column existed were shared at
        // NPC-record level. Give every store that was already assigned to the
        // record its own attachment row so existing uploads remain visible.
        DB::table('npc_status_attachments')
            ->where('type', 'cctv_seal')
            ->whereNull('store_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $attachment): void {
                $storeIds = DB::table('npc_status_store')
                    ->where('npc_status_id', $attachment->npc_status_id)
                    ->orderBy('store_id')
                    ->pluck('store_id')
                    ->map(fn ($storeId) => (int) $storeId)
                    ->values();

                if ($storeIds->isEmpty()) {
                    return;
                }

                DB::table('npc_status_attachments')
                    ->where('id', $attachment->id)
                    ->update(['store_id' => $storeIds->shift()]);

                foreach ($storeIds as $storeId) {
                    DB::table('npc_status_attachments')->insert([
                        'npc_status_id' => $attachment->npc_status_id,
                        'store_id' => $storeId,
                        'type' => $attachment->type,
                        'validity_from' => $attachment->validity_from,
                        'file_path' => $attachment->file_path,
                        'file_name' => $attachment->file_name,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'uploaded_by' => $attachment->uploaded_by,
                        'created_at' => $attachment->created_at,
                        'updated_at' => $attachment->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('npc_status_attachments', function (Blueprint $table) {
            $table->dropIndex('npc_status_attachment_store_idx');
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
