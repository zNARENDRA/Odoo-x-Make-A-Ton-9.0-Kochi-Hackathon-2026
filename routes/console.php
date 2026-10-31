<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:generate-invoices')->everyMinute()->withoutOverlapping(30);
Schedule::command('billing:send-reminders')->hourly()->withoutOverlapping(30);
Schedule::command('billing:dispatch-emails')->everyMinute()->withoutOverlapping(30);
Schedule::command('billing:check-problems')->everyFiveMinutes()->withoutOverlapping(10);
