<?php namespace Lovata\BaseCode\Classes\Event\OrderPosition;

use Lovata\OrdersShopaholic\Models\OrderPosition;

/**
 * Class OrderPositionModelHandler
 * @package Lovata\BaseCode\Classes\Event\OrderPosition
 * @author Sergey Zakharevich, s.zakharevich@lovata.com
 */
class OrderPositionModelHandler
{
    protected $iPriority = 1000;

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe()
    {
        OrderPosition::extend(function ($obElement) {
            /** @var OrderPosition $obElement */
            $obElement->fillable[] = 'one_c_external_id';
            $obElement->bindEvent('model.beforeCreate', function () use ($obElement) {
                $this->beforeCreate($obElement);
            }, $this->iPriority);
        });
    }

    /**
     * Save discount values in orpder position property array
     * @param OrderPosition $obOrderPosition
     */
    protected function beforeCreate($obOrderPosition)
    {
        $obOffer = $obOrderPosition->offer;

        if (empty($obOffer)) {
            return;
        }

        $sOneCExternalId = '';
        if (!empty($obOffer->product)) {

            $sOneCExternalId = $obOffer->product->external_id;
        }
        // If Product has only one offer -> fix Offer ID
        if ($obOffer->product->offer->count() === 1) {
            $sOneCExternalId = $sOneCExternalId;
        } else {
            // Else proceed with default
            $sOneCExternalId = $sOneCExternalId . '#' . $obOffer->external_id;
        }
        $obOrderPosition->one_c_external_id = $sOneCExternalId;
    }
}
