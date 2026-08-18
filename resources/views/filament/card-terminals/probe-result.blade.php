{{-- What the terminal answered, or why it did not. Same visual language as the
     order detail: hero with the verdict, then the facts in a grid. --}}
<div class="tp">
    <style>
        /* Filament exposes its palette as oklch() values (Tailwind v4), used
           directly here, with color-mix() for the soft tints. */
        .tp {
            --tp-border: var(--gray-200, #e4e4e7);
            --tp-surface: var(--gray-50, #fafafa);
            --tp-muted: var(--gray-500, #71717a);
            --tp-ok: var(--success-600, #15803d);
            --tp-warn: var(--warning-600, #ca8a04);
            --tp-bad: var(--danger-600, #dc2626);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            font-size: .875rem;
        }
        .dark .tp {
            --tp-border: var(--gray-700, #3f3f46);
            --tp-surface: var(--gray-800, #27272a);
            --tp-muted: var(--gray-400, #a1a1aa);
            --tp-ok: var(--success-400, #4ade80);
            --tp-warn: var(--warning-400, #facc15);
            --tp-bad: var(--danger-400, #f87171);
        }

        .tp-hero { display: flex; align-items: flex-start; gap: .75rem; padding: 1rem 1.25rem; border: 1px solid var(--tp-border); border-radius: .75rem; background: var(--tp-surface); }
        .tp-hero--ok { --tp-tone: var(--tp-ok); }
        .tp-hero--warn { --tp-tone: var(--tp-warn); }
        .tp-hero--bad { --tp-tone: var(--tp-bad); }
        .tp-ico { flex: none; width: 1.5rem; height: 1.5rem; color: var(--tp-tone); }
        .tp-verdict { font-size: 1.125rem; font-weight: 600; color: var(--tp-tone); line-height: 1.2; }
        .tp-note { margin-top: .3rem; color: var(--tp-muted); }

        .tp-info { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--tp-border); border: 1px solid var(--tp-border); border-radius: .75rem; overflow: hidden; }
        .tp-cell { background: var(--tp-surface); padding: .625rem .875rem; min-width: 0; }
        .tp-cell-label { font-size: .6875rem; text-transform: uppercase; letter-spacing: .03em; color: var(--tp-muted); font-weight: 500; }
        .tp-cell-value { margin-top: .2rem; font-weight: 500; overflow-wrap: anywhere; }

        .tp-hints { border: 1px solid var(--tp-border); border-radius: .75rem; overflow: hidden; }
        .tp-hint { display: flex; gap: .625rem; padding: .5rem .875rem; color: var(--tp-muted); }
        .tp-hint + .tp-hint { border-top: 1px solid var(--tp-border); }
        .tp-hint-n { flex: none; min-width: 1.4rem; height: 1.4rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 9999px; background: var(--tp-border); font-size: .75rem; font-weight: 600; }
    </style>

    @if ($busyWith !== null)
        <div class="tp-hero tp-hero--warn">
            <x-heroicon-o-credit-card class="tp-ico" />
            <div>
                <div class="tp-verdict">Terminale occupato</div>
                <p class="tp-note">Lo sta usando «{{ $busyWith }}». Non lo interrogo durante un pagamento: riprova quando è libero.</p>
            </div>
        </div>
    @elseif ($error !== null)
        <div class="tp-hero tp-hero--bad">
            <x-heroicon-o-exclamation-triangle class="tp-ico" />
            <div>
                <div class="tp-verdict">Nessuna risposta</div>
                <p class="tp-note">{{ $error }}</p>
            </div>
        </div>

        {{-- In quest'ordine: dal più probabile al meno. --}}
        <div class="tp-hints">
            <div class="tp-hint"><span class="tp-hint-n">1</span><span>Il terminale è acceso e collegato alla stessa rete?</span></div>
            <div class="tp-hint"><span class="tp-hint-n">2</span><span>L'app Scambio Importo è aperta e in ascolto?</span></div>
            <div class="tp-hint"><span class="tp-hint-n">3</span><span>Indirizzo e porta sono quelli configurati sul terminale?</span></div>
        </div>
    @else
        <div class="tp-hero {{ $status->isOperative() ? 'tp-hero--ok' : 'tp-hero--warn' }}">
            <x-dynamic-component :component="$status->isOperative() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" class="tp-ico" />
            <div>
                <div class="tp-verdict">{{ $status->label() }}</div>
                <p class="tp-note">
                    {{ $status->isOperative()
                        ? 'Il terminale risponde ed è pronto a incassare.'
                        : 'Il terminale risponde, ma in questo stato non può accettare pagamenti.' }}
                </p>
            </div>
        </div>

        <div class="tp-info">
            <div class="tp-cell">
                <div class="tp-cell-label">Terminal ID</div>
                <div class="tp-cell-value">{{ $status->terminalId }}</div>
            </div>
            <div class="tp-cell">
                {{-- Worth a glance: the terminal's clock is what ends up on the
                     transaction, and a drifted one makes an evening hard to
                     reconstruct. --}}
                <div class="tp-cell-label">Orologio</div>
                <div class="tp-cell-value">{{ $clock }}</div>
            </div>
            <div class="tp-cell">
                <div class="tp-cell-label">Software</div>
                <div class="tp-cell-value">{{ $status->softwareRelease ?? '-' }}</div>
            </div>
        </div>
    @endif
</div>
