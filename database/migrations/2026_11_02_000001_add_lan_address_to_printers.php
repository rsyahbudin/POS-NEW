<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            // A network printer: the print bridge in the cafe sends its jobs to host:port (raw ESC/POS, normally 9100).
            $table->string('host', 100)->nullable()->after('copies');
            $table->unsignedSmallInteger('port')->default(9100)->after('host');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn(['host', 'port']);
        });
    }
};
