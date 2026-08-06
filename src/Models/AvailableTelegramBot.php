<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $bot_name
 */
final class AvailableTelegramBot extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'bot_name',
    ];

    /**
     * Получить поля с автоматически генерируемыми UUID.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Привести имя Telegram-бота к нижнему регистру.
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
     * Получить подключения бота.
     *
     * @return HasMany<TelegramConnectedUser, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(related: TelegramConnectedUser::class);
    }
}
