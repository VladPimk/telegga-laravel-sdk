<?php

declare(strict_types=1);

namespace Telegga\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Support\ExceptionLogContext;

final class SyncTelegramBotsCommand extends Command
{
    protected $signature = 'telegga:bots:sync';

    protected $description = 'Synchronize active Telegga bots with local available bots';

    /**
     * Create the command.
     */
    public function __construct(
        private readonly BotService $bots,
    ) {
        parent::__construct();
    }

    /**
     * Synchronize active Telegga bots.
     */
    public function handle(): int
    {
        try {
            $result = $this->bots->sync();
        } catch (BotException $exception) {
            Log::error('Telegga bot synchronization failed.', [
                'status' => 'failed',
                'error_code' => 'synchronization_failed',
                'exception' => ExceptionLogContext::from(exception: $exception),
            ]);

            report($exception);

            $this->error('Telegga bots could not be synchronized.');

            return self::FAILURE;
        }

        Log::info('Telegga bot synchronization completed.', [
            'status' => 'success',
            'received_bots' => $result->received,
            'active_bots' => $result->active,
            'created_bots' => $result->created,
            'existing_bots' => $result->existing,
        ]);

        $this->info(
            "Telegram bots synchronized: received {$result->received}, active {$result->active}, created {$result->created}, existing {$result->existing}.",
        );

        return self::SUCCESS;
    }
}
