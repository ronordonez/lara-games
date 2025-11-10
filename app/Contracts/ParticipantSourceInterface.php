<?php

namespace App\Contracts;

/**
 * Interface para providers de fuentes de participantes
 * Cada fuente (Facebook, YouTube, CSV, etc.) debe implementar esta interface
 */
interface ParticipantSourceInterface
{
    /**
     * Obtener participantes de la fuente
     *
     * @param array $config Configuración específica de la fuente
     * @param string $interactionType Tipo de interacción (comment, like, share, etc.)
     * @return array Array de participantes en formato estándar
     */
    public function fetchParticipants(array $config, string $interactionType = 'comment'): array;

    /**
     * Validar que la configuración de la fuente es correcta
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config): bool;

    /**
     * Obtener estadísticas básicas de la fuente (sin traer todos los participantes)
     *
     * @param array $config
     * @return array ['total_participants', 'total_likes', etc.]
     */
    public function getStats(array $config): array;

    /**
     * Formato estándar de participante que debe retornar cada provider:
     * [
     *     'identifier' => '',      // ID único del participante en la fuente
     *     'name' => '',            // Nombre del participante
     *     'username' => '',        // Username (opcional)
     *     'profile_url' => '',     // URL del perfil (opcional)
     *     'avatar' => '',          // URL del avatar (opcional)
     *     'content' => '',         // Comentario/mensaje (opcional)
     *     'likes_count' => 0,      // Likes del comentario/post
     *     'interaction_date' => '', // Fecha ISO 8601
     *     'interaction_type' => '', // comment, like, share, etc.
     *     'follower_count' => 0,   // Seguidores del usuario (opcional)
     *     'verified' => false,     // Usuario verificado (opcional)
     *     'metadata' => [],        // Datos adicionales específicos de la fuente
     * ]
     */
}
