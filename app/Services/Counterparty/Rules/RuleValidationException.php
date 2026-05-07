<?php

namespace App\Services\Counterparty\Rules;

use RuntimeException;

/**
 * Thrown by RuleLoader when a rule file fails validation. The message
 * names the file path and (when applicable) the offending rule's name
 * so the operator can locate the issue quickly.
 *
 * Add-time validation in CounterpartyRulesAddCommand catches the same
 * class of errors before writing the file; this exception is the
 * load-time fatal that fires if a hand-edited file slips past.
 */
class RuleValidationException extends RuntimeException {}
