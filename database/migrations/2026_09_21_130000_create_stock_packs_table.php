<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock pack is a bulk unit (BOX, PACK, CASE...) received at Stock In. It carries its own
 * barcode/QR label; the pieces inside stay ordinary one-per-row stock records that point back
 * to it through stock_pack_id, on stock_ins, stock_transfers and stock_receivings alike, so a
 * box can be scanned whole or split piece by piece anywhere downstream.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_packs', function (Blueprint $table) {
            $table->id();
            // Stamped with the active entity like the other stock tables (CompanyContext).
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('no action');
            $table->foreignId('asset_id')->constrained('assets')->onDelete('no action');
            $table->string('barcode')->unique();
            $table->text('qrcode')->nullable();
            $table->string('bulk_uom', 30);
            // Declared contents at Stock In; pieces may later be split off in transfers.
            $table->unsignedInteger('units_per_pack');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->index('asset_id');
        });

        foreach (['stock_ins', 'stock_transfers', 'stock_receivings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('stock_pack_id')->nullable()->constrained('stock_packs')->onDelete('no action');
            });
        }
    }

    public function down(): void
    {
        // Forward-only: dropping these would discard which pieces were received in which box.
        throw new RuntimeException('create_stock_packs_table cannot be rolled back.');
    }
};
