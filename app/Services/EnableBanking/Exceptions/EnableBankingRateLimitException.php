<?php

namespace App\Services\EnableBanking\Exceptions;

/** HTTP 429. Abort this account cleanly, persist continuation key, continue with others. */
class EnableBankingRateLimitException extends EnableBankingException {}
