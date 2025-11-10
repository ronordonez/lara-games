<?php

namespace App\Services\Sources;

use App\Contracts\ParticipantSourceInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookSourceProvider implements ParticipantSourceInterface
{
    protected $accessToken;
    protected $apiVersion;
    protected $baseUrl;

    public function __construct()
    {
        $this->accessToken = config('services.facebook.access_token');
        $this->apiVersion = config('services.facebook.api_version', 'v18.0');
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";

        $this->validateToken();
    }

    protected function validateToken()
    {
        if (empty($this->accessToken) || $this->accessToken === 'your-facebook-access-token') {
            throw new Exception(
                "FACEBOOK_ACCESS_TOKEN no configurado correctamente. " .
                "Consulta FACEBOOK_TOKEN_SETUP.md"
            );
        }
    }

    public function fetchParticipants(array $config, string $interactionType = 'comment'): array
    {
        $postId = $config['post_id'] ?? null;

        if (!$postId) {
            throw new Exception("Configuración inválida: 'post_id' es requerido para Facebook");
        }

        // Permitir override del access_token por fuente (source_config.access_token)
        if (!empty($config['access_token'])) {
            $this->accessToken = $config['access_token'];
            // revalidar token básico
            try {
                $this->validateToken();
            } catch (Exception $e) {
                Log::warning('fetchParticipants: access_token override inválido: ' . $e->getMessage());
            }
        }

        try {
            switch ($interactionType) {
                case 'comment':
                    return $this->fetchComments($postId);

                case 'like':
                    return $this->fetchLikes($postId);

                case 'share':
                    return $this->fetchShares($postId);

                case 'any':
                    return $this->fetchAll($postId);

                default:
                    throw new Exception("Tipo de interacción no soportado: {$interactionType}");
            }
        } catch (Exception $e) {
            Log::error("Error en FacebookSourceProvider: " . $e->getMessage());
            throw $e;
        }
    }

    protected function fetchComments(string $postId): array
    {
        $response = Http::get("{$this->baseUrl}/{$postId}/comments", [
            'fields' => 'id,from,message,created_time,like_count',
            'access_token' => $this->accessToken,
            'limit' => 1000,
        ]);

        if (!$response->successful()) {
            throw new Exception('Error al obtener comentarios: ' . $response->body());
        }

        $comments = $response->json()['data'] ?? [];

        return array_map(function ($comment) {
            return [
                'identifier' => $comment['from']['id'] ?? '',
                'name' => $comment['from']['name'] ?? '',
                'username' => $comment['from']['name'] ?? '',
                'profile_url' => "https://facebook.com/" . ($comment['from']['id'] ?? ''),
                'avatar' => null,
                'content' => $comment['message'] ?? '',
                'likes_count' => $comment['like_count'] ?? 0,
                'interaction_date' => $comment['created_time'] ?? now()->toIso8601String(),
                'interaction_type' => 'comment',
                'follower_count' => 0,
                'verified' => false,
                'metadata' => [
                    'comment_id' => $comment['id'] ?? '',
                    'source' => 'facebook',
                ],
            ];
        }, $comments);
    }

    protected function fetchLikes(string $postId): array
    {
        $results = [];

        // Helper para paginar una arista (likes o reactions)
        $fetchPaged = function (string $edge, array $params = []) use ($postId) {
            $items = [];
            $url = "{$this->baseUrl}/{$postId}/{$edge}";
            $params = array_merge(['access_token' => $this->accessToken, 'limit' => 100], $params);

            try {
                $resp = Http::get($url, $params);
            } catch (\Exception $e) {
                Log::warning("fetchLikes: exception fetching {$edge}: " . $e->getMessage());
                return [];
            }

            if (!$resp->successful()) {
                Log::warning('fetchLikes: ' . $edge . ' returned non-success: ' . $resp->body());
                return [];
            }

            $data = $resp->json();
            $pageData = $data['data'] ?? [];
            $items = array_merge($items, $pageData);

            // Paginación: seguir next si existe
            $next = $data['paging']['next'] ?? null;
            while ($next) {
                try {
                    $resp = Http::get($next);
                } catch (\Exception $e) {
                    Log::warning("fetchLikes: exception fetching next page for {$edge}: " . $e->getMessage());
                    break;
                }
                if (!$resp->successful()) {
                    Log::warning('fetchLikes: next page for ' . $edge . ' returned non-success: ' . $resp->body());
                    break;
                }
                $page = $resp->json();
                $pageData = $page['data'] ?? [];
                $items = array_merge($items, $pageData);
                $next = $page['paging']['next'] ?? null;
            }

            return $items;
        };

        // Intentar primero /likes
        $likes = $fetchPaged('likes');

        // Si no hay likes, intentar /reactions
        if (empty($likes)) {
            $likes = $fetchPaged('reactions', ['fields' => 'id,name,type,created_time']);
        }

        Log::info('fetchLikes: total likes/reactions fetched: ' . count($likes));

        // Normalizar resultados
        foreach ($likes as $like) {
            $id = $like['id'] ?? ($like['from']['id'] ?? null);
            $name = $like['name'] ?? ($like['from']['name'] ?? null);

            if (!$id) {
                // saltar entradas sin id
                continue;
            }

            $results[] = [
                'identifier' => (string) $id,
                'name' => $name ?? 'Sin nombre',
                'username' => $name ?? '',
                'profile_url' => "https://facebook.com/" . $id,
                'avatar' => $like['picture'] ?? null,
                // Cada reaction/like representa 1 like por usuario en este contexto
                'likes_count' => 1,
                'content' => '',
                'interaction_date' => $like['created_time'] ?? now()->toIso8601String(),
                'interaction_type' => 'like',
                'follower_count' => 0,
                'verified' => false,
                'metadata' => [
                    'source' => 'facebook',
                    'reaction_id' => $like['id'] ?? null,
                    'reaction_type' => $like['type'] ?? null,
                ],
            ];
        }

        return $results;
    }

    protected function fetchShares(string $postId): array
    {
        try {
            $response = Http::get("{$this->baseUrl}/{$postId}/sharedposts", [
                'access_token' => $this->accessToken,
                'fields' => 'id,from,created_time',
            ]);

            if (!$response->successful()) {
                Log::warning('No se pudieron obtener shares de Facebook');
                return [];
            }

            $shares = $response->json()['data'] ?? [];

            return array_map(function ($share) {
                return [
                    'identifier' => $share['from']['id'] ?? '',
                    'name' => $share['from']['name'] ?? '',
                    'username' => $share['from']['name'] ?? '',
                    'profile_url' => "https://facebook.com/" . ($share['from']['id'] ?? ''),
                    'avatar' => null,
                    'content' => '',
                    'likes_count' => 0,
                    'interaction_date' => $share['created_time'] ?? now()->toIso8601String(),
                    'interaction_type' => 'share',
                    'follower_count' => 0,
                    'verified' => false,
                    'metadata' => [
                        'share_id' => $share['id'] ?? '',
                        'source' => 'facebook',
                    ],
                ];
            }, $shares);
        } catch (Exception $e) {
            Log::warning('Error al obtener shares: ' . $e->getMessage());
            return [];
        }
    }

    protected function fetchAll(string $postId): array
    {
        $comments = $this->fetchComments($postId);
        $likes = $this->fetchLikes($postId);
        $shares = $this->fetchShares($postId);

        $all = array_merge($comments, $likes, $shares);

        // Deduplicate by interaction-specific id when possible (keep likes and comments separate)
        $unique = [];
        foreach ($all as $participant) {
            $interaction = $participant['interaction_type'] ?? 'unknown';

            // Prefer an explicit interaction id from metadata
            $meta = $participant['metadata'] ?? [];
            $interactionId = $meta['comment_id'] ?? $meta['reaction_id'] ?? $meta['share_id'] ?? null;

            if ($interactionId) {
                $key = $interaction . '_' . $interactionId;
            } else {
                // Fallback: allow multiple entries per user by including interaction type and a hash
                $key = $interaction . '_' . $participant['identifier'] . '_' . md5($participant['content'] ?? '');
            }

            if (!isset($unique[$key])) {
                $unique[$key] = $participant;
            }
        }

        return array_values($unique);
    }

    public function validateConfig(array $config): bool
    {
        return !empty($config['post_id']);
    }

    public function getStats(array $config): array
    {
        $postId = $config['post_id'] ?? null;

        if (!$postId) {
            return ['total_participants' => 0, 'total_likes' => 0, 'total_comments' => 0, 'total_shares' => 0];
        }

        try {
            $response = Http::get("{$this->baseUrl}/{$postId}", [
                'fields' => 'comments.summary(true),likes.summary(true),shares',
                'access_token' => $this->accessToken,
            ]);

            if (!$response->successful()) {
                return ['total_participants' => 0, 'total_likes' => 0, 'total_comments' => 0, 'total_shares' => 0];
            }

            $data = $response->json();

            $totalComments = $data['comments']['summary']['total_count'] ?? 0;
            $totalLikes = $data['likes']['summary']['total_count'] ?? 0;
            $totalShares = $data['shares']['count'] ?? 0;

            return [
                'total_participants' => $totalComments + $totalLikes + $totalShares,
                'total_comments' => $totalComments,
                'total_likes' => $totalLikes,
                'total_shares' => $totalShares,
            ];

        } catch (Exception $e) {
            Log::error('Error al obtener stats de Facebook: ' . $e->getMessage());
            return ['total_participants' => 0, 'total_likes' => 0, 'total_comments' => 0, 'total_shares' => 0];
        }
    }

    public function testConnection(): array
    {
        try {
            $response = Http::get("{$this->baseUrl}/me", [
                'access_token' => $this->accessToken,
                'fields' => 'id,name',
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Token inválido o expirado',
                    'error' => $response->body(),
                ];
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
}
