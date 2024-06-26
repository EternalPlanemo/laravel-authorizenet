<?php

namespace ANet\Contracts;

interface ANetCustomerContract
{
    public function getCustomerProfileId(): string|int|null;

    public function getUserIdForAnet(): int;
}
