<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AvailableTelegramBot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'bot_name',
    ];

    /**
     * Загрузить модель.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (AvailableTelegramBot $model): void {
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
        ];
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
