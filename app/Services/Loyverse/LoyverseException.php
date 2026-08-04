<?php

namespace App\Services\Loyverse;

use RuntimeException;

/** Base failure talking to Loyverse — anything not covered by a subclass */
class LoyverseException extends RuntimeException {}
