<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\RaffleController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\UserConfigController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => 'auth'
], function ($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    // OAuth Google
    Route::get('/google', [AuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [AuthController::class, 'handleGoogleCallback']);
});

// ============================================
// FACEBOOK OAUTH & INTEGRATION
// ============================================
Route::prefix('facebook')->group(function () {
    // OAuth Flow
    Route::get('/oauth/redirect', [FacebookOAuthController::class, 'redirectToFacebook']);
    Route::get('/oauth/callback', [FacebookOAuthController::class, 'handleFacebookCallback']);

    // Endpoints protegidos (requieren autenticación)
    Route::middleware('auth:api')->group(function () {
        // Obtener páginas del usuario
        Route::get('/pages', [FacebookOAuthController::class, 'getPages']);

        // Obtener publicaciones de una página
        Route::get('/pages/{pageId}/posts', [FacebookOAuthController::class, 'getPagePosts']);

        // Crear source desde una publicación
        Route::post('/sources/create', [FacebookOAuthController::class, 'createSourceFromPost']);

        // Sincronizar participantes desde Facebook
        Route::post('/sources/{sourceId}/sync', [FacebookOAuthController::class, 'syncParticipants']);
    });
});

Route::group(
    [
        'middleware' => 'auth:api'
    ],
    function () {
        Route::get('/user', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // ============================================
        // SOURCES (Fuentes de Participantes)
        // ============================================
        Route::prefix('sources')->group(function () {
            // CRUD de fuentes
            Route::get('/', [SourceController::class, 'index']);           // Listar fuentes
            Route::post('/', [SourceController::class, 'store']);          // Crear fuente
            Route::get('/{id}', [SourceController::class, 'show']);        // Ver fuente
            Route::put('/{id}', [SourceController::class, 'update']);      // Actualizar fuente
            Route::delete('/{id}', [SourceController::class, 'destroy']);  // Eliminar fuente

            // Sincronización y participantes
            Route::post('/{id}/sync', [SourceController::class, 'sync']);              // Sincronizar participantes desde la fuente
            Route::get('/{id}/participants', [SourceController::class, 'participants']); // Obtener participantes (cache)
            Route::get('/{id}/stats', [SourceController::class, 'stats']);             // Estadísticas de la fuente
            Route::delete('/{id}/cache', [SourceController::class, 'clearCache']);     // Limpiar cache
        });

        // ============================================
        // RAFFLES (Sorteos y Ganadores)
        // ============================================
        Route::prefix('raffles')->group(function () {
            // Realizar sorteo
            Route::post('/sources/{sourceId}/draw', [RaffleController::class, 'draw']); // Realizar sorteo

            // Ganadores
            Route::get('/', [RaffleController::class, 'index']);                        // Listar todos los ganadores
            Route::get('/sources/{sourceId}/winners', [RaffleController::class, 'winners']); // Ganadores de una fuente
            Route::get('/{id}', [RaffleController::class, 'show']);                     // Ver ganador
            Route::put('/{id}', [RaffleController::class, 'update']);                   // Actualizar ganador
            Route::delete('/{id}', [RaffleController::class, 'destroy']);               // Eliminar ganador
        });

        Route::prefix('user-configs')->group(function () {
            Route::get('/{key}', [UserConfigController::class, 'show']);
            Route::put('/{key}', [UserConfigController::class, 'upsert']);
        });
    }
);
