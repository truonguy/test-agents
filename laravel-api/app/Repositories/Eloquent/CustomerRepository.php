<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function findByEmail(string $email): ?Customer
    {
        return Customer::query()->where('email', $email)->first();
    }
}
