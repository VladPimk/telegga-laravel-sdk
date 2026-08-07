<?php

declare(strict_types=1);

namespace Telegga\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Support\ExceptionLogContext;

final class ClearWebhookEventsCommand extends Command
{
    private const int CHUNK_SIZE = 1000;

    protected $signature = 'telegga:webhook-events:clear
                            {days=90 : Delete events older than this number of days}';

    protected $description = 'Delete old Telegga webhook event records';

    /**
     * Delete old webhook event records.
     */
    public function handle(): int
    {
        $rawDays = $this->argument('days');
        $days = filter_var(
            $rawDays,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ],
        );

        if ($days === false) {
            Log::warning('Telegga webhook event cleanup was rejected.', [
                'status' => 'failed',
                'days' => $rawDays,
                'deleted_records' => 0,
                'error_code' => 'invalid_days',
            ]);
            $this->error('The days argument must be a positive integer.');

            return self::FAILURE;
        }

        $deleted = 0;
        $threshold = now()->subDays($days);

        try {
            TeleggaWebhookEvent::query()
                ->select('id')
                ->where('created_at', '<', $threshold)
                ->chunkById(self::CHUNK_SIZE, function (Collection $events) use (&$deleted): void {
                    $deleted += TeleggaWebhookEvent::query()
                        ->whereKey($events->modelKeys())
                        ->delete();
                });
        } catch (QueryException $exception) {
            Log::error('Telegga webhook event cleanup failed.', [
                'status' => 'failed',
                'days' => $days,
                'deleted_records' => $deleted,
                'error_code' => 'database_error',
                'exception' => ExceptionLogContext::from(exception: $exception),
            ]);

            report($exception);

            $this->error('Telegga webhook event records could not be deleted.');

            return self::FAILURE;
        }

        Log::info('Telegga webhook event cleanup completed.', [
            'status' => 'success',
            'days' => $days,
            'deleted_records' => $deleted,
        ]);

        $this->info("Deleted {$deleted} Telegga webhook event records older than {$days} days.");

        return self::SUCCESS;
    }
}
