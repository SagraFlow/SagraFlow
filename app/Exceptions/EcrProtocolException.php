<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A message that does not respect the protocol: bad framing, bad checksum, a
 * field that cannot be built. Never used for a payment that simply went badly -
 * a refused card is a perfectly valid message.
 */
class EcrProtocolException extends RuntimeException {}
