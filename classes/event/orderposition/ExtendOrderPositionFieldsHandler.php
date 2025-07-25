<?php namespace Lovata\BaseCode\Classes\Event\OrderPosition;

use Lovata\OrdersShopaholic\Controllers\OrderPositions;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;

/**
 * Class ExtendOrderPositionFieldsHandler
 * @package Lovata\BaseCode\Classes\Event\OrderPosition
 * @author  Sergey Zakharevich, <s.v.zakharevich@gmail.com>, LOVATA Group
 */
class ExtendOrderPositionFieldsHandler extends AbstractBackendFieldHandler
{
    /**
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->addTabFields([
            'one_c_external_id' => [
                'label' => 'lovata.basecode::lang.field.one_c_external_id',
                'type' => 'text',
                'span' => 'left',
                'disabled' => false,
                'tab' => 'lovata.toolbox::lang.tab.settings',
            ],
        ]);
    }

    /**
     * Get model class name
     * @return string
     */
    protected function getModelClass(): string
    {
        return OrderPosition::class;
    }

    /**
     * Get controller class name
     * @return string
     */
    protected function getControllerClass(): string
    {
        return OrderPositions::class;
    }
}
