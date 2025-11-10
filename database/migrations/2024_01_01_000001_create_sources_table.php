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
        Schema::create('sources', function (Blueprint $table) {
            $table->id()->comment('ID único de la fuente de participantes');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('Usuario propietario');

            // Identificación de la fuente
            $table->string('name')->comment('Nombre descriptivo del sorteo/campaña');
            $table->text('description')->nullable()->comment('Descripción');

            // Tipo y configuración de fuente
            $table->enum('source_type', ['facebook', 'youtube', 'instagram', 'twitter', 'tiktok', 'manual_list', 'csv', 'api'])->comment('Tipo de fuente de datos');
            $table->json('source_config')->comment('Configuración específica de la fuente (post_id, video_id, hashtag, etc.)');

            // Ejemplo source_config por tipo:
            // facebook: {"post_id": "123_456", "page_id": "123"}
            // youtube: {"video_id": "abc123"}
            // instagram: {"post_url": "...", "hashtag": "#sorteo"}
            // manual_list: {"participants": [{"name": "...", "email": "..."}]}
            // csv: {"file_path": "storage/...", "delimiter": ","}
            // api: {"endpoint": "https://...", "method": "GET", "headers": {...}}

            // Tipo de interacción requerida
            $table->enum('interaction_type', ['comment', 'like', 'share', 'subscribe', 'follow', 'any'])->default('comment')->comment('Tipo de interacción válida');

            // Filtros de participantes
            $table->json('filters')->nullable()->comment('Filtros a aplicar (hashtags, keywords, min_likes, date_range, etc.)');

            // Ejemplo filters:
            // {
            //   "required_hashtags": ["#sorteo", "#promo"],
            //   "forbidden_words": ["spam", "bot"],
            //   "min_comment_likes": 5,
            //   "date_from": "2024-01-01",
            //   "date_to": "2024-12-31",
            //   "min_followers": 100,
            //   "verified_only": false,
            //   "unique_per_user": true
            // }

            // Estadísticas (actualizadas cada sync)
            $table->integer('total_raw_participants')->default(0)->comment('Total sin filtrar');
            $table->integer('total_valid_participants')->default(0)->comment('Total después de filtros');

            // Control de cache
            $table->timestamp('last_sync_at')->nullable()->comment('Última sincronización');
            $table->timestamp('cache_expires_at')->nullable()->comment('Expiración del cache');
            $table->integer('cache_ttl_hours')->default(6)->comment('Horas de validez del cache');

            // Estado
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['source_type', 'status']);
            $table->index('cache_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
