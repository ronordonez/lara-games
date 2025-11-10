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
        Schema::create('winners', function (Blueprint $table) {
            $table->id()->comment('ID único del ganador');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('Usuario que realizó el sorteo');
            $table->foreignId('source_id')->constrained()->onDelete('cascade')->comment('Fuente de donde salió el ganador');

            // Información del ganador
            $table->string('winner_name')->comment('Nombre del ganador');
            $table->string('winner_identifier')->comment('ID/username/email según la fuente');
            $table->string('winner_profile_url')->nullable()->comment('URL del perfil (si aplica)');
            $table->string('winner_avatar')->nullable()->comment('Avatar (si aplica)');
            $table->text('winner_contact')->nullable()->comment('Info de contacto adicional');

            // Detalles de la participación ganadora
            $table->text('winner_content')->nullable()->comment('Comentario/mensaje del ganador');
            $table->json('winner_metadata')->nullable()->comment('Metadata adicional del ganador');

            // Ejemplo winner_metadata:
            // {
            //   "source_type": "facebook",
            //   "interaction_type": "comment",
            //   "comment_likes": 15,
            //   "interaction_date": "2024-01-15 10:30:00",
            //   "follower_count": 500,
            //   "verified": false
            // }

            // Contexto del sorteo
            $table->integer('total_participants_at_draw')->comment('Total de participantes válidos al momento del sorteo');
            $table->json('filters_applied')->nullable()->comment('Filtros que se aplicaron');
            $table->text('notes')->nullable()->comment('Notas adicionales del organizador');

            // Información del sorteo
            $table->string('raffle_method')->default('random')->comment('Método usado: random, weighted, manual');
            $table->integer('position')->default(1)->comment('Posición (1er lugar, 2do, etc.)');

            $table->timestamps();

            $table->index(['source_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('winner_identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winners');
    }
};
