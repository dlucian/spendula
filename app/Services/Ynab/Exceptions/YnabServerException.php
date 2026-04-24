<?php

namespace App\Services\Ynab\Exceptions;

/** HTTP 5xx after the client's retry budget is exhausted. */
class YnabServerException extends YnabException {}
