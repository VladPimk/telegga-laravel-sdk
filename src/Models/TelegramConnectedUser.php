<?php

declare(strict_types=1);

namespace Telegga\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $email
 * @property int|null $user_id
 * @property int $available_telegram_bot_id
 * @property bool $is_connected
 * @property bool $is_created
 * @property-read AvailableTelegramBot|null $telegramBot
 */
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
            'is_connected' => 'boolean',
            'is_created' => 'boolean',
        ];
    }

    /**
     * Get the application user.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            related: $this->userModel(),
            foreignKey: 'user_id',
        );
    }

    /**
     * Get the selected Telegram bot.
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

    /**
     * Get accepted webhook events.
     *
     * @return HasMany<TeleggaWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(
            related: TeleggaWebhookEvent::class,
            foreignKey: 'telegram_connected_user_id',
        );
    }

    /**
     * Get the configured application user model class.
     *
     * @return class-string<Model>
     */
    private function userModel(): string
    {
        $userModel = config(key: 'telegga.user_model');
        $usersTable = config(key: 'telegga.users_table');

        if (! is_string($userModel) || ! is_subclass_of($userModel, Model::class)) {
            throw new LogicException('Telegga user_model must be an Eloquent model class.');
        }

        if (! is_string($usersTable) || trim($usersTable) === '') {
            throw new LogicException('Telegga users_table must be a non-empty table name.');
        }

        if ((new $userModel)->getTable() !== $usersTable) {
            throw new LogicException('Telegga user_model must use the configured users_table.');
        }

        return $userModel;
    }
}
