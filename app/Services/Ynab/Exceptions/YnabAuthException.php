<?php

namespace App\Services\Ynab\Exceptions;

/** HTTP 401. Hard fail per SPEC §10.2 — operator fixes SPENDULA_YNAB_ACCESS_TOKEN. */
class YnabAuthException extends YnabException {}
