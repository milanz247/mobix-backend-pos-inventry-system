<?php

namespace App;

enum WebFulfillmentMode: string
{
    case HomeDelivery = 'home_delivery';
    case ShopPickup = 'shop_pickup';
}
