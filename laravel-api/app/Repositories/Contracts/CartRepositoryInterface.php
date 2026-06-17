<?php

namespace App\Repositories\Contracts;

use App\Models\Cart;
use App\Models\Customer;

interface CartRepositoryInterface
{
    /**
     * Lấy (hoặc tạo) cart active của customer — 1 customer = 1 cart.
     */
    public function activeCartFor(Customer $customer): Cart;
}
