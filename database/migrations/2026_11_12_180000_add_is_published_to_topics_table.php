<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A whole course can be hidden, on top of its chapters and pages.
     * Existing courses stay visible.
     */
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('is_published');
        });
    }
};
