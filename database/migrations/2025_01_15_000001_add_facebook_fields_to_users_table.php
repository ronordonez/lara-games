<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('facebook_user_id')->nullable()->after('email');
            $table->text('facebook_token')->nullable()->after('facebook_user_id');
            $table->timestamp('facebook_token_expires')->nullable()->after('facebook_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['facebook_user_id', 'facebook_token', 'facebook_token_expires']);
        });
    }
};
