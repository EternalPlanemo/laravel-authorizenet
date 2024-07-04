<?php

namespace ANet\PaymentProfile;

use ANet\AuthorizeNet;
use ANet\CustomerProfile\CustomerProfile;
use ANet\Exceptions\ANetApiException;
use DB;
use Illuminate\Support\Collection;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\contract\v1\CreateCustomerPaymentProfileResponse;
use net\authorize\api\contract\v1\CustomerPaymentProfileMaskedType;
use net\authorize\api\controller as AnetControllers;

class PaymentProfile extends AuthorizeNet
{
    /**
     * @returns Collection<int, CustomerPaymentProfileMaskedType>
     *
     * @throws ANetApiException
     */
    public function get(): Collection
    {
        $customerProfileId = $this->user->getCustomerProfileId();
        $customerProfile = app(CustomerProfile::class, ['user' => $this->user])->getById($customerProfileId);

        return collect($customerProfile->getPaymentProfiles());
    }

    public function create(array $opaqueData, array $source, ?AnetAPI\CustomerAddressType $address = null)
    {
        $merchantKeys = $this->getMerchantAuthentication();

        $opaqueDataType = new AnetAPI\OpaqueDataType();
        $opaqueDataType->setDataDescriptor($opaqueData['dataDescriptor']);
        $opaqueDataType->setDataValue($opaqueData['dataValue']);

        $paymentType = new AnetAPI\PaymentType();
        $paymentType->setOpaqueData($opaqueDataType);

        // https://github.com/AuthorizeNet/sample-code-php/blob/master/CustomerProfiles/create-customer-payment-profile.php
        // Create the Bill To info for new payment type
        $billto = $address ?? (new AnetAPI\CustomerAddressType())
            ->setFirstName($this->user->first_name)
            ->setLastName($this->user->last_name);

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
        /** @var ?CreateCustomerPaymentProfileResponse $response */
        $controller = new AnetControllers\CreateCustomerPaymentProfileController($paymentProfileRequest);
        $response = $this->execute($controller);

        if (! is_null($response->getCustomerPaymentProfileId())) {
            $this->storeInDatabase($response, $source);
        }

        return $response;
    }

    protected function storeInDatabase(CreateCustomerPaymentProfileResponse $response, iterable $source): void
    {
        DB::table('user_payment_profiles')->upsert(
            values: [[
                'last_4' => $source['last_4'],
                'brand' => $source['brand'],
                'type' => $source['type'],
                'user_id' => $this->user->getUserIdForAnet(),
                'payment_profile_id' => $response->getCustomerPaymentProfileId(),
            ]],
            uniqueBy: ['payment_profile_id'],
            update: ['last_4', 'brand', 'type', 'user_id', 'payment_profile_id'],
        );
    }
}
