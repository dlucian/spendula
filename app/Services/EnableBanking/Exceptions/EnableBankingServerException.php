<?php

namespace App\Services\EnableBanking\Exceptions;

/** HTTP 5xx after retries have been exhausted. Abort this account per SPEC §10.1. */
class EnableBankingServerException extends EnableBankingException {}
