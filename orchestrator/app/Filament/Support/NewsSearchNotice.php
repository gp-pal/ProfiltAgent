<?php

namespace App\Filament\Support;

use App\Services\NewsSource;

/**
 * The sentence the panel shows when a run's news search could not see. One place for
 * both the run view and the profile view, so neither can read a failed search as
 * "no mentions".
 */
class NewsSearchNotice
{
    /**
     * Null when the news action is not a failed search.
     */
    public static function forAction(string $status, ?string $detail): ?string
    {
        if ($status !== 'error') {
            return null;
        }

        $decoded = json_decode((string) $detail, true);
        $state = is_array($decoded) ? ($decoded['news']['search_state'] ?? null) : null;

        return match ($state) {
            NewsSource::SEARCH_UNANSWERED => 'News search was not answered by the search backend — unknown whether mentions exist.',
            NewsSource::SEARCH_RETURNED_NOTHING => 'News search returned no results at all — unknown whether mentions exist.',
            NewsSource::SEARCH_OFF_PORTAL => 'News search did not reach the news portals — unknown whether mentions exist.',
            default => 'News search failed — unknown whether mentions exist.',
        };
    }

    /**
     * Escaped warning line, or '' when there is nothing to warn about.
     */
    public static function html(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        return '<div style="margin-bottom:0.4rem;color:#f59e0b">' . e($message) . '</div>';
    }
}
