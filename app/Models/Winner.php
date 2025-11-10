<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Winner extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_id',
        'winner_name',
        'winner_identifier',
        'winner_profile_url',
        'winner_avatar',
        'winner_contact',
        'winner_content',
        'winner_metadata',
        'total_participants_at_draw',
        'filters_applied',
        'notes',
        'raffle_method',
        'position',
    ];

    protected $casts = [
        'winner_metadata' => 'array',
        'filters_applied' => 'array',
    ];

    /**
     * Relaciones
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Accessors
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->winner_metadata[$key] ?? $default;
    }

    /**
     * Scopes
     */
    public function scopeBySource($query, int $sourceId)
    {
        return $query->where('source_id', $sourceId);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeByPosition($query, int $position)
    {
        return $query->where('position', $position);
    }
}
