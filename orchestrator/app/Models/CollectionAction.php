<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single action within a collection run (one source touched).
 */
class CollectionAction extends Model
{
    protected $fillable = [
        'run_hash',
        'source',
        'action',
        'status',
        'yielded',
        'field_scores',
        'detail',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yielded' => 'array',
            'field_scores' => 'array',
        ];
    }
}
