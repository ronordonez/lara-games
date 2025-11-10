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
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('Identificador único del usuario.');
            $table->string('name')->comment('Nombre del usuario.');
            $table->string('email')->comment('Correo electrónico del usuario.');
            $table->string('phone')->nullable();
            $table->timestamp('email_verified_at')->nullable()->comment('Fecha de verificación del correo electrónico.');
            $table->string('password')->comment('Contraseña del usuario.');
            $table->rememberToken()->comment('Token de recordar sesión.');
            $table->boolean('status')->default(true)->comment('Estado del usuario');
            $table->string('avatar')->nullable()->comment('URL del avatar del usuario.');
            $table->integer('login_attempts')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamp('lockout_time')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->boolean('has_full_access')->default(false);

            $table->timestamps();
            $table->softDeletes('deleted_at', precision: 0);
            $table->comment('Esta tabla almacena los usuarios del sistema.');

        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
