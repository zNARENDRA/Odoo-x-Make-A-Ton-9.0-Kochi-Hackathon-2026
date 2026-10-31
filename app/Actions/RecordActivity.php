<?php

namespace App\Actions;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class RecordActivity
{
    public function handle(Model $model, string $action): void
    {
        $keys = array_diff($model->getFillable(), ['snapshot', 'last_detected_at', 'detail']);
        $after = Arr::only($model->getAttributes(), $keys);
        $before = Arr::only($model->getRawOriginal(), $keys);
        if ($action === 'updated') {
            $keys = array_intersect(array_keys($model->getChanges()), $keys);
            if (! $keys) {
                return;
            }
            $before = Arr::only($before, $keys);
            $after = Arr::only($after, $keys);
        }
        $actor = Auth::user();
        Activity::create([
            'actor_id' => $actor?->id, 'actor_name' => $actor?->name ?? 'System',
            'actor_role' => $actor?->role ?? 'system', 'subject_type' => class_basename($model), 'subject_id' => $model->getKey(),
            'action' => $action, 'before_values' => $action === 'created' ? [] : $before,
            'after_values' => $action === 'deleted' ? [] : $after,
        ]);
    }
}
