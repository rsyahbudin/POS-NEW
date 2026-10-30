<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $t) {
            $t->string('about', 400)->nullable();
            $t->string('whatsapp', 40)->nullable();
            $t->string('tiktok', 60)->nullable();
            $t->string('facebook', 120)->nullable();
            $t->string('website', 160)->nullable();
            $t->string('maps_url', 300)->nullable();
            $t->string('google_review_url', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $t) {
            $t->dropColumn(['about', 'whatsapp', 'tiktok', 'facebook', 'website', 'maps_url', 'google_review_url']);
        });
    }
};
