<?php

namespace App\CardPayments\Protocol;

/**
 * One frame off the wire: what it is, and what it carried.
 */
final readonly class DecodedFrame
{
    public function __construct(
        public EcrFrameType $type,
        public string $payload,
    ) {}

    public function isApplication(): bool
    {
        return $this->type === EcrFrameType::Application;
    }

    public function isProgress(): bool
    {
        return $this->type === EcrFrameType::Progress;
    }

    /** The message code, the letter that says what this payload is. */
    public function messageCode(): ?string
    {
        return $this->isApplication() && strlen($this->payload) >= 10 ? $this->payload[9] : null;
    }

    /** A progress line, trimmed for showing to the cashier. */
    public function progressText(): string
    {
        return trim($this->payload);
    }
}
