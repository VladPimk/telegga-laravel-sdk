<?php

declare(strict_types=1);

use Telegga\Laravel\TelegramLinkStatus;
use Telegga\Laravel\TelegramUserStatus;

it('identifies whether a Telegga user exists', function (TelegramUserStatus $status, bool $exists): void {
    expect($status->existsInTelegga())->toBe($exists);
})->with([
    'not created' => [TelegramUserStatus::NotCreated, false],
    'active' => [TelegramUserStatus::Active, true],
    'disabled' => [TelegramUserStatus::Disabled, true],
]);

it('defines every supported Telegga bot-link status', function (): void {
    expect(array_map(
        static fn (TelegramLinkStatus $status): string => $status->value,
        TelegramLinkStatus::cases(),
    ))->toBe([
        'pending',
        'active',
        'blocked',
        'revoked',
    ]);
});
