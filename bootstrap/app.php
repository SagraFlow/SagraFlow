<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Every withoutOverlapping here carries its own expiry, deliberately.
        // The default is a day: a run killed mid-flight (a container restarted,
        // a machine rebooted) leaves its lock behind, and the task then skips
        // every turn until tomorrow. These are the safety nets of a sagra
        // evening, so each one is allowed to be silent for minutes at worst -
        // just long enough that a slow run is not trampled by the next.
        //
        // Printer health heartbeat + queue reconciliation.
        $schedule->command('printers:poll')->everyFifteenSeconds()->withoutOverlapping(1);
        $schedule->command('print:reconcile')->everyMinute()->withoutOverlapping(2);
        // Free stock held by checkouts that never confirmed nor cancelled.
        $schedule->command('stock:release-reservations')->everyMinute()->withoutOverlapping(2);
        // Ask the terminals about payments left without an answer, while they
        // can still answer: their memory holds one transaction, and a station
        // that takes the terminal next overwrites it. Given more room than the
        // others: it talks to devices over the network, one at a time.
        $schedule->command('card:reconcile')->everyMinute()->withoutOverlapping(5);
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

        // A till account that lands in the panel is taken to the till instead of
        // being met by a forbidden page. That is the path a cashier walks every
        // time the tablet is logged out, because the sign-in form is the panel's
        // own. Handled here rather than with middleware: what runs before the
        // panel's guard is decided by the framework's priority list, not by the
        // order of the panel's middleware array, and anything unknown to that
        // list runs last - after the guard has already refused.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request): ?RedirectResponse {
            $user = $request->user();

            return $e->getStatusCode() === 403 && $request->routeIs('filament.*') && $user !== null && ! $user->isAdministrator()
                ? redirect()->route('pos')
                : null;
        });
    })->create();
