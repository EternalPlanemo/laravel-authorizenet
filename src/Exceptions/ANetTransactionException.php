<?php

namespace ANet\Exceptions;

use net\authorize\api\contract\v1\CreateTransactionResponse;
use Throwable;
use net\authorize\api\contract\v1\TransactionResponseType\ErrorsAType\ErrorAType;

class ANetTransactionException extends ANetException
{
    public function __construct(public CreateTransactionResponse $response, int $code = 0, ?Throwable $previous = null)
    {
        $errors = $response->getTransactionResponse()
            ?->getErrors()
            ?? [];

        $message = collect($errors)->map(function (ErrorAType $error) {
            $message = match (intval($error->getErrorCode())) {
                11 => 'A duplicate transaction has been submitted. Please wait 2 minutes.',
                default => $error->getErrorText(),
            };

            return $message;
        })->join("\n");

        $message = empty($message)
            ? 'Transaction failed for an unknown reason.'
            : $message;

        parent::__construct($message, $code, $previous);
    }
}
