<?php

namespace App\CardPayments\Protocol;

/**
 * What arrived on the wire. Only Application carries a payload worth parsing;
 * the other three are the plumbing of the conversation.
 */
enum EcrFrameType
{
    /** A real message: a result, a status, a last result. */
    case Application;

    /** The other end took our message. */
    case Ack;

    /** The other end rejected it: we resend, up to three times. */
    case Nak;

    /**
     * A line of progress while the terminal talks to the host ("inserire
     * carta", "attendere"...). It needs no answer and is not the outcome: it
     * exists to be shown to the cashier while she waits.
     */
    case Progress;
}
