<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One work history entry (MASTER_SPEC §19). end_year null = "present". */
class WorkExperience extends Model
{
    protected $fillable = ['company', 'position', 'start_year', 'end_year', 'sort_order'];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Shape used in API responses and the audit log. */
    public function toEntry(): array
    {
        return [
            'company' => $this->company,
            'position' => $this->position,
            'start_year' => $this->start_year,
            'end_year' => $this->end_year ?? 'present',
        ];
    }
}
