<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One collection run, keyed by run hash.
 */
class CollectionRun extends Model
{
    /**
     * Verdicts that mark a run as needing an operator's attention.
     *
     * @var array<int, string>
     */
    public const ATTENTION_VERDICTS = ['fail', 'reject', 'rejected', 'review', 'uncertain', 'partial'];

    /**
     * Verdict for a run whose sources exhausted cleanly (no recoverable error) with
     * at least one declared field still unfilled: the firm's public data ceiling was
     * reached, not a collection failure. Deliberately NOT in ATTENTION_VERDICTS and
     * exempt from the score floor — re-running collects nothing new, so it is never
     * "needs attention". A run stopped by our own wall-clock budget is 'partial'
     * instead, because a retry could still close the gap.
     */
    public const VERDICT_DATA_LIMITED = 'data_limited';

    /** Overall score below which a run needs attention regardless of verdict. */
    public const ATTENTION_SCORE_FLOOR = 0.5;

    protected $fillable = [
        'run_hash',
        'company_key',
        'company_code',
        'name',
        'session',
        'sources_used',
        'overall_score',
        'verdict',
        'status',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sources_used' => 'array',
            'overall_score' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Per-action log rows for this run (joined on the shared run hash).
     *
     * @return HasMany<CollectionAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(CollectionAction::class, 'run_hash', 'run_hash');
    }

    /**
     * Runs an operator should look at: failed status, a problem verdict, or a
     * low overall score. Shared by the table filter and the sidebar badge so
     * both stay in sync.
     *
     * @param  Builder<CollectionRun>  $query
     * @return Builder<CollectionRun>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->where('status', 'failed')
            ->orWhereIn('verdict', self::ATTENTION_VERDICTS)
            ->orWhere(fn (Builder $s): Builder => $s
                ->whereNotNull('overall_score')
                ->where('overall_score', '<', self::ATTENTION_SCORE_FLOOR)
                // A 'data_limited' run is exhausted, not broken — never flag it on
                // score alone. Any other (or no) verdict below the floor still is.
                ->where(fn (Builder $v): Builder => $v
                    ->whereNull('verdict')
                    ->orWhere('verdict', '!=', self::VERDICT_DATA_LIMITED))));
    }
}
