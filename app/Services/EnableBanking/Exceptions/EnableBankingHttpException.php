<?php

namespace App\Services\EnableBanking\Exceptions;

/** Non-retriable HTTP error that doesn't fit another category (400, 404, 422, etc.). */
class EnableBankingHttpException extends EnableBankingException {}
