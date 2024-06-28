<?php

namespace ANet\PaymentProfile;

use ANet\AuthorizeNet;
use ANet\Exceptions\ANetLogicException;
use ANet\Exceptions\ANetTransactionException;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\contract\v1\CreateTransactionResponse;
use net\authorize\api\contract\v1\TransactionResponseType;
use net\authorize\api\controller as AnetControllers;

/**
 * @throws ANetLogicException
 * @throws ANetTransactionException
 */
class PaymentProfileCharge extends AuthorizeNet
{
    public function charge(int $cents, int|string $paymentProfileId, ?AnetAPI\CustomerAddressType $address = null)
    {
        $amount = $this->convertCentsToDollar($cents);
        $customerId = (string) $this->user->getCustomerProfileId();
        $paymentProfileId = (string) $paymentProfileId;

        // Set the transaction's refId
        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($customerId);

        $paymentProfile = new AnetAPI\PaymentProfileType();
        $paymentProfile->setPaymentProfileId($paymentProfileId);

        $profileToCharge->setPaymentProfile($paymentProfile);

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType('authCaptureTransaction');

        $transactionRequestType->setAmount($amount);
        $transactionRequestType->setProfile($profileToCharge);

        if (isset($address)) {
            $transactionRequestType->setBillTo($address);
        }

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($this->getMerchantAuthentication());
        $request->setRefId($this->getRefId());
        $request->setTransactionRequest($transactionRequestType);
        $controller = new AnetControllers\CreateTransactionController($request);

        /** @var CreateTransactionResponse $response */
        $response = $this->execute($controller);

        /** @var TransactionResponseType $transaction */
        $transaction = $response->getTransactionResponse();

        if (empty($response) || empty($transaction)) {
            throw new ANetLogicException('Transaction Failed.');
        }

        $success = $response->getMessages()->getResultCode() === 'Ok'
            && empty($response->getTransactionResponse()->getErrors());

        if (! $success) {
            throw new ANetTransactionException($response);
        }

        return $response;
    }
}
