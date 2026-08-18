<?php

namespace App\CardPayments;

use App\CardPayments\Protocol\EcrRequest;
use App\CardPayments\Protocol\EcrResponse;
use App\CardPayments\Protocol\TerminalStatus;
use App\Exceptions\EcrProtocolException;
use App\Models\CardTerminal;

/**
 * Asks a terminal how it is, on request.
 *
 * On request and not on a timer, deliberately: the terminal holds one ECR
 * conversation at a time, and a probe that fired every few seconds would
 * eventually land in the middle of somebody's payment. Someone checking the
 * terminals before service is worth answering; nobody needs the question asked
 * behind their back.
 */
class TerminalProbe
{
    /** Long enough for a wireless terminal, short enough not to sit there. */
    public const TIMEOUT = 10;

    public function __construct(private EcrConnection $connection) {}

    /**
     * @return array{status: ?TerminalStatus, error: ?string, busyWith: ?string}
     */
    public function probe(CardTerminal $terminal): array
    {
        // A payment in progress owns the conversation. Asking now would at best
        // be refused, at worst talk over a customer typing their PIN.
        if ($terminal->isBusy()) {
            return ['status' => null, 'error' => null, 'busyWith' => $terminal->busyRegisterName() ?? '-'];
        }

        try {
            $payload = $this->connection->request(
                host: $terminal->ip_address,
                port: $terminal->port,
                payload: EcrRequest::terminalStatus($terminal->terminal_id),
                readTimeout: self::TIMEOUT,
                attempts: 1,
            );
        } catch (EcrProtocolException $exception) {
            return ['status' => null, 'error' => $exception->getMessage(), 'busyWith' => null];
        }

        return ['status' => EcrResponse::terminalStatus($payload), 'error' => null, 'busyWith' => null];
    }
}
