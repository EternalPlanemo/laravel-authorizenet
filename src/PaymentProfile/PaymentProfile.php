<?php

namespace ANet\PaymentProfile;

use ANet\AuthorizeNet;
use ANet\Exceptions\ANetApiException;
use DB;
use Illuminate\Support\Collection;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\contract\v1\GetCustomerProfileResponse;
use net\authorize\api\controller as AnetControllers;

class PaymentProfile extends AuthorizeNet
{
    public function get(): Collection
    {
        $customerProfileId = $this->user->getCustomerProfileId();
        $merchantKeys = $this->getMerchantAuthentication();

        // Create the API request
        $request = new AnetAPI\GetCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($merchantKeys);
        $request->setCustomerProfileId($customerProfileId);

        // Create the controller
        $controller = new AnetControllers\GetCustomerProfileController($request);

        // Execute the request
        /** @var GetCustomerProfileResponse $response */
        $response = $this->execute($controller);

        if (empty($response)) {
            throw new ANetApiException($response);
        }

        $message = $response->getMessages()->getMessage()[0] ?? null;

        // Customer has no saved payment profiles yet
        if ($message?->getCode() === 'E00121') {
            return collect();
        }

        if ($response->getMessages()->getResultCode() == 'Ok') {
            $profile = $response->getProfile();
            $paymentProfiles = $profile->getPaymentProfiles();

            return collect($paymentProfiles);
        }

        return collect();
    }

    public function create(array $opaqueData, array $source)
    {
        $merchantKeys = $this->getMerchantAuthentication();

        $opaqueDataType = new AnetAPI\OpaqueDataType();
        $opaqueDataType->setDataDescriptor($opaqueData['dataDescriptor']);
        $opaqueDataType->setDataValue($opaqueData['dataValue']);

        $paymentType = new AnetAPI\PaymentType();
        $paymentType->setOpaqueData($opaqueDataType);

        // https://github.com/AuthorizeNet/sample-code-php/blob/master/CustomerProfiles/create-customer-payment-profile.php
        // Create the Bill To info for new payment type
        $billto = new AnetAPI\CustomerAddressType();
        $billto->setFirstName($this->user->first_name);
        $billto->setLastName($this->user->last_name);

        $customerPaymentProfileType = new AnetAPI\CustomerPaymentProfileType;
        $customerPaymentProfileType->setPayment($paymentType);
        $customerPaymentProfileType->setBillTo($billto);

        // Assemble the complete transaction request
        $paymentProfileRequest = new AnetAPI\CreateCustomerPaymentProfileRequest();
        $paymentProfileRequest->setMerchantAuthentication($merchantKeys);

        // Add an existing profile id to the request
        $paymentProfileRequest->setCustomerProfileId($this->user->anet()->getCustomerProfileId());
        $paymentProfileRequest->setPaymentProfile($customerPaymentProfileType);

        // Create the controller and get the response
        $controller = new AnetControllers\CreateCustomerPaymentProfileController($paymentProfileRequest);
        $response = $this->execute($controller);

        if (! is_null($response->getCustomerPaymentProfileId())) {
            $this->storeInDatabase($response, $source);
        }

        return $response;
    }

    protected function storeInDatabase(ANetApiResponseType $response, iterable $source): void
    {
        DB::transaction(function () use ($response, $source) {
            DB::table('user_payment_profiles')->updateOrInsert(
                [
                    'user_id' => $this->user->getUserIdForAnet(),
                    'payment_profile_id' => $response->getCustomerPaymentProfileId(),
                ],
                [
                    'last_4' => $source['last_4'],
                    'brand' => $source['brand'],
                    'type' => $source['type'],
                ],
            );
        });
    }
}
