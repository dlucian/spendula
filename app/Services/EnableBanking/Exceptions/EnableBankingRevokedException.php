<?php

namespace App\Services\EnableBanking\Exceptions;

/** HTTP 403. Consent revoked by PSU. Mark connection revoked per SPEC §10.1. */
class EnableBankingRevokedException extends EnableBankingException {}
