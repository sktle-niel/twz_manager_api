<?php

namespace App\Services\Loyverse;

/** Loyverse rejected the token (401/403) — revoked, expired, or mistyped */
class LoyverseAuthException extends LoyverseException {}
