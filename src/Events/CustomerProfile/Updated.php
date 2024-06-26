<?php

namespace ANet\Events\CustomerProfile;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class Updated
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $id,
    ) {
        //
    }
}
