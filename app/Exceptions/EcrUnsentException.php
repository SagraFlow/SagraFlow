<?php

namespace App\Exceptions;

/**
 * The message never got through: the terminal could not be reached, the write
 * failed, or the terminal refused the frame outright. Nothing was processed, so
 * nothing can have been charged - which is what separates this from silence
 * after a message did leave.
 */
class EcrUnsentException extends EcrProtocolException {}
