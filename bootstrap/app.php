<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Printer health heartbeat + queue reconciliation.
        $schedule->command('printers:poll')->everyFifteenSeconds()->withoutOverlapping();
        $schedule->command('print:reconcile')->everyMinute()->withoutOverlapping();
        // Free stock held by checkouts that never confirmed nor cancelled.
        $schedule->command('stock:release-reservations')->everyMinute()->withoutOverlapping();
        // Populate the Horizon metrics dashboard.
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Reuse the Filament panel login for guarded web pages (e.g. the POS).
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
