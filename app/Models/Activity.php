<?php

namespace App\Models;

use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected $fillable = ['actor_id', 'actor_name', 'actor_role', 'subject_type', 'subject_id', 'action', 'before_values', 'after_values'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity records cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Activity records cannot be deleted.'));
    }
}
