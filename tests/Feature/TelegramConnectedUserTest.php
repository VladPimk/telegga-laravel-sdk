<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    Schema::enableForeignKeyConstraints();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $migration = require __DIR__.'/../../database/migrations/create_telegram_connected_users_table.php';

    $migration->up();
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('users');
});

it('создаёт таблицу подключений с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('telegram_connected_users', [
        'id',
        'uuid',
        'name',
        'email',
        'user_id',
        'is_connected',
        'is_created',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('генерирует uuid и устанавливает начальные статусы', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
    ]);

    expect($connection->getKey())
        ->toBe($connection->id)
        ->and($connection->id)
        ->toBeInt()
        ->and(Str::isUuid($connection->uuid))
        ->toBeTrue()
        ->and($connection->is_created)
        ->toBeFalse()
        ->and($connection->is_connected)
        ->toBeFalse()
        ->and($connection->user_id)
        ->toBeNull()
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
    ]);

    expect($connection->user)
        ->toBeInstanceOf(User::class)
        ->and($connection->user->is($user))
        ->toBeTrue();
});

it('сохраняет подключение после удаления связанного пользователя', function (): void {
    $user = User::query()->create([
        'name' => 'Иван',
    ]);
    $connection = TelegramConnectedUser::query()->create([
        'user_id' => $user->getKey(),
        'name' => $user->name,
    ]);

    $user->delete();
    $connection->refresh();

    expect($connection->user_id)
        ->toBeNull()
        ->and($connection->user)
        ->toBeNull();
});
