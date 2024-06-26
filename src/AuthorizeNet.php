<?php

namespace ANet;

use ANet\Contracts\ANetCustomerContract;
use Exception;
use Illuminate\Database\Eloquent\Model;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;

abstract class AuthorizeNet
{
    protected ?AnetAPI\MerchantAuthenticationType $merchantAuthentication;

    /** @var mixed */
    protected $request;

    /** @var string */
    protected $refId;

    protected ?AnetAPI\TransactionRequestType $transactionType;

    protected $controller;

    public ?ANetMock $mock;

    public Model&ANetCustomerContract $user;

    public function __construct(Model&ANetCustomerContract $user)
    {
        $this->mock = new ANetMock();
        $this->user = $user;
    }

    /**
     * It will setup and get merchant authentication keys
     */
    public function getMerchantAuthentication(): AnetAPI\MerchantAuthenticationType
    {
        $this->merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $this->merchantAuthentication->setName($this->_getLoginID());
        $this->merchantAuthentication->setTransactionKey($this->_getTransactionKey());

        return $this->merchantAuthentication;
    }

    private function _getLoginID(): ?string
    {
        $loginId = config('authorizenet.login_id');
        if (! $loginId) {
            throw new Exception('Please provide Login ID in .env file. Which you can get from authorize.net');
        }

        return $loginId;
    }

    private function _getTransactionKey(): ?string
    {
        $transactionKey = config('authorizenet.transaction_key');
        if (! $transactionKey) {
            throw new Exception('Please provide transaction key in .env file. Which you can get from authorize.net');
        }

        return $transactionKey;
    }

    public function setRequest(mixed $requestObject): self
    {
        $this->request = $requestObject;

        return $this;
    }

    public function getRequest(): mixed
    {
        return $this->request;
    }

    public function setRefId(string $refId): self
    {
        $this->refId = $refId;

        return $this;
    }

    /**
     * It will return refId if not provided then time
     */
    public function getRefId(): string
    {
        return $this->refId || (string) time();
    }

    public function setTransactionType(string $type, int $amount): self
    {
        $this->transactionType = new AnetAPI\TransactionRequestType;
        $this->transactionType->setTransactionType($type);
        $this->transactionType->setAmount($this->convertCentsToDollar($amount));

        return $this;
    }

    public function getTransactionType(): AnetAPI\TransactionRequestType
    {
        return $this->transactionType;
    }

    public function convertCentsToDollar(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    public function convertDollarsToCents(int|float|string $dollars): string
    {
        return bcmul((string) $dollars, 100, 2);
    }

    public function setController(mixed $controller): self
    {
        $this->controller = $controller;

        return $this;
    }

    public function getController(): mixed
    {
        return $this->controller;
    }

    public function execute(mixed $controller): mixed
    {
        $env = config('authorizenet.env');
        if ($env == 'production') {
            return $controller->executeWithApiResponse(ANetEnvironment::PRODUCTION);
        }

        return $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);
    }

    public function testingResponse(mixed $controller): mixed
    {
        return $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);
    }

    public function getANetEnv(): string
    {
        $env = config('authorizenet.env');
        if ($env == 'production') {
            return ANetEnvironment::PRODUCTION;
        }

        return ANetEnvironment::SANDBOX;
    }
}
