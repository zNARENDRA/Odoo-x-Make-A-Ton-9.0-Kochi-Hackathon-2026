<?php

return [
    'currency' => env('BILLING_CURRENCY', 'INR'),
    'payment_reminder_days' => (int) env('BILLING_PAYMENT_REMINDER_DAYS', 3),
    'renewal_reminder_days' => (int) env('BILLING_RENEWAL_REMINDER_DAYS', 7),
    'emails_per_minute' => (int) env('BILLING_EMAILS_PER_MINUTE', 8),
];
