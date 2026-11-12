<?php
 
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
 
/**
 * Sales report batch.
 *
 * Runs every 3 minutes so the admin Sales Report is never more than a few
 * minutes behind the live orders table. The page has no manual "run" control —
 * this schedule is the only thing that refreshes the report tables.
 *
 * withoutOverlapping() is not optional here. The job re-exports every order
 * line item, re-runs the COBOL binary over all history, then rebuilds all six
 * report tables with DELETE + INSERT. Two runs overlapping would have both
 * deleting and inserting the same rows, and the report tables would be left
 * holding whichever interleaving won. The 10-minute argument is the lock
 * expiry, so a crashed run can't wedge the schedule permanently.
 *
 * Output goes to its own log rather than being discarded — a silent scheduled
 * job that has been failing for a week is worse than no job at all.
 */
Schedule::command('sales:run-batch')
    ->everyThreeMinutes()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/sales-batch.log'));
 
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
 
 