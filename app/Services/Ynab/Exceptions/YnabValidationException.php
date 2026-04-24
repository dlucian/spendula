<?php

namespace App\Services\Ynab\Exceptions;

/** HTTP 4xx other than 401/429. Usually malformed payload or missing required field. */
class YnabValidationException extends YnabException {}
