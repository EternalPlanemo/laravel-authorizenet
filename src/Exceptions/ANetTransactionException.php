<?php

namespace ANet\Exceptions;

use net\authorize\api\contract\v1\CreateTransactionResponse;
use Throwable;

class ANetTransactionException extends ANetException
{
    public function __construct(CreateTransactionResponse $response, int $code = 0, Throwable $previous = null)
    {
        $transaction = $response->getTransactionResponse();

        $message = collect($transaction->getErrors() ?? [])->map(function ($error) {
            $message = match (intval($error->getErrorCode())) {
                11 => "A duplicate transaction has been submitted. Please wait 2 minutes.",
                default => $error->getErrorText(),
            };

            return "[Error {$error->getErrorCode()}] {$message}";
        })->join("\n");

        parent::__construct($message, $code, $previous);
    }
}
