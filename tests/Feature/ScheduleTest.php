<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduled tasks are the safety nets of a service: the printer heartbeat
 * that releases held prints, the reconcilers that recover a lost job or an
 * unanswered payment. A run killed mid-flight leaves its overlapping lock
 * behind, and Laravel's default expiry for that lock is a day - which turns a
 * container restart into a night without a heartbeat. Nobody notices, because
 * everything else keeps working.
 */
it('lets no scheduled task silence itself for more than a few minutes', function () {
    // The schedule is registered when the console application starts, so it has
    // to be started before there is anything to look at.
    test()->artisan('schedule:list')->run();

    $overlapping = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->withoutOverlapping);

    expect($overlapping)->not->toBeEmpty();

    foreach ($overlapping as $event) {
        expect($event->expiresAt)->toBeLessThanOrEqual(5, $event->command.' holds its lock too long');
    }
});
