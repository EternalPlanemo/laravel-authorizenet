<?php

namespace ANet;

use ANet\Contracts\ANetCustomerContract;
use ANet\CustomerProfile\CustomerProfile;
use ANet\Exceptions\ANetApiException;
use ANet\PaymentProfile\PaymentProfile;
use ANet\PaymentProfile\PaymentProfileCharge;
use ANet\PaymentProfile\PaymentProfileRefund;
use ANet\Transactions\Card;
use ANet\Transactions\Transactions;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use net\authorize\api\contract\v1\CreateTransactionResponse;
use net\authorize\api\contract\v1\CustomerAddressType;
use net\authorize\api\contract\v1\CustomerProfileMaskedType;

class ANet
{
    protected Model&ANetCustomerContract $user;

    /** @var ANetMock */
    public $mock;

    /**
     * ANet constructor.
     */
    public function __construct(Model $user)
    {
        $this->user = $user;
        $this->mock = new ANetMock();
    }

    /**
     * It will create customer profile on authorize net
     * and store payment profile id in the system.
     *
     * @return mixed
     *
     * @throws Exception
     */
    public function createCustomerProfile()
    {
        return (new CustomerProfile($this->user))->create();
    }

    /**
     * @throws ANetApiException
     */
    public function deleteCustomerProfile(): void
    {
        app(CustomerProfile::class, ['user' => $this->user])->delete();
    }

    /**
     * @throws ANetApiException
     */
    public function getCustomerProfile(): ?CustomerProfileMaskedType
    {
        $customerId = $this->getCustomerProfileId();
        $action = app(CustomerProfile::class, ['user' => $this->user]);

        if (isset($customerId)) {
            return $action->getById($customerId);
        }

        return $action->get()->first();
    }

    /**
     * @return mixed
     */
    public function getCustomerProfileId()
    {
        $data = DB::table('user_gateway_profiles')
            ->where('user_id', $this->user->getUserIdForAnet())
            ->first();

        return optional($data)->profile_id;
    }

    /**
     * @return mixed
     */
    public function createPaymentProfile($opaqueData, array $source, ?CustomerAddressType $address = null)
    {
        return (new PaymentProfile($this->user))->create($opaqueData, $source, $address);
    }

    public function getPaymentProfiles(): Collection
    {
        return app(PaymentProfile::class, ['user' => $this->user])->get();
    }

    public function charge(int $cents, int $paymentProfileId, ?CustomerAddressType $address = null): CreateTransactionResponse
    {
        return (new PaymentProfileCharge($this->user))->charge($cents, $paymentProfileId, $address);
    }

    /**
     * @return mixed
     */
    public function refund($cents, $refTransId, $paymentProfileId)
    {
        return (new PaymentProfileRefund($this->user))->handle($cents, $refTransId, $paymentProfileId);
    }

    /**
     * @return Collection
     */
    public function getPaymentMethods()
    {
        return DB::table('user_payment_profiles')->where('user_id', $this->user->getUserIdForAnet())->get();
    }

    /**
     * @return Collection
     */
    public function getPaymentCardProfiles()
    {
        $paymentMethods = $this->getPaymentMethods();

        return collect($paymentMethods->where('type', 'card')->all());
    }

    /**
     * @return Collection
     */
    public function getPaymentBankProfiles()
    {
        $paymentMethods = $this->getPaymentMethods();

        return collect($paymentMethods->where('type', 'bank')->all());
    }

    /**
     * It will return transaction class instance to help with transaction related queries
     *
     * @return Transactions
     */
    public function transactions()
    {
        return new Transactions($this->user, new CreateTransactionResponse());
    }

    /**
     * It will return instance of mock class to mock responses
     *
     * @return ANetMock
     */
    public function mock()
    {
        return $this->mock;
    }

    public function card()
    {
        return new Card($this);
    }

    public function subs()
    {
        return $this->subscription();
    }

    public function recurring()
    {
        return $this->subscription();
    }

    public function subscription()
    {
        return new Subscription($this);
    }
}
