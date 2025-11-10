<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class Source extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'source_type',
        'source_config',
        'interaction_type',
        'filters',
        'total_raw_participants',
        'total_valid_participants',
        'last_sync_at',
        'cache_expires_at',
        'cache_ttl_hours',
        'status',
    ];

    protected $casts = [
        'source_config' => 'array',
        'filters' => 'array',
        'last_sync_at' => 'datetime',
        'cache_expires_at' => 'datetime',
    ];

    /**
     * Relaciones
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function winners()
    {
        return $this->hasMany(Winner::class);
    }

    /**
     * Cache management
     */
    public function getCacheKey(): string
    {
        return "source_participants_{$this->id}";
    }

    public function hasFreshCache(): bool
    {
        if (!$this->cache_expires_at) {
            return false;
        }
        return now()->lt($this->cache_expires_at);
    }

    public function getParticipantsFromCache(): ?array
    {
        return Cache::get($this->getCacheKey());
    }

    public function cacheParticipants(array $participants): self
    {
        $expiresAt = now()->addHours($this->cache_ttl_hours);

        Cache::put($this->getCacheKey(), $participants, $expiresAt);

        $this->update([
            'cache_expires_at' => $expiresAt,
            'total_raw_participants' => count($participants),
            'last_sync_at' => now(),
        ]);

        return $this;
    }

    public function clearCache(): self
    {
        Cache::forget($this->getCacheKey());

        $this->update([
            'cache_expires_at' => null,
        ]);

        return $this;
    }

    /**
     * Filtrar participantes según configuración
     */
    public function applyFilters(array $participants): array
    {
        if (!$this->filters || empty($this->filters)) {
            return $participants;
        }

        $filtered = $participants;
        $filters = $this->filters;

        // Normalizar formato de filtros: aceptar tanto formato asociativo
        // como un arreglo de reglas { type, field, value } (p. ej. desde Postman)
        if (array_values($filters) === $filters && !empty($filters) && isset($filters[0]['type'])) {
            $mapped = [];
            foreach ($filters as $rule) {
                $type = strtolower($rule['type'] ?? '');
                $field = $rule['field'] ?? null;
                $value = $rule['value'] ?? null;

                switch ($type) {
                    case 'min_comments':
                        $mapped['min_comments'] = intval($value);
                        break;
                    case 'contains_text':
                        // Si el valor contiene # lo tratamos como hashtag requerido
                        if (is_string($value) && str_starts_with($value, '#')) {
                            $mapped['required_hashtags'][] = $value;
                        } elseif (is_string($value)) {
                            $mapped['required_words'][] = $value;
                        }
                        break;
                    case 'min_followers':
                    case 'min_followers_count':
                        $mapped['min_followers'] = intval($value);
                        break;
                    case 'min_comment_likes':
                    case 'min_likes':
                        $mapped['min_comment_likes'] = intval($value);
                        break;
                    case 'forbidden_word':
                    case 'forbidden_words':
                        if (!empty($value)) {
                            $mapped['forbidden_words'][] = $value;
                        }
                        break;
                    case 'verified_only':
                        $mapped['verified_only'] = (bool)$value;
                        break;
                    case 'unique_per_user':
                        $mapped['unique_per_user'] = (bool)$value;
                        break;
                    case 'date_from':
                        $mapped['date_from'] = $value;
                        break;
                    case 'date_to':
                        $mapped['date_to'] = $value;
                        break;
                    default:
                        // Si no tenemos mapping, guardar crudo bajo su tipo por si se necesita
                        $mapped[$type] = $value;
                        break;
                }
            }

            // Reemplazar filters por la versión mapeada
            $filters = $mapped;
        }

        // Filtro: Hashtags requeridos
        if (!empty($filters['required_hashtags'])) {
            $filtered = array_filter($filtered, function ($p) use ($filters) {
                $content = strtolower($p['content'] ?? '');
                foreach ($filters['required_hashtags'] as $hashtag) {
                    $tag = strtolower(str_starts_with($hashtag, '#') ? $hashtag : "#{$hashtag}");
                    if (str_contains($content, $tag)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Filtro: Palabras requeridas (cuando el filtro viene como "contains_text" sin #)
        if (!empty($filters['required_words'])) {
            $filtered = array_filter($filtered, function ($p) use ($filters) {
                $content = strtolower($p['content'] ?? '');
                foreach ($filters['required_words'] as $word) {
                    if (str_contains($content, strtolower($word))) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Filtro: Mínimo de comentarios por usuario (cuenta todas las interacciones en el conjunto original)
        if (isset($filters['min_comments']) && $filters['min_comments'] > 0) {
            $min = intval($filters['min_comments']);
            $counts = [];
            foreach ($participants as $p) {
                $id = $p['identifier'] ?? ($p['user_id'] ?? null);
                if (!$id) continue;
                if (!isset($counts[$id])) $counts[$id] = 0;
                $counts[$id]++;
            }

            $filtered = array_filter($filtered, function ($p) use ($counts, $min) {
                $id = $p['identifier'] ?? ($p['user_id'] ?? null);
                return ($id && isset($counts[$id]) && $counts[$id] >= $min);
            });
        }

        // Filtro: Palabras requeridas (cuando el filtro viene como "contains_text" sin #)
        if (!empty($filters['required_words'])) {
            $filtered = array_filter($filtered, function ($p) use ($filters) {
                $content = strtolower($p['content'] ?? '');
                foreach ($filters['required_words'] as $word) {
                    if (str_contains($content, strtolower($word))) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Filtro: Palabras prohibidas
        if (!empty($filters['forbidden_words'])) {
            $filtered = array_filter($filtered, function ($p) use ($filters) {
                $content = strtolower($p['content'] ?? '');
                foreach ($filters['forbidden_words'] as $word) {
                    if (str_contains($content, strtolower($word))) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Filtro: Likes mínimos
        if (isset($filters['min_comment_likes']) && $filters['min_comment_likes'] > 0) {
            $filtered = array_filter($filtered, function ($p) use ($filters) {
                return ($p['likes_count'] ?? 0) >= $filters['min_comment_likes'];
            });
        }

        // Filtro: Rango de fechas
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $filtered = array_filter($filtered, function ($p) use ($filters) {
                if (empty($p['interaction_date'])) {
                    return false;
                }

                $date = Carbon::parse($p['interaction_date']);

                if (!empty($filters['date_from'])) {
                    $from = Carbon::parse($filters['date_from']);
                    if ($date->lt($from)) {
                        return false;
                    }
                }

                if (!empty($filters['date_to'])) {
                    $to = Carbon::parse($filters['date_to']);
                    if ($date->gt($to)) {
                        return false;
                    }
                }

                return true;
            });
        }

        // Filtro: Followers mínimos
        if (isset($filters['min_followers']) && $filters['min_followers'] > 0) {
            // Si ninguna entrada tiene follower_count o fan_count, ignorar este
            // filtro porque no hay datos para evaluar (evita filtrar todo).
            $hasFollowerData = false;
            foreach ($participants as $p) {
                if (isset($p['follower_count']) || isset($p['fan_count'])) {
                    $hasFollowerData = true;
                    break;
                }
            }

            if ($hasFollowerData) {
                $filtered = array_filter($filtered, function ($p) use ($filters) {
                    $count = 0;
                    if (isset($p['follower_count'])) $count = intval($p['follower_count']);
                    elseif (isset($p['fan_count'])) $count = intval($p['fan_count']);
                    return $count >= intval($filters['min_followers']);
                });
            } else {
                Log::info("applyFilters: min_followers requested but no follower_count/fan_count present in participants; skipping this filter.");
            }
        }

        // Filtro: Solo verificados
        if (!empty($filters['verified_only'])) {
            $filtered = array_filter($filtered, function ($p) {
                return !empty($p['verified']);
            });
        }

        // Filtro: Único por usuario (eliminar duplicados)
        if (!empty($filters['unique_per_user'])) {
            // Agrupar por identifier y seleccionar la mejor entrada por usuario.
            // Criterio: mayor likes_count; si hay empate, la más reciente por interaction_date.
            $groups = [];
            foreach ($filtered as $p) {
                $identifier = $p['identifier'] ?? ($p['user_id'] ?? ($p['name'] ?? null));
                if (!$identifier) continue;

                if (!isset($groups[$identifier])) {
                    $groups[$identifier] = $p;
                    continue;
                }

                $current = $groups[$identifier];
                $currLikes = intval($current['likes_count'] ?? 0);
                $pLikes = intval($p['likes_count'] ?? 0);

                if ($pLikes > $currLikes) {
                    $groups[$identifier] = $p;
                    continue;
                }

                if ($pLikes === $currLikes) {
                    // comparar por fecha si ambas tienen interaction_date
                    $currDate = !empty($current['interaction_date']) ? Carbon::parse($current['interaction_date']) : null;
                    $pDate = !empty($p['interaction_date']) ? Carbon::parse($p['interaction_date']) : null;

                    if ($pDate && $currDate) {
                        if ($pDate->gt($currDate)) {
                            $groups[$identifier] = $p;
                        }
                    } elseif ($pDate && !$currDate) {
                        $groups[$identifier] = $p;
                    }
                }
            }

            $filtered = array_values($groups);
        }

        // Actualizar conteo de participantes válidos
        $this->update(['total_valid_participants' => count($filtered)]);

        return array_values($filtered);
    }

    /**
     * Obtener configuración específica de la fuente
     */
    public function getConfigValue(string $key, $default = null)
    {
        return $this->source_config[$key] ?? $default;
    }

    /**
     * Obtener filtro específico
     */
    public function getFilterValue(string $key, $default = null)
    {
        return $this->filters[$key] ?? $default;
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('source_type', $type);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithFreshCache($query)
    {
        return $query->where('cache_expires_at', '>', now());
    }
}
