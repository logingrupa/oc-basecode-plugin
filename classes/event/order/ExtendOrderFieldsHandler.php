<?php namespace Lovata\BaseCode\Classes\Event\Order;

use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;

use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Controllers\Orders;

/**
 * Class ExtendOrderFieldsHandler
 * @package Lovata\BaseCode\Classes\Event\Order
 * @author  Andrey Kharanenka a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendOrderFieldsHandler extends AbstractBackendFieldHandler
{
    /**
     * Extend backend fields
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->addTabFields([
            'one_c_status_id' => [
                'label' => 'lovata.basecode::lang.field.one_c_status',
                'type' => 'dropdown',
                'span' => 'left',
                'required' => '0',
                'showSearch' => 'false',
                'options' => [
                    OrderModelHandler::ONE_C_EMPTY => trans('lovata.toolbox::lang.field.empty'),
                    OrderModelHandler::ONE_C_STATUS_NEW => trans('lovata.basecode::lang.field.one_c_status_id_' . OrderModelHandler::ONE_C_STATUS_NEW),
                    OrderModelHandler::ONE_C_STATUS_COMPLETE => trans('lovata.basecode::lang.field.one_c_status_id_' . OrderModelHandler::ONE_C_STATUS_COMPLETE),
                ],
                'tab' => 'lovata.toolbox::lang.tab.settings',
                'disabled' => false,
            ],
        ]);
    }

    /**
     * Get model class name
     * @return string
     */
    protected function getModelClass(): string
    {
        return Order::class;
    }

    /**
     * Get controller class name
     * @return string
     */
    protected function getControllerClass(): string
    {
        return Orders::class;
    }
}
