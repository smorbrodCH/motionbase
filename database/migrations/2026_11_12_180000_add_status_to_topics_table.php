<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publication status of a whole course (Entwurf, Privat, Veröffentlicht,
     * In Überarbeitung, Archiv), on top of its chapters and pages.
     * Existing courses stay published.
     */
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->string('status', 16)->default('published')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
