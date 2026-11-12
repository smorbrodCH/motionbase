<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publication status of a whole course (Entwurf, Privat, Veröffentlicht,
     * In Überarbeitung, Archiv) replaces the yes/no "is_published". A course
     * that was unpublished stays out of reach as a draft; all others stay
     * published.
     */
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->string('status', 16)->default('published')->after('slug');
        });

        DB::table('topics')->where('is_published', false)->update(['status' => 'draft']);

        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->after('slug');
        });

        DB::table('topics')->whereIn('status', ['draft', 'private', 'archived'])->update(['is_published' => false]);

        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
