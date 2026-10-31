<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:generate-invoices')->everyMinute()->withoutOverlapping(30);
