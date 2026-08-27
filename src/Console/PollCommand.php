<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived;
use MeRezaRezaei\Teleproto\Facades\TP;
use Throwable;

/**
 * Long-polling runner for Telegram Bot updates in local development and queue workers.
 */
class PollCommand extends Command
{
    protected $signature = 'teleproto:poll
                            {--timeout=30 : Long-polling timeout in seconds}
                            {--limit=100 : Maximum updates to fetch per batch}';

    protected $description = 'Listen for incoming Telegram Bot updates via long-polling (Ideal for local development)';

    public function handle(): int
    {
        $this->components->info('Starting Telegram Bot update poller (Ctrl+C to stop)...');

        $offset = 0;
        $timeout = (int)$this->option('timeout');
        $limit = (int)$this->option('limit');

        $bot = TP::bot();

        while (true) {
            try {
                $response = $bot->call('getUpdates', [
                    'offset' => $offset,
                    'limit' => $limit,
                    'timeout' => $timeout,
                ]);

                $updates = $response['result'] ?? [];

                foreach ($updates as $update) {
                    $updateId = $update['update_id'] ?? 0;
                    $offset = max($offset, $updateId + 1);

                    $this->displayUpdateSummary($update);

                    // Dispatch Laravel Event
                    TelegramUpdateReceived::dispatch($update, $bot->botToken);
                }
            } catch (Throwable $e) {
                $this->components->error('Polling error: ' . $e->getMessage());
                sleep(2);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Prints a clean one-line summary of incoming updates in the console.
     *
     * @param array<string, mixed> $update
     */
    protected function displayUpdateSummary(array $update): void
    {
        $updateId = $update['update_id'] ?? 0;

        if (isset($update['message'])) {
            $msg = $update['message'];
            $from = $msg['from']['username'] ?? $msg['from']['first_name'] ?? 'User ' . ($msg['from']['id'] ?? '');
            $text = $msg['text'] ?? ($msg['caption'] ?? '[Media / Non-text]');
            $this->line("<fg=green>[#{$updateId}]</> Message from <fg=cyan>{$from}</>: {$text}");
        } elseif (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $from = $cb['from']['username'] ?? $cb['from']['first_name'] ?? 'User';
            $data = $cb['data'] ?? '';
            $this->line("<fg=yellow>[#{$updateId}]</> Callback Query from <fg=cyan>{$from}</>: data='{$data}'");
        } else {
            $type = array_keys(array_filter($update, fn($k) => $k !== 'update_id', ARRAY_FILTER_USE_KEY))[0] ?? 'unknown';
            $this->line("<fg=blue>[#{$updateId}]</> Received update type: <fg=magenta>{$type}</>");
        }
    }
}
