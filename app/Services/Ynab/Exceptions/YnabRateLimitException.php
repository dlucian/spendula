<?php

namespace App\Services\Ynab\Exceptions;

/** HTTP 429. Back off 60s and retry per SPEC §10.2; this is raised after retry exhaustion. */
class YnabRateLimitException extends YnabException {}
