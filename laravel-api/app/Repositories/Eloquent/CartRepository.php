<?php

namespace App\Repositories\Eloquent;

use App\Models\Cart;
use App\Models\Customer;
use App\Repositories\Contracts\CartRepositoryInterface;

class CartRepository implements CartRepositoryInterface
{
    public function activeCartFor(Customer $customer): Cart
    {
        return Cart::firstOrCreate(['customer_id' => $customer->id]);
    }
}
