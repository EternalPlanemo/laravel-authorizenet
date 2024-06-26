<?php

namespace ANet\CustomerProfile;

use ANet\AuthorizeNet;
use ANet\Events\CustomerProfile\Created;
use ANet\Events\CustomerProfile\Updated;
use ANet\Exceptions\ANetApiException;
use ANet\Exceptions\ANetLogicException;
use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\contract\v1\CreateCustomerProfileResponse;
use net\authorize\api\contract\v1\DeleteCustomerProfileResponse;
use net\authorize\api\contract\v1\GetCustomerProfileIdsResponse;
use net\authorize\api\contract\v1\GetCustomerProfileResponse;
use net\authorize\api\controller as AnetController;

class CustomerProfile extends AuthorizeNet
{
    /**
     * It will talk to authorize.net and provide some basic information so, that the user can be charged.
     *
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

        /** @var CreateCustomerProfileResponse|object{status: string, profile_id: string} $response */
        $response = $this->handleCreateCustomerResponse($response);
        $customerProfileId = $response instanceof CreateCustomerProfileResponse
            ? $response->getCustomerProfileId()
            : $response->profile_id;

        if (! empty($customerProfileId)) {
            $this->persistInDatabase($customerProfileId);
        }

        return $response;
    }

    public function all(): Collection
    {
        $merchantKeys = $this->getMerchantAuthentication();
        $request = new AnetAPI\GetCustomerProfileIdsRequest();
        $request->setMerchantAuthentication($merchantKeys);

        $controller = new AnetController\GetCustomerProfileIdsController($request);

        /** @var ?GetCustomerProfileIdsResponse $response */
        $response = $this->execute($controller);

        if ($response != null && $response->getMessages()->getResultCode() == 'Ok') {
            return collect($response->getIds());
        } else {
            throw new ANetApiException($response);
        }
    }

    /**
     * @throws ANetApiException
     */
    public function get(): Collection
    {
        $customerId = $this->user->getCustomerProfileId();

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

    public function getByEmail(string $email): Collection
    {
        $merchantKeys = $this->getMerchantAuthentication();
        // Create the merchant authentication object

        // Create the API request to get all customer profiles
        $request = new AnetAPI\GetCustomerProfileIdsRequest();
        $request->setMerchantAuthentication($merchantKeys);

        // Create the controller
        $controller = new AnetController\GetCustomerProfileIdsController($request);

        // Execute the request
        /** @var ?GetCustomerProfileIdsResponse $response */
        $response = $this->execute($controller);

        if ($response != null && $response->getMessages()->getResultCode() == 'Ok') {
            $profileIds = $response->getIds();

            // Loop through all profile IDs to find the email
            foreach ($profileIds as $profileId) {
                // Get the details of each customer profile
                $profileRequest = new AnetAPI\GetCustomerProfileRequest();
                $profileRequest->setMerchantAuthentication($merchantKeys);
                $profileRequest->setCustomerProfileId($profileId);

                $profileController = new AnetController\GetCustomerProfileController($profileRequest);
                /** @var ?GetCustomerProfileResponse $response */
                $profileResponse = $this->execute($profileController);

                if ($profileResponse != null && $profileResponse->getMessages()->getResultCode() == 'Ok') {
                    $profile = $profileResponse->getProfile();
                    if ($profile->getEmail() == $email) {
                        // Return profile if email matches
                        return Collection::wrap($profile);
                    }
                } else {
                    throw new ANetApiException($profileResponse);
                }
            }

            // No profile found with the given email
            return collect();
        } else {
            throw new ANetApiException($response);
        }
    }

    /**
     * @throws ANetApiException
     */
    public function delete(): void
    {
        $customerId = $this->user->getCustomerProfileId();

        if (empty($customerId)) {
            return;
        }

        // Create the merchant authentication object
        $merchantKeys = $this->getMerchantAuthentication();

        // Create the API request
        $request = new AnetAPI\DeleteCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantKeys);
        $request->setCustomerProfileId($customerId);

        // Create the controller
        $controller = new AnetController\DeleteCustomerProfileController($request);

        // Execute the request
        /** @var ?DeleteCustomerProfileResponse $response */
        $response = $this->execute($controller);

        if (empty($response) || $response->getMessages()->getResultCode() !== 'Ok') {
            throw new ANetApiException($response);
        }

        DB::table('user_gateway_profiles')
            ->where('profile_id', $customerId)
            ->delete();
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

    protected function persistInDatabase(string $customerProfileId): bool
    {
        return DB::transaction(function () use ($customerProfileId) {
            $profile = DB::table('user_gateway_profiles')
                ->where('profile_id', $customerProfileId)
                ->lockForUpdate()
                ->first();

            if (isset($profile)) {
                DB::table('user_gateway_profiles')
                    ->where('id', $profile->id)
                    ->lockForUpdate()
                    ->update([
                        'updated_at' => now(),
                        'user_id' => $this->user->getUserIdForAnet(),
                    ]);

                Updated::dispatch($customerProfileId);
            } else {
                DB::table('user_gateway_profiles')->insert([[
                    'created_at' => now(),
                    'updated_at' => now(),
                    'user_id' => $this->user->getUserIdForAnet(),
                    'profile_id' => $customerProfileId,
                ]]);

                Created::dispatch($customerProfileId);
            }

            return true;
        });
    }

    protected function draftCustomerProfile(): AnetAPI\CustomerProfileType
    {
        $customerProfile = new AnetAPI\CustomerProfileType();
        $customerProfile->setDescription('Customer Profile');
        $customerProfile->setMerchantCustomerId($this->user->getUserIdForAnet());
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
