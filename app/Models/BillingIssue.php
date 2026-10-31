<?php

namespace App\Models;

use Database\Factories\BillingIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BillingIssue extends Model
{
    /** @use HasFactory<BillingIssueFactory> */
    use HasFactory;

    protected $fillable = ['issue_key', 'kind', 'subject_type', 'subject_id', 'title', 'detail', 'status', 'first_detected_at', 'last_detected_at', 'resolved_at', 'resolution_note'];

    protected function casts(): array
    {
        return ['first_detected_at' => 'immutable_datetime', 'last_detected_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public static function record(string $kind, string $subjectType, int $subjectId, string $title, string $detail, bool $present = true): void
    {
        DB::transaction(function () use ($kind, $subjectType, $subjectId, $title, $detail, $present): void {
            $key = $kind.':'.$subjectType.':'.$subjectId;
            if (! $present) {
                static::where('issue_key', $key)->where('status', 'open')->get()->each(
                    fn (self $issue) => $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => 'The next check confirmed that this condition is clear.'])
                );

                return;
            }
            $issue = static::firstOrCreate(['issue_key' => $key], [
                'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'title' => $title, 'detail' => $detail, 'status' => 'open',
                'first_detected_at' => now(), 'last_detected_at' => now(),
            ]);
            $issue = static::lockForUpdate()->findOrFail($issue->id);
            $issue->update(['detail' => $detail, 'status' => 'open', 'last_detected_at' => now(), 'resolved_at' => null, 'resolution_note' => null]);
        });
    }
}
