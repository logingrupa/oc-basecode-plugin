<?php namespace Lovata\BaseCode\Classes\Event\Order;

use Lovata\BaseCode\Classes\Helper\OneC\Import1CHelper;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class OrderModelHandler
 * @package Lovata\BaseCode\Classes\Event\Order
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com
 */
class OrderModelHandler
{
    const ONE_C_STATUS_NEW = 1;
    const ONE_C_STATUS_COMPLETE = 2;
    const ONE_C_EMPTY = 3;

    /**
     * Register the listeners for the subscriber.
     * @param \Illuminate\Events\Dispatcher $obEvents
     */
    public function subscribe($obEvent)
    {
        Order::extend(function ($obOrder) {
            $obOrder->bindEvent('model.beforeSave', function () use ($obOrder) {
                /**
                 * @var Order $obOrder
                 */
                if (!Import1CHelper::instance()->status() && $obOrder->status_id != $obOrder->getOriginal('status_id')) {
                    $obOrder->one_c_status_id = self::ONE_C_STATUS_NEW;
                }
            });
        });
    }
}
