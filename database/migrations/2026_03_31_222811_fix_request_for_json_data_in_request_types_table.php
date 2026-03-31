<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\RequestType;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQL Server, let's manually update them to valid JSON arrays
        DB::table('request_types')->where('request_for', 'SAP')->update(['request_for' => json_encode(['SAP'])]);
        DB::table('request_types')->where('request_for', 'POS')->update(['request_for' => json_encode(['POS'])]);
        DB::table('request_types')->whereNull('request_for')->update(['request_for' => json_encode(['SAP'])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
