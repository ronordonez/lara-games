<?php

namespace App\Services\Sources;

use App\Contracts\ParticipantSourceInterface;
use Exception;

/**
 * Provider para listas manuales de participantes
 * Útil para sorteos sin API o datos importados manualmente
 */
class ManualListSourceProvider implements ParticipantSourceInterface
{
    public function fetchParticipants(array $config, string $interactionType = 'comment'): array
    {
        $participants = $config['participants'] ?? [];

        if (empty($participants)) {
            return [];
        }

        // Normalizar al formato estándar
        return array_map(function ($participant, $index) {
            return [
                'identifier' => $participant['id'] ?? $participant['email'] ?? $participant['name'] ?? "manual_{$index}",
                'name' => $participant['name'] ?? 'Sin nombre',
                'username' => $participant['username'] ?? $participant['name'] ?? '',
                'profile_url' => $participant['profile_url'] ?? null,
                'avatar' => $participant['avatar'] ?? null,
                'content' => $participant['comment'] ?? $participant['message'] ?? '',
                'likes_count' => $participant['likes'] ?? 0,
                'interaction_date' => $participant['date'] ?? now()->toIso8601String(),
                'interaction_type' => 'manual',
                'follower_count' => $participant['followers'] ?? 0,
                'verified' => $participant['verified'] ?? false,
                'metadata' => [
                    'source' => 'manual_list',
                    'email' => $participant['email'] ?? null,
                    'phone' => $participant['phone'] ?? null,
                    'extra' => $participant['extra'] ?? null,
                ],
            ];
        }, $participants, array_keys($participants));
    }

    public function validateConfig(array $config): bool
    {
        return isset($config['participants']) && is_array($config['participants']) && count($config['participants']) > 0;
    }

    public function getStats(array $config): array
    {
        $count = isset($config['participants']) ? count($config['participants']) : 0;

        return [
            'total_participants' => $count,
            'total_comments' => $count,
            'total_likes' => 0,
            'total_shares' => 0,
        ];
    }
}
