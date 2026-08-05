<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TelegramConnectedUser extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $attributes = [
        'is_connected' => false,
        'is_created' => false,
    ];

    protected $fillable = [
        'name',
        'email',
        'user_id',
        'available_telegram_bot_id',
        'is_connected',
        'is_created',
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
     * Получить преобразования атрибутов модели.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'is_created' => 'boolean',
        ];
    }

    /**
     * Получить пользователя проекта.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(related: User::class);
    }

    /**
     * Получить выбранного Telegram-бота.
     *
     * @return BelongsTo<AvailableTelegramBot, $this>
     */
    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(
            related: AvailableTelegramBot::class,
            foreignKey: 'available_telegram_bot_id',
        );
    }
}
