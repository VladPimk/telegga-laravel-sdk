<?php

declare(strict_types=1);

use App\Models\CustomUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    try {
        Schema::dropIfExists('custom_users');
    } finally {
        Schema::enableForeignKeyConstraints();
    }
});

it('создаёт таблицу подключений с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('telegram_connected_users', [
        'id',
        'uuid',
        'name',
        'email',
        'user_id',
        'available_telegram_bot_id',
        'is_connected',
        'is_created',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});

it('создаёт ожидаемые индексы таблицы подключений', function (): void {
    $indexes = collect(Schema::getIndexes('telegram_connected_users'));

    expect($indexes->contains(
        fn (array $index): bool => $index['columns'] === ['uuid']
            && $index['unique'] === false,
    ))->toBeTrue()
        ->and($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['user_id']
                && $index['unique'] === false,
        ))->toBeTrue()
        ->and($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['available_telegram_bot_id']
                && $index['unique'] === false,
        ))->toBeTrue();
});

it('генерирует uuid и устанавливает начальные статусы', function (): void {
    $providedUuid = Str::uuid()->toString();
    $connection = TelegramConnectedUser::query()->create([
        'uuid' => $providedUuid,
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    expect($connection->getKey())
        ->toBe($connection->id)
        ->and($connection->id)
        ->toBeInt()
        ->and($connection->uuid)
        ->toBeString()
        ->not->toBe($providedUuid)
        ->and(Str::isUuid($connection->uuid, 7))
        ->toBeTrue()
        ->and($connection->is_created)
        ->toBeFalse()
        ->and($connection->is_connected)
        ->toBeFalse()
        ->and($connection->user_id)
        ->toBeNull()
        ->and($connection->telegramBot->is($this->telegramBot))
        ->toBeTrue()
        ->and($connection->user)
        ->toBeNull();
});

it('связывает подключение с пользователем проекта', function (): void {
    $user = User::query()->create([
        'name' => 'Иван',
    ]);

    $connection = TelegramConnectedUser::query()->create([
        'user_id' => $user->getKey(),
        'name' => $user->name,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    expect($connection->user)
        ->toBeInstanceOf(User::class)
        ->and($connection->user->is($user))
        ->toBeTrue();
});

it('использует настроенные модель и таблицу пользователя проекта', function (): void {
    $this->dropConnectionTable();
    Schema::create('custom_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    config()->set('telegga.user_model', CustomUser::class);
    config()->set('telegga.users_table', 'custom_users');

    $connectionMigration = require __DIR__.'/../../database/migrations/2026_07_31_000002_create_telegram_connected_users_table.php';
    $connectionMigration->up();

    $user = CustomUser::query()->create([
        'name' => 'Иван',
    ]);
    $connection = TelegramConnectedUser::query()->create([
        'user_id' => $user->getKey(),
        'name' => $user->name,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $foreignKeys = collect(Schema::getForeignKeys('telegram_connected_users'));

    expect($connection->user)
        ->toBeInstanceOf(CustomUser::class)
        ->and($connection->user->is($user))
        ->toBeTrue()
        ->and($foreignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']
                && $foreignKey['foreign_table'] === 'custom_users'
                && $foreignKey['foreign_columns'] === ['id']
                && $foreignKey['on_delete'] === 'set null',
        ))
        ->toBeTrue();

    $user->delete();
    $connection->refresh();

    expect($connection->user_id)
        ->toBeNull()
        ->and($connection->user)
        ->toBeNull();
});

it('отклоняет некорректный класс модели пользователя проекта', function (): void {
    config()->set('telegga.user_model', stdClass::class);

    expect(fn () => (new TelegramConnectedUser)->user())
        ->toThrow(LogicException::class, 'Telegga user_model must be an Eloquent model class.');
});

it('отклоняет несовпадающие модель и таблицу пользователя проекта', function (): void {
    config()->set('telegga.user_model', CustomUser::class);

    expect(fn () => (new TelegramConnectedUser)->user())
        ->toThrow(LogicException::class, 'Telegga user_model must use the configured users_table.');
});

it('отклоняет пустое имя таблицы пользователей проекта', function (): void {
    $this->dropConnectionTable();
    config()->set('telegga.users_table', '');
    $connectionMigration = require __DIR__.'/../../database/migrations/2026_07_31_000002_create_telegram_connected_users_table.php';

    expect(fn () => $connectionMigration->up())
        ->toThrow(LogicException::class, 'Telegga users_table must be a non-empty table name.');
});

it('позволяет пользователю проекта иметь несколько подключений', function (): void {
    $user = User::query()->create([
        'name' => 'Иван',
    ]);

    TelegramConnectedUser::query()->create([
        'user_id' => $user->getKey(),
        'name' => $user->name,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    TelegramConnectedUser::query()->create([
        'user_id' => $user->getKey(),
        'name' => $user->name,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    expect(TelegramConnectedUser::query()
        ->where('user_id', $user->getKey())
        ->count())
        ->toBe(2);
});

it('сохраняет подключение после удаления связанного пользователя', function (): void {
    $user = User::query()->create([
        'name' => 'Иван',
    ]);
    $connection = TelegramConnectedUser::query()->create([
        'user_id' => $user->getKey(),
        'name' => $user->name,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $user->delete();
    $connection->refresh();

    expect($connection->user_id)
        ->toBeNull()
        ->and($connection->user)
        ->toBeNull();
});
