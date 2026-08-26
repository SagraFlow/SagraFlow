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
        // Guarded web pages (the POS) send their guests to the till's own
        // sign-in, never to the panel's: Filament will not authenticate anyone
        // who could not open the panel afterwards, which is every cashier.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // And a tablet that is already signed in, landing on that form, goes
        // straight through to the till rather than to the welcome page.
        $middleware->redirectUsersTo(fn () => route('pos'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A till account that lands in the panel is taken to the till instead of
        // being met by a forbidden page: a bookmark kept from another tablet, an
        // address typed by hand, a link followed by mistake. Handled here rather
        // than with middleware: what runs before the panel's guard is decided by
        // the framework's priority list, not by the order of the panel's
        // middleware array, and anything unknown to that list runs last - after
        // the guard has already refused.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request): ?RedirectResponse {
            $user = $request->user();

            return $e->getStatusCode() === 403 && $request->routeIs('filament.*') && $user !== null && ! $user->isAdministrator()
                ? redirect()->route('pos')
                : null;
        });
    })->create();
