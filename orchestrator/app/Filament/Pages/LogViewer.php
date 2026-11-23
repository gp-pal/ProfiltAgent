<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Read-only tail of storage/logs/laravel.log so an operator can watch the
 * enrichment process without shelling in. The log can grow past a megabyte,
 * so this never loads the whole file: it seeks to the last ~64KB before
 * reading, then keeps only the last ~200 lines. Any failure (missing or
 * unreadable file) degrades to a placeholder message instead of a 500.
 */
class LogViewer extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Logs';

    protected static string | UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?string $title = 'Logs';

    protected string $view = 'filament.pages.log-viewer';

    private const TAIL_BYTES = 65536;

    private const TAIL_LINES = 200;

    public function getLogPath(): string
    {
        return (string) config('logviewer.path', storage_path('logs/laravel.log'));
    }

    /**
     * Last ~200 lines of the log file, read from only the last ~64KB of
     * bytes so a multi-megabyte log never gets loaded in full. Fail-soft:
     * any I/O error returns a friendly placeholder instead of throwing.
     */
    public function logTail(): string
    {
        $path = $this->getLogPath();

        try {
            if (! is_file($path) || ! is_readable($path)) {
                return 'No log file yet.';
            }

            $size = filesize($path);

            if ($size === false) {
                return 'No log file yet.';
            }

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return 'No log file yet.';
            }

            try {
                fseek($handle, max(0, $size - self::TAIL_BYTES));
                $chunk = stream_get_contents($handle);
            } finally {
                fclose($handle);
            }

            if ($chunk === false || trim($chunk) === '') {
                return 'Log file is empty.';
            }

            $lines = preg_split('/\r\n|\r|\n/', $chunk) ?: [];
            $lines = array_slice($lines, -self::TAIL_LINES);

            return trim(implode("\n", $lines));
        } catch (Throwable) {
            return 'No log file yet.';
        }
    }
}
