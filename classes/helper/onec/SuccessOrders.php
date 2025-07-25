<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use Lovata\BaseCode\Classes\Event\Order\OrderModelHandler;
use Lovata\BaseCode\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use October\Rain\Database\ModelException;

/**
 * Class SuccessOrders
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class SuccessOrders extends AbstractHelper
{
    /**
     * Processing.
     * @return string
     */
    public function processing()
    {
        if (empty($this->sType)
            || empty($this->sMode)
            || $this->sType != self::TYPE_SALE
            || $this->sMode != self::MODE_SUCCESS
        ) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        $this->orderProcessing();

        // Form a response.
        return $this->response([self::STATUS_SUCCESS]);
    }

    /**
     * Order processing.
     */
    protected function orderProcessing()
    {
        $arOrderIdList = Settings::getValue('order_id_lis_one_s');

        if (empty($arOrderIdList) || !is_array($arOrderIdList)) {
            return;
        }

        $obOrderList = Order::whereIn('id', $arOrderIdList)
            ->where('one_c_status_id', OrderModelHandler::ONE_C_STATUS_NEW)
            ->get();

        foreach ($obOrderList as $obOrder) {
            try {
                $obOrder->one_c_status_id = OrderModelHandler::ONE_C_STATUS_COMPLETE;
                $obOrder->save();
            } catch (ModelException $obException) {
                continue;
            }
        }

        Settings::set('order_id_lis_one_s', []);
    }
}
