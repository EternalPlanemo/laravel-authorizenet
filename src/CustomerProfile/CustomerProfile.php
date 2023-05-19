<?php

namespace ANet\CustomerProfile;

use ANet\AuthorizeNet;
use ANet\Events\CustomerProfile\Created;
use ANet\Exceptions\ANetApiException;
use ANet\Exceptions\ANetLogicException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class CustomerProfile extends AuthorizeNet
{
    /**
     * it will talk to authorize.net and provide some basic information so, that the user can be charged.
     *
     * @param  User  $user
     * @return AnetAPI\ANetApiResponseType
     *
     * @throws \Exception
     */
    public function create()
    {
        $customerProfileDraft = $this->draftCustomerProfile();
        $request = $this->draftRequest($customerProfileDraft);
        $controller = new AnetController\CreateCustomerProfileController($request);

        $response = $this->execute($controller);
        $response = $this->handleCreateCustomerResponse($response);

        if (method_exists($response, 'getCustomerProfileId')) {
            $this->persistInDatabase($response->getCustomerProfileId());
        } elseif (isset($response->profile_id) && $response->profile_id) {
            if ($this->persistInDatabase($response->profile_id)) {
                Created::dispatch($response->profile_id);
            }
        }

        return $response;
    }

    /**
     * @throws ANetApiException
     */
    public function get(): Collection
    {
        $customerId = $this->user->getCustomerProfileId();
        dd($customerId);

        if (empty($customerId)) {
            return collect();
        }

        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($this->getMerchantAuthentication());
        $request->setCustomerProfileId($customerId);
        $controller = new AnetController\GetCustomerProfileController($request);
        $response = $controller->executeWithApiResponse($this->getANetEnv());

        if (($response != null) && ($response->getMessages()->getResultCode() == 'Ok')) {
            $profileSelected = $response->getProfile();
            $paymentProfiles = collect($profileSelected->getPaymentProfiles());

            return $paymentProfiles;
        }

        throw new ANetApiException($response);
    }

    /**
     * @return AnetAPI\ANetApiResponseType
     *
     * @throws \Exception
     */
    protected function handleCreateCustomerResponse(AnetAPI\CreateCustomerProfileResponse $response)
    {
        if (is_null($response->getCustomerProfileId())) {
            if (config('authorizenet.env') == 'local') {
                throw new ANetApiException($response);
            }

            Log::debug($response->getMessages()->getMessage()[0]->getText());

            // Check For Duplicate Profile and return response
            $error_code = $response->getMessages()->getMessage()[0]->getCode();

            if ($error_code == 'E00039') {
                $re = '/A duplicate record with ID (?<profileId>[0-9]+) already exists/m';
                $str = $response->getMessages()->getMessage()[0]->getText();

                preg_match($re, $str, $matches);

                $profile_id = $matches['profileId'] ?? '';

                $response = (object) ['status' => true, 'profile_id' => $profile_id];

                return $response;
            }

            throw new ANetLogicException('Failed, To create customer profile.');
        }

        return $response;
    }

    /**
     * @param  User  $user
     */
    protected function persistInDatabase(string $customerProfileId): bool
    {
        return \DB::table('user_gateway_profiles')->updateOrInsert(
            [
                'user_id' => $this->user->id,
            ],
            [
                'profile_id' => $customerProfileId,
            ],
        );
    }

    /**
     * @param  User  $user
     */
    protected function draftCustomerProfile(): AnetAPI\CustomerProfileType
    {
        $customerProfile = new AnetAPI\CustomerProfileType();
        $customerProfile->setDescription('Customer Profile');
        $customerProfile->setMerchantCustomerId($this->user->id);
        $customerProfile->setEmail($this->user->email);

        return $customerProfile;
    }

    protected function draftRequest(AnetAPI\CustomerProfileType $customerProfile): AnetAPI\CreateCustomerProfileRequest
    {
        $request = new AnetAPI\CreateCustomerProfileRequest();

        $request->setMerchantAuthentication($this->getMerchantAuthentication());
        $request->setRefId($this->getRefId());
        $request->setProfile($customerProfile);

        return $request;
    }
}
