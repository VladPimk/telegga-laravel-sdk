<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TelegramConnectedUser extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'is_connected' => false,
        'is_created' => false,
    ];

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'user_id',
        'available_telegram_bot_id',
        'is_connected',
        'is_created',
    ];

    /**
     * Загрузить модель.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (TelegramConnectedUser $model): void {
            $model->uuid = str()->uuid();
        });
    }

    /**
     * Получить преобразования атрибутов модели.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uuid' => 'string',
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
