<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $telegram_connected_user_id
 * @property string $event_id
 * @property string $event
 * @property int $attempts
 * @property Carbon $first_seen_at
 * @property Carbon|null $processed_at
 */
final class TeleggaWebhookEvent extends Model
{
    use HasUuids;

    protected $attributes = [
        'attempts' => 1,
    ];

    protected $fillable = [
        'telegram_connected_user_id',
        'event_id',
        'event',
        'attempts',
        'first_seen_at',
        'processed_at',
    ];

    /**
     * Get fields with automatically generated UUIDs.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get model attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'first_seen_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the connection for which the event was accepted.
     *
     * @return BelongsTo<TelegramConnectedUser, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(
            related: TelegramConnectedUser::class,
            foreignKey: 'telegram_connected_user_id',
        )->withTrashed();
    }
}
