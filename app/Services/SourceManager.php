<?php

namespace App\Services;

use App\Contracts\ParticipantSourceInterface;
use App\Models\Source;
use App\Services\Sources\FacebookSourceProvider;
use App\Services\Sources\ManualListSourceProvider;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Servicio central para gestionar fuentes de participantes
 * Factory pattern para crear providers según el tipo de fuente
 */
class SourceManager
{
    /**
     * Obtener el provider correcto según el tipo de fuente
     */
    public function getProvider(string $sourceType): ParticipantSourceInterface
    {
        return match ($sourceType) {
            'facebook' => new FacebookSourceProvider(),
            'manual_list' => new ManualListSourceProvider(),
            // 'youtube' => new YouTubeSourceProvider(),
            // 'instagram' => new InstagramSourceProvider(),
            // 'csv' => new CsvSourceProvider(),
            default => throw new Exception("Tipo de fuente no soportado: {$sourceType}"),
        };
    }

    /**
     * Sincronizar participantes de una fuente
     * Usa cache si está disponible, sino consulta el provider
     */
    public function syncParticipants(Source $source, bool $forceRefresh = false, ?string $overrideInteractionType = null): array
    {
        // Si el cache es válido y no se fuerza refresh, usar cache
        if (!$forceRefresh && $source->hasFreshCache()) {
            Log::info("Usando cache para source_id: {$source->id}");
            $cached = $source->getParticipantsFromCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            // Obtener provider
            $provider = $this->getProvider($source->source_type);

            // Validar configuración
            if (!$provider->validateConfig($source->source_config)) {
                throw new Exception("Configuración inválida para fuente tipo: {$source->source_type}");
            }

            // Fetch participantes desde la fuente
            Log::info("Sincronizando participantes desde {$source->source_type} para source_id: {$source->id}");
            $interactionToUse = $overrideInteractionType ?? $source->interaction_type;

            $rawParticipants = $provider->fetchParticipants(
                config: $source->source_config,
                interactionType: $interactionToUse
            );


            Log::info($rawParticipants);

            Log::info("Total participantes obtenidos: " . count($rawParticipants));

            // Aplicar filtros
            $filteredParticipants = $source->applyFilters($rawParticipants);

            // Guardar en cache
            $source->cacheParticipants($filteredParticipants);

            // Actualizar stats
            $source->update([
                'total_raw_participants' => count($rawParticipants),
                'total_valid_participants' => count($filteredParticipants),
            ]);

            return $filteredParticipants;

        } catch (Exception $e) {
            Log::error("Error al sincronizar source_id {$source->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener solo estadísticas (sin traer participantes)
     */
    public function getSourceStats(Source $source): array
    {
        try {
            $provider = $this->getProvider($source->source_type);
            return $provider->getStats($source->source_config);
        } catch (Exception $e) {
            Log::error("Error al obtener stats de source_id {$source->id}: " . $e->getMessage());
            return [
                'total_participants' => 0,
                'total_comments' => 0,
                'total_likes' => 0,
                'total_shares' => 0,
            ];
        }
    }

    /**
     * Obtener participantes aplicando filtros adicionales en tiempo real
     */
    public function getFilteredParticipants(Source $source, array $runtimeFilters = []): array
    {
        // Primero obtener participantes (usa cache si está disponible)
        $participants = $this->syncParticipants($source);

        // Si no hay filtros adicionales, retornar
        if (empty($runtimeFilters)) {
            return $participants;
        }

        // Aplicar filtros adicionales en runtime
        $filtered = $participants;

        // Filtro: búsqueda por nombre
        if (!empty($runtimeFilters['search'])) {
            $search = strtolower($runtimeFilters['search']);
            $filtered = array_filter($filtered, function ($p) use ($search) {
                return str_contains(strtolower($p['name']), $search) ||
                       str_contains(strtolower($p['username'] ?? ''), $search) ||
                       str_contains(strtolower($p['content'] ?? ''), $search);
            });
        }

        // Filtro: tipo de interacción específico
        if (!empty($runtimeFilters['interaction_type'])) {
            $filtered = array_filter($filtered, function ($p) use ($runtimeFilters) {
                return $p['interaction_type'] === $runtimeFilters['interaction_type'];
            });
        }

        // Filtro: verificados
        if (isset($runtimeFilters['verified'])) {
            $filtered = array_filter($filtered, function ($p) use ($runtimeFilters) {
                return $p['verified'] == $runtimeFilters['verified'];
            });
        }

        return array_values($filtered);
    }

    /**
     * Realizar sorteo de ganadores
     */
    public function drawWinners(Source $source, int $count = 1, array $runtimeFilters = []): array
    {
        $participants = $this->getFilteredParticipants($source, $runtimeFilters);

        if (empty($participants)) {
            throw new Exception("No hay participantes válidos para el sorteo");
        }

        if ($count > count($participants)) {
            throw new Exception("No hay suficientes participantes. Disponibles: " . count($participants) . ", solicitados: {$count}");
        }

        // Sorteo aleatorio
        $keys = array_rand($participants, $count);

        if ($count === 1) {
            $keys = [$keys];
        }

        $winners = [];
        foreach ($keys as $key) {
            $winners[] = $participants[$key];
        }

        return $winners;
    }
}
