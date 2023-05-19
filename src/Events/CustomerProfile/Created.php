<?php

namespace ANet\Events\CustomerProfile;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class Created
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        protected int $id,
    ) {
        //
    }
}
