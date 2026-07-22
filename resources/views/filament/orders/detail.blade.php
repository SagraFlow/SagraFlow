@php
    $money = fn (?int $cents): string => '€ '.number_format(($cents ?? 0) / 100, 2, '.', '');

    $groups = $order->lines
        ->sortBy(fn ($line) => $line->food?->category?->position ?? PHP_INT_MAX)
        ->groupBy(fn ($line) => $line->food?->category?->name ?? 'Senza categoria');

    $productCount = $order->lines->count();
    $unitCount = $order->lines->sum('quantity');
@endphp

<div class="od">
    <style>
        /* Filament exposes its palette as oklch() color values (Tailwind v4),
           so they are used directly as colors, with color-mix() for soft tints
           and explicit fallbacks in case a variable is missing. */
        .od {
            --od-border: var(--gray-200, #e4e4e7);
            --od-surface: var(--gray-50, #fafafa);
            --od-muted: var(--gray-500, #71717a);
            --od-accent: var(--primary-600, #b45309);
            --od-accent-soft: color-mix(in oklch, var(--primary-600, #b45309) 8%, transparent);
            --od-success: var(--success-600, #15803d);
            --od-badge: var(--gray-100, #f4f4f5);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            font-size: .875rem;
        }
        .dark .od {
            --od-border: var(--gray-700, #3f3f46);
            --od-surface: var(--gray-800, #27272a);
            --od-muted: var(--gray-400, #a1a1aa);
            --od-accent: var(--primary-400, #fbbf24);
            --od-accent-soft: color-mix(in oklch, var(--primary-400, #fbbf24) 12%, transparent);
            --od-success: var(--success-400, #4ade80);
            --od-badge: var(--gray-700, #3f3f46);
        }

        .od-card { border: 1px solid var(--od-border); border-radius: .75rem; background: var(--od-surface); }

        .od-hero { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; }
        .od-badge-status { display: inline-flex; align-items: center; gap: .375rem; padding: .2rem .55rem; border-radius: .5rem; font-size: .75rem; font-weight: 600; color: var(--od-accent); background: var(--od-accent-soft); }
        .od-dot { width: .45rem; height: .45rem; border-radius: 9999px; background: currentColor; }
        .od-pay { display: flex; align-items: center; gap: .4rem; margin-top: .6rem; color: var(--od-muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; }
        .od-total { font-size: 1.5rem; font-weight: 600; color: var(--od-accent); line-height: 1; text-align: right; }
        .od-count { margin-top: .35rem; color: var(--od-muted); font-size: .75rem; text-align: right; }

        .od-info { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--od-border); border: 1px solid var(--od-border); border-radius: .75rem; overflow: hidden; }
        .od-cell { background: var(--od-surface); padding: .625rem .875rem; }
        .od-cell-label { font-size: .6875rem; text-transform: uppercase; letter-spacing: .03em; color: var(--od-muted); font-weight: 500; }
        .od-cell-value { margin-top: .2rem; font-weight: 500; }

        .od-heading { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: var(--od-muted); font-weight: 600; margin-bottom: .5rem; }
        .od-heading span { color: var(--od-accent); }

        .od-group + .od-group { margin-top: .75rem; }
        .od-group-label { font-size: .6875rem; text-transform: uppercase; letter-spacing: .03em; font-weight: 600; color: var(--od-muted); margin-bottom: .375rem; }

        .od-line { display: flex; align-items: flex-start; gap: .625rem; padding: .5rem .75rem; border: 1px solid var(--od-border); border-radius: .5rem; background: var(--od-surface); }
        .od-line + .od-line { margin-top: .375rem; }
        .od-qty { flex: none; min-width: 1.9rem; text-align: center; padding: .1rem .35rem; border-radius: .375rem; background: var(--od-badge); font-weight: 600; font-size: .75rem; font-variant-numeric: tabular-nums; }
        .od-line-main { flex: 1; min-width: 0; }
        .od-line-name { font-weight: 500; }
        .od-line-sub { margin-top: .1rem; font-size: .75rem; color: var(--od-accent); }
        .od-line-note { margin-top: .1rem; font-size: .75rem; font-style: italic; color: var(--od-muted); }
        .od-line-total { flex: none; font-weight: 500; font-variant-numeric: tabular-nums; }

        .od-totals { border: 1px solid var(--od-border); border-radius: .75rem; overflow: hidden; }
        .od-trow { display: flex; justify-content: space-between; padding: .5rem .875rem; color: var(--od-muted); }
        .od-trow + .od-trow { border-top: 1px solid var(--od-border); }
        .od-trow-val { font-variant-numeric: tabular-nums; }
        .od-trow--discount .od-trow-val { color: var(--od-success); }
        .od-trow--total { background: var(--od-accent-soft); font-weight: 600; }
        .od-trow--total .od-trow-val { color: var(--od-accent); }

        .od-ico { width: 1rem; height: 1rem; }
    </style>

    {{-- Hero: status, payment, total --}}
    <div class="od-card od-hero">
        <div>
            <span class="od-badge-status">
                <span class="od-dot"></span>
                {{ $order->status->getLabel() }}
            </span>
            <div class="od-pay">
                @if ($order->payment_method?->value === 'card')
                    <x-heroicon-o-credit-card class="od-ico" />
                @else
                    <x-heroicon-o-banknotes class="od-ico" />
                @endif
                {{ $order->payment_method?->getLabel() ?? '-' }}
            </div>
        </div>
        <div>
            <div class="od-total">{{ $money($order->total) }}</div>
            <div class="od-count">{{ $productCount }} prodotti · {{ $unitCount }} unità</div>
        </div>
    </div>

    {{-- Info grid (3x3) --}}
    <div class="od-info">
        <div class="od-cell">
            <div class="od-cell-label">N. ordine</div>
            <div class="od-cell-value">{{ $order->number }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Data/Ora</div>
            <div class="od-cell-value">{{ $order->paid_at?->format('d/m/Y H:i') ?? '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Giornata</div>
            <div class="od-cell-value">{{ $order->eventDay?->displayName ?? '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Servizio</div>
            <div class="od-cell-value">{{ $order->service_type?->getLabel() ?? '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Tavolo</div>
            <div class="od-cell-value">{{ $order->table_number ?? '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Coperti</div>
            <div class="od-cell-value">{{ $order->covers ?: '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Cliente</div>
            <div class="od-cell-value">{{ $order->customer_name ?: '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Cassa</div>
            <div class="od-cell-value">{{ $order->cashRegister?->name ?? '-' }}</div>
        </div>
        <div class="od-cell">
            <div class="od-cell-label">Operatore</div>
            <div class="od-cell-value">{{ $order->operator?->name ?? '-' }}</div>
        </div>
    </div>

    {{-- Products grouped by category --}}
    <div>
        <div class="od-heading">Prodotti <span>{{ $productCount }}</span></div>
        @foreach ($groups as $categoryName => $lines)
            <div class="od-group">
                <div class="od-group-label">{{ $categoryName }}</div>
                @foreach ($lines as $line)
                    <div class="od-line">
                        <div class="od-qty">{{ $line->quantity }}x</div>
                        <div class="od-line-main">
                            <div class="od-line-name">{{ $line->food_name }}</div>
                            @if ($line->deviationSummary() !== '')
                                <div class="od-line-sub">{{ $line->deviationSummary() }}</div>
                            @endif
                            @if ($line->note)
                                <div class="od-line-note">"{{ $line->note }}"</div>
                            @endif
                        </div>
                        <div class="od-line-total">{{ $money($line->line_total) }}</div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Totals --}}
    <div class="od-totals">
        <div class="od-trow">
            <span>Subtotale</span>
            <span class="od-trow-val">{{ $money($order->subtotal) }}</span>
        </div>
        @if ($order->coverTotal() > 0)
            <div class="od-trow">
                <span>Coperto ({{ $order->covers }}x {{ $money($order->cover_charge) }})</span>
                <span class="od-trow-val">{{ $money($order->coverTotal()) }}</span>
            </div>
        @endif
        @if ($order->discount_amount > 0)
            <div class="od-trow od-trow--discount">
                <span>Sconto{{ $order->discount_applies_to_cover ? ' (coperto incluso)' : '' }}</span>
                <span class="od-trow-val">- {{ $money($order->discount_amount) }}</span>
            </div>
        @endif
        <div class="od-trow od-trow--total">
            <span>Totale</span>
            <span class="od-trow-val">{{ $money($order->total) }}</span>
        </div>
    </div>
</div>
