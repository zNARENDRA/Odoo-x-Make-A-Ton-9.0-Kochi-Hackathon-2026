<?php

namespace App\Models;

use Database\Factories\BillingEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingEmail extends Model
{
    /** @use HasFactory<BillingEmailFactory> */
    use HasFactory;

    protected $fillable = ['event_key', 'kind', 'subscription_id', 'invoice_id', 'payment_id', 'billing_on', 'queued_at', 'sent_at', 'suppressed_at', 'failed_at'];

    protected function casts(): array
    {
        return ['billing_on' => 'immutable_date', 'queued_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'suppressed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];
    }
}
