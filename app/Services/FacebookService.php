<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookService
{
    protected $accessToken;
    protected $apiVersion;
    protected $baseUrl;

    public function __construct()
    {
        $this->accessToken = config('services.facebook.access_token');
        $this->apiVersion = config('services.facebook.api_version', 'v18.0');
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";

        // Validar token al inicializar
        $this->validateToken();
    }

    /**
     * Validar que el token esté configurado correctamente
     */
    protected function validateToken()
    {
        if (empty($this->accessToken)) {
            throw new Exception(
                "FACEBOOK_ACCESS_TOKEN no está configurado en .env\n" .
                "Por favor, consulta FACEBOOK_TOKEN_SETUP.md para obtener instrucciones."
            );
        }

        if ($this->accessToken === 'your-facebook-access-token') {
            throw new Exception(
                "FACEBOOK_ACCESS_TOKEN es un placeholder. Necesitas un token real.\n" .
                "Sigue los pasos en FACEBOOK_TOKEN_SETUP.md para obtener un token válido de Facebook.\n\n" .
                "Opción rápida: Ve a https://developers.facebook.com/tools/explorer/ y genera un token."
            );
        }

        if (strlen($this->accessToken) < 50) {
            throw new Exception(
                "FACEBOOK_ACCESS_TOKEN parece inválido (muy corto).\n" .
                "Un token válido debe tener 100+ caracteres y comenzar con 'EAAQ' o similar.\n" .
                "Consulta FACEBOOK_TOKEN_SETUP.md"
            );
        }
    }

    /**
     * Verificar si el token es válido haciendo una llamada de prueba
     */
    public function testConnection()
    {
        try {
            $response = Http::get("{$this->baseUrl}/me", [
                'access_token' => $this->accessToken,
                'fields' => 'id,name',
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw new Exception(
                    "Token inválido o expirado:\n" .
                    json_encode($error, JSON_PRETTY_PRINT) . "\n\n" .
                    "Genera un nuevo token en: https://developers.facebook.com/tools/explorer/\n" .
                    "O consulta FACEBOOK_TOKEN_SETUP.md para más opciones."
                );
            }

            return [
                'success' => true,
                'message' => 'Conexión exitosa con Facebook',
                'data' => $response->json(),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al conectar con Facebook',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener engagement de una publicación
     */
    public function getPostEngagement(string $postId)
    {
        try {
            $response = Http::get("{$this->baseUrl}/{$postId}", [
                'fields' => 'comments.limit(1000){id,from,message,created_time,like_count},likes.limit(1000){id,name,pic}',
                'access_token' => $this->accessToken,
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al obtener datos de Facebook: ' . $response->body());
            }

            $data = $response->json();

            return [
                'comments' => $this->formatComments($data['comments']['data'] ?? []),
                'likes' => $this->formatLikes($data['likes']['data'] ?? []),
                // 'shares' => $this->formatShares($data['shares'] ?? []),
            ];

        } catch (Exception $e) {
            Log::error('Error en FacebookService::getPostEngagement: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener comentarios con paginación
     */
    public function getPostComments(string $postId, ?string $after = null)
    {
        try {
            $params = [
                'fields' => 'id,from,message,created_time,like_count,comments',
                'access_token' => $this->accessToken,
                'limit' => 100,
            ];

            if ($after) {
                $params['after'] = $after;
            }

            $response = Http::get("{$this->baseUrl}/{$postId}/comments", $params);

            if (!$response->successful()) {
                throw new Exception('Error al obtener comentarios de Facebook: ' . $response->body());
            }

            $data = $response->json();

            return [
                'comments' => $this->formatComments($data['data'] ?? []),
                'paging' => $data['paging'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error('Error en FacebookService::getPostComments: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener likes de una publicación
     */
    public function getPostLikes(string $postId, ?string $after = null)
    {
        try {
            $params = [
                'access_token' => $this->accessToken,
                'limit' => 100,
            ];

            if ($after) {
                $params['after'] = $after;
            }

            $response = Http::get("{$this->baseUrl}/{$postId}/likes", $params);

            if (!$response->successful()) {
                throw new Exception('Error al obtener likes de Facebook: ' . $response->body());
            }

            $data = $response->json();

            return [
                'likes' => $this->formatLikes($data['data'] ?? []),
                'paging' => $data['paging'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error('Error en FacebookService::getPostLikes: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener shares de una publicación
     */
    public function getPostShares(string $postId)
    {
        try {
            $response = Http::get("{$this->baseUrl}/{$postId}/sharedposts", [
                'access_token' => $this->accessToken,
                'fields' => 'id,from,created_time,message',
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al obtener shares de Facebook: ' . $response->body());
            }

            $data = $response->json();

            return $this->formatShares($data['data'] ?? []);

        } catch (Exception $e) {
            Log::error('Error en FacebookService::getPostShares: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Formatear comentarios
     */
    protected function formatComments(array $comments)
    {
        return array_map(function ($comment) {
            return [
                'comment_id' => $comment['id'] ?? '',
                'user_id' => $comment['from']['id'] ?? '',
                'username' => $comment['from']['name'] ?? '',
                'name' => $comment['from']['name'] ?? '',
                'message' => $comment['message'] ?? '',
                'likes' => $comment['like_count'] ?? 0,
                'created_at' => $comment['created_time'] ?? now(),
                'profile_url' => "https://facebook.com/" . ($comment['from']['id'] ?? ''),
            ];
        }, $comments);
    }

    /**
     * Formatear likes
     */
    protected function formatLikes(array $likes)
    {
        return array_map(function ($like) {
            return [
                'like_id' => $like['id'] ?? uniqid('like_'),
                'user_id' => $like['id'] ?? '',
                'username' => $like['name'] ?? '',
                'name' => $like['name'] ?? '',
                'avatar' => $like['pic'] ?? null,
                'created_at' => now(),
                'profile_url' => "https://facebook.com/" . ($like['id'] ?? ''),
            ];
        }, $likes);
    }

    /**
     * Formatear shares
     */
    protected function formatShares(array $shares)
    {
        if (isset($shares['count'])) {
            // Si solo tenemos el conteo
            return [];
        }

        return array_map(function ($share) {
            return [
                'share_id' => $share['id'] ?? '',
                'user_id' => $share['from']['id'] ?? '',
                'username' => $share['from']['name'] ?? '',
                'name' => $share['from']['name'] ?? '',
                'created_at' => $share['created_time'] ?? now(),
                'profile_url' => "https://facebook.com/" . ($share['from']['id'] ?? ''),
            ];
        }, $shares);
    }

    /**
     * Buscar publicaciones por hashtag
     */
    public function searchByHashtag(string $hashtag, int $limit = 50)
    {
        try {
            $response = Http::get("{$this->baseUrl}/search", [
                'q' => "#{$hashtag}",
                'type' => 'post',
                'access_token' => $this->accessToken,
                'limit' => $limit,
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al buscar por hashtag en Facebook: ' . $response->body());
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('Error en FacebookService::searchByHashtag: ' . $e->getMessage());
            throw $e;
        }
    }
}
