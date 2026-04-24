<?php

namespace App\Services\EnableBanking\Exceptions;

/** HTTP 401. Invalid JWT / app misconfigured. Hard fail per SPEC §10.1. */
class EnableBankingAuthException extends EnableBankingException {}
