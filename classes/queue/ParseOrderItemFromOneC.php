<?php namespace Lovata\BaseCode\Classes\Queue;

use DB;
use Log;

use Kharanenka\Helper\Result;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Lovata\Toolbox\Traits\Helpers\TraitValidationHelper;

use Lovata\BaseCode\Classes\Helper\OneC\Import1CHelper;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OrderPromoMechanismProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\OrdersShopaholic\Models\Status;
use Lovata\Shopaholic\Models\Offer;

use October\Rain\Database\ModelException;

/**
 * Class ParseOrderItemFromOneC
 *
 * @package Lovata\BaseCode\Classes\Queue
 * @author Sergey Zakharevich, <s.v.zakharevich@gmail.com>, LOVATA Group
 */
class ParseOrderItemFromOneC
{
    use TraitValidationHelper;

    /** @var array */
    protected $arData = [];

    /** @var Order */
    protected $obOrder;

    /** @var array */
    protected $arShippingTypeExternalIds = [];

    /** @var float */
    protected $fShippingPrice;

    /** @var \Illuminate\Database\Eloquent\Model|ShippingType|object|null */
    private $obShippingType;

    /**
     * Fire.
     * @param \Illuminate\Queue\Jobs\Job $obJob
     * @param array $arData
     * @throws \Exception
     */
    public function fire($obJob, $arData)
    {
        $this->process(is_array($arData) ? $arData : []);
        $obJob->delete();
    }

    /**
     * Sync one order from its 1C payload, see ImportOrders::parseOrderDocument().
     * @param array $arData
     * @throws \Exception
     */
    public function process(array $arData)
    {
        Import1CHelper::instance()->setTrueStatus();
        $this->arData = $arData;

        if (empty($this->arData)) {
            return;
        }

        $this->getShippingTypeExternalIdList();
        $this->getOrderShippingData();
        $this->syncOrder();
    }

    /**
     * Get shipping type external id list
     */
    private function getShippingTypeExternalIdList()
    {
        $this->arShippingTypeExternalIds = ShippingType::active()->lists('external_id');
    }

    /**
     * Get shipping data.
     */
    protected function getOrderShippingData()
    {
        $fPrice = 0;
        $sExternalId = null;

        $arOrderPositionList = array_get($this->arData, 'order_position_list');
        foreach ($arOrderPositionList as $arOrderPosition) {
            $sExternalId = $this->formatString(array_get($arOrderPosition, 'external_id'));

            if (!in_array($sExternalId, $this->arShippingTypeExternalIds)) {
                continue;
            }

            $fPrice = $this->formatString(array_get($arOrderPosition, 'price'));
            $fPrice = PriceHelper::toFloat($fPrice);

            break;
        }

        $this->fShippingPrice = $fPrice;
        $this->obShippingType = ShippingType::where('external_id', $sExternalId)->first();
    }

    /**
     * Format string
     * @param string|null $sString
     * @return string
     */
    protected function formatString(?string $sString = null): string
    {
        if (empty($sString) || is_array($sString)) {
            return '';
        }

        return trim($sString);
    }

    /**
     * Sync order.
     * @throws \Exception
     */
    protected function syncOrder()
    {
        $sOrderNumber = $this->formatString(array_get($this->arData, 'order_number', ''));

        if (empty($sOrderNumber)) {
            return;
        }

        $this->obOrder = Order::getByNumber($sOrderNumber)->first();

        if (empty($this->obOrder)) {
            return;
        }

        $this->updateOrderData();
        $this->updateOrderPositionData();
    }

    /**
     * Update order data.
     * @throws \Exception
     */
    protected function updateOrderData()
    {
        $sCodeStatus = $this->formatString(array_get($this->arData, 'code_status'));

        if (empty($sCodeStatus)) {
            return;
        }

        $obStatus = Status::getByCode($sCodeStatus)->first();

        if (empty($obStatus)) {
            return;
        }

        try {
            $this->obOrder->status_id = $obStatus->id;
            // A 1C order without a delivery line (store pickup) keeps the shipping type chosen at checkout.
            if (!empty($this->obShippingType)) {
                $this->obOrder->shipping_price = $this->fShippingPrice;
                $this->obOrder->shipping_type_id = $this->obShippingType->id;
            }
            $this->obOrder->save();
        } catch (\Exception $obException) {
            throw new $obException('Cannot update order #' . $this->obOrder->id);
        }
    }

    /**
     * Update order data.
     * @return void|null
     * @throws \Exception
     */
    protected function updateOrderPositionData()
    {
        $arOrderPositionList = array_get($this->arData, 'order_position_list');

        DB::beginTransaction();

        if (empty($arOrderPositionList) || !is_array($arOrderPositionList)) {
            try {
                $this->obOrder->order_position()->delete();
                $this->obOrder->save();
            } catch (\Exception $obException) {
                Log::error($obException);
                Result::setFalse()->setMessage($obException->getMessage());
            }

            return;
        }

        $arOrderPositionExternalIdListFromOneC = [];
        $arOrderPositionDataToAdd = [];

        foreach ($arOrderPositionList as $arOrderPosition) {
            $sExternalId = $this->formatString(array_get($arOrderPosition, 'external_id'));

            if (empty($sExternalId) || in_array($sExternalId, $this->arShippingTypeExternalIds)) {
                continue;
            }

            $fListPrice = PriceHelper::toFloat($this->formatString(array_get($arOrderPosition, 'price')));
            $fTotal = PriceHelper::toFloat($this->formatString(array_get($arOrderPosition, 'total')));
            $iQuantity = (integer)$this->formatString(array_get($arOrderPosition, 'quantity'));

            if ($iQuantity < 1) {
                Result::setFalse()->setMessage('1C line ' . $sExternalId . ' has quantity ' . $iQuantity);

                break;
            }

            // 1C Сумма is what the customer was charged for the line, ЦенаЗаЕдиницу the list price.
            $fPrice = round($fTotal / $iQuantity, 2);

            $arOrderPositionExternalIdListFromOneC[] = $sExternalId;

            $obOrderPosition = OrderPosition::where('order_id', $this->obOrder->id)
                ->where('one_c_external_id', $sExternalId)
                ->first();

            if (empty($obOrderPosition)) {
                $iOfferExternalId = $this->getOfferExternalId($sExternalId);
                $obOffer = Offer::where('external_id', $iOfferExternalId)->first();

                if (empty($obOffer)) {
                    Result::setFalse()->setMessage('Not found offer with external_id ' . $iOfferExternalId);

                    break;
                }

                $arOrderPositionDataToAdd[$sExternalId] = [
                    'order_id' => $this->obOrder->id,
                    'one_c_external_id' => $sExternalId,
                    'item_id' => $obOffer->id,
                    'item_type' => Offer::class,
                    'price' => $fPrice,
                    'old_price' => $fListPrice,
                    'quantity' => $iQuantity
                ];
            } else {
                $obOrderPosition->price = $fPrice;
                $obOrderPosition->old_price = $fListPrice;
                $obOrderPosition->quantity = $iQuantity;

                if ($obOrderPosition->isClean()) {
                    continue;
                }

                try {
                    $obOrderPosition->save();
                } catch (ModelException $obModelException) {
                    Log::error($obModelException);
                    Result::setFalse()->setMessage($obModelException->getMessage());
                }
            }
        }

        $arOrderPositionExternalIdList = $this->obOrder->order_position
            ->keyBy('one_c_external_id')
            ->keys()
            ->toArray();

        $arOrderPositionExternalIdListToDelete = array_diff($arOrderPositionExternalIdList, $arOrderPositionExternalIdListFromOneC);

        $this->deleteOrderPositionsByExternalIdList($arOrderPositionExternalIdListToDelete);
        $this->addOrderPositionsByExternalIdList($arOrderPositionDataToAdd);

        if (!Result::status()) {
            DB::rollBack();

            return null;
        }

        // The 1C line totals already contain every discount the manager kept, nothing may be applied on top.
        $this->obOrder->order_promo_mechanism()->delete();
        OrderPromoMechanismProcessor::update($this->obOrder);

        $this->obOrder->save();

        DB::commit();

        Result::setTrue();
    }

    /**
     * Get offer external id
     * @param string $sExternalId
     * @return mixed|string|null
     */
    private function getOfferExternalId(string $sExternalId)
    {
        $arExternalIdParts = explode('#', $sExternalId);

        if (count($arExternalIdParts) === 1) {
            return $sExternalId;
        }

        if (count($arExternalIdParts) === 2) {
            return array_get($arExternalIdParts, 1);
        }

        return null;
    }

    /**
     * Delete order positions by external id list
     * @param array $arOrderPositionExternalIdList
     * @throws \Exception
     */
    private function deleteOrderPositionsByExternalIdList(array $arOrderPositionExternalIdList)
    {
        if (empty($arOrderPositionExternalIdList)) {
            return;
        }

        try {
            OrderPosition::where('order_id', $this->obOrder->id)
                ->whereIn('one_c_external_id', $arOrderPositionExternalIdList)
                ->delete();
        } catch (\Exception $obException) {
            Result::setFalse()->setMessage($obException->getMessage());
            Log::error($obException);
        }
    }

    /**
     * Add order positions by external id list
     * @param array $arOrderPositionDataToAdd
     */
    private function addOrderPositionsByExternalIdList(array $arOrderPositionDataToAdd)
    {
        if (!Result::status()) {
            return;
        }

        if (empty($this->obOrder) || empty($arOrderPositionDataToAdd)) {
            return;
        }

        foreach ($arOrderPositionDataToAdd as $arOrderPosition) {
            if ($this->createOrderPosition($arOrderPosition)) {
                continue;
            }

            $arErrorData = Result::data();
            $arErrorData['offer_id'] = array_get($arOrderPosition, 'item_id');
            $arErrorData['one_c_external_id'] = array_get($arOrderPosition, 'one_c_external_id');
            Result::setFalse($arErrorData);

            break;
        }
    }

    /**
     * Create order position
     * @param array $arOrderPositionData
     * @return bool
     */
    protected function createOrderPosition(array $arOrderPositionData): bool
    {
        try {
            $obOrderPosition = OrderPosition::create($arOrderPositionData);
            $this->obOrder->order_position()->add($obOrderPosition);
        } catch (\October\Rain\Database\ModelException $obException) {
            $this->processValidationError($obException);

            return false;
        }

        return true;
    }

    /**
     * Get xml order object.
     * @param string $sOrderId
     * @param string $sFilePath
     * @return null|XMLObjectClass
     */
    protected function getXmlOrderObject($sOrderId, $sFilePath)
    {
        if (empty($sFilePath) || empty($sOrderId) || !file_exists($sFilePath)) {
            return null;
        }

        $obXmlObject = ImportOrders::getXmlObject($sFilePath);

        if (empty($obXmlObject)) {
            return null;
        }

        $arElementList = $obXmlObject->xpath(ImportOrders::XML_PATH_ORDER_LIST);

        if (empty($arElementList)) {
            return null;
        }

        foreach ($arElementList as $obXmlElement) {
            if ($sOrderId == $obXmlElement->getValueByPath('Ид')) {
                return $obXmlElement;
            }
        }

        return null;
    }
}
