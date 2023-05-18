<?php

namespace ANet\Exceptions;

use Exception;
use net\authorize\api\contract\v1\ANetApiResponseType;
use Throwable;

class ANetApiException extends Exception
{
    public function __construct(ANetApiResponseType $response, int $code = 0, Throwable $previous = null)
    {
        $message = $response->getMessages()->getMessage()[0]->getText();

        parent::__construct($message, $code, $previous);
    }
}
