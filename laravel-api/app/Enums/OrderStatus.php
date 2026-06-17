<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case PACKING = 'PACKING';
    case SHIPPING = 'SHIPPING';
    case DELIVERED = 'DELIVERED';
    case CANCELLED = 'CANCELLED';
}
