<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.youtube.api_key');
        $this->baseUrl = 'https://www.googleapis.com/youtube/v3';
    }

    /**
     * Obtener engagement de un video
     */
    public function getVideoEngagement(string $videoId)
    {
        try {
            // Obtener estadísticas del video
            $videoStats = $this->getVideoStatistics($videoId);

            // Obtener comentarios
            $comments = $this->getVideoComments($videoId);

            return [
                'video_stats' => $videoStats,
                'comments' => $comments['comments'],
                'likes' => $this->extractLikesFromStats($videoStats),
            ];

        } catch (Exception $e) {
            Log::error('Error en YouTubeService::getVideoEngagement: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener estadísticas de un video
     */
    public function getVideoStatistics(string $videoId)
    {
        try {
            $response = Http::get("{$this->baseUrl}/videos", [
                'part' => 'statistics,snippet',
                'id' => $videoId,
                'key' => $this->apiKey,
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al obtener estadísticas de YouTube: ' . $response->body());
            }

            $data = $response->json();

            if (empty($data['items'])) {
                throw new Exception('Video no encontrado');
            }

            $video = $data['items'][0];

            return [
                'video_id' => $video['id'],
                'title' => $video['snippet']['title'] ?? '',
                'description' => $video['snippet']['description'] ?? '',
                'view_count' => $video['statistics']['viewCount'] ?? 0,
                'like_count' => $video['statistics']['likeCount'] ?? 0,
                'comment_count' => $video['statistics']['commentCount'] ?? 0,
                'published_at' => $video['snippet']['publishedAt'] ?? '',
            ];

        } catch (Exception $e) {
            Log::error('Error en YouTubeService::getVideoStatistics: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener comentarios de un video
     */
    public function getVideoComments(string $videoId, ?string $pageToken = null)
    {
        try {
            $params = [
                'part' => 'snippet,replies',
                'videoId' => $videoId,
                'key' => $this->apiKey,
                'maxResults' => 100,
                'textFormat' => 'plainText',
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::get("{$this->baseUrl}/commentThreads", $params);

            if (!$response->successful()) {
                throw new Exception('Error al obtener comentarios de YouTube: ' . $response->body());
            }

            $data = $response->json();

            return [
                'comments' => $this->formatComments($data['items'] ?? []),
                'nextPageToken' => $data['nextPageToken'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error('Error en YouTubeService::getVideoComments: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener todos los comentarios de un video (con paginación automática)
     */
    public function getAllVideoComments(string $videoId, int $maxPages = 10)
    {
        $allComments = [];
        $pageToken = null;
        $pageCount = 0;

        try {
            do {
                $result = $this->getVideoComments($videoId, $pageToken);
                $allComments = array_merge($allComments, $result['comments']);
                $pageToken = $result['nextPageToken'];
                $pageCount++;
            } while ($pageToken && $pageCount < $maxPages);

            return $allComments;

        } catch (Exception $e) {
            Log::error('Error en YouTubeService::getAllVideoComments: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Formatear comentarios
     */
    protected function formatComments(array $items)
    {
        $comments = [];

        foreach ($items as $item) {
            $topComment = $item['snippet']['topLevelComment'] ?? null;

            if ($topComment) {
                $snippet = $topComment['snippet'];

                $comments[] = [
                    'comment_id' => $topComment['id'] ?? '',
                    'user_id' => $snippet['authorChannelId']['value'] ?? '',
                    'username' => $snippet['authorDisplayName'] ?? '',
                    'name' => $snippet['authorDisplayName'] ?? '',
                    'message' => $snippet['textDisplay'] ?? '',
                    'likes' => $snippet['likeCount'] ?? 0,
                    'created_at' => $snippet['publishedAt'] ?? now(),
                    'profile_url' => $snippet['authorChannelUrl'] ?? '',
                    'avatar' => $snippet['authorProfileImageUrl'] ?? null,
                ];

                // Agregar respuestas si existen
                if (isset($item['replies']['comments'])) {
                    foreach ($item['replies']['comments'] as $reply) {
                        $replySnippet = $reply['snippet'];

                        $comments[] = [
                            'comment_id' => $reply['id'] ?? '',
                            'user_id' => $replySnippet['authorChannelId']['value'] ?? '',
                            'username' => $replySnippet['authorDisplayName'] ?? '',
                            'name' => $replySnippet['authorDisplayName'] ?? '',
                            'message' => $replySnippet['textDisplay'] ?? '',
                            'likes' => $replySnippet['likeCount'] ?? 0,
                            'created_at' => $replySnippet['publishedAt'] ?? now(),
                            'profile_url' => $replySnippet['authorChannelUrl'] ?? '',
                            'avatar' => $replySnippet['authorProfileImageUrl'] ?? null,
                            'is_reply' => true,
                            'parent_id' => $topComment['id'] ?? '',
                        ];
                    }
                }
            }
        }

        return $comments;
    }

    /**
     * Extraer likes de las estadísticas
     * Nota: YouTube API no proporciona lista de usuarios que dieron like,
     * solo el conteo total
     */
    protected function extractLikesFromStats(array $stats)
    {
        // YouTube no proporciona datos individuales de likes
        // Solo retornamos información del conteo
        return [
            'total_likes' => $stats['like_count'] ?? 0,
            'note' => 'YouTube API no proporciona lista de usuarios que dieron like, solo el conteo total'
        ];
    }

    /**
     * Buscar videos por palabra clave
     */
    public function searchVideos(string $query, int $maxResults = 10)
    {
        try {
            $response = Http::get("{$this->baseUrl}/search", [
                'part' => 'snippet',
                'q' => $query,
                'type' => 'video',
                'key' => $this->apiKey,
                'maxResults' => $maxResults,
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al buscar videos en YouTube: ' . $response->body());
            }

            $data = $response->json();

            return array_map(function ($item) {
                return [
                    'video_id' => $item['id']['videoId'] ?? '',
                    'title' => $item['snippet']['title'] ?? '',
                    'description' => $item['snippet']['description'] ?? '',
                    'channel_title' => $item['snippet']['channelTitle'] ?? '',
                    'published_at' => $item['snippet']['publishedAt'] ?? '',
                    'thumbnail' => $item['snippet']['thumbnails']['default']['url'] ?? '',
                ];
            }, $data['items'] ?? []);

        } catch (Exception $e) {
            Log::error('Error en YouTubeService::searchVideos: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener información del canal
     */
    public function getChannelInfo(string $channelId)
    {
        try {
            $response = Http::get("{$this->baseUrl}/channels", [
                'part' => 'snippet,statistics',
                'id' => $channelId,
                'key' => $this->apiKey,
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al obtener información del canal: ' . $response->body());
            }

            $data = $response->json();

            if (empty($data['items'])) {
                throw new Exception('Canal no encontrado');
            }

            $channel = $data['items'][0];

            return [
                'channel_id' => $channel['id'],
                'title' => $channel['snippet']['title'] ?? '',
                'description' => $channel['snippet']['description'] ?? '',
                'subscriber_count' => $channel['statistics']['subscriberCount'] ?? 0,
                'video_count' => $channel['statistics']['videoCount'] ?? 0,
                'view_count' => $channel['statistics']['viewCount'] ?? 0,
            ];

        } catch (Exception $e) {
            Log::error('Error en YouTubeService::getChannelInfo: ' . $e->getMessage());
            throw $e;
        }
    }
}
