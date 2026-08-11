<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $bot_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AvailableTelegramBot extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'bot_name',
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
     * Convert the Telegram bot name to lowercase.
     *
     * @return Attribute<string, string>
     */
    protected function botName(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => str()->lower($value),
        );
    }

    /**
     * Get bot connections.
     *
     * @return HasMany<TelegramConnectedUser, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(related: TelegramConnectedUser::class);
    }
}
