<?php namespace Lovata\BaseCode\Classes\Queue;

use DB;
use Log;

use Kharanenka\Helper\Result;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Lovata\Toolbox\Traits\Helpers\TraitValidationHelper;

use Lovata\BaseCode\Classes\Helper\OneC\Import1CHelper;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
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
        Import1CHelper::instance()->setTrueStatus();
        $this->arData = $arData;

        if (empty($this->arData) || !is_array($this->arData)) {
            $obJob->delete();

            return;
        }

        $this->getShippingTypeExternalIdList();
        $this->getOrderShippingData();
        $this->syncOrder();
        $obJob->delete();
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
    protected function formatString(string $sString = null): string
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

            $fPrice = $this->formatString(array_get($arOrderPosition, 'price'));
            $fPrice = PriceHelper::toFloat($fPrice);
            $iQuantity = (integer)$this->formatString(array_get($arOrderPosition, 'quantity'));
            $arDiscounts = array_get($arOrderPosition, 'discount_data', []);

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
                    'old_price' => $this->getOldPriceFromDiscounts($arDiscounts, $fPrice),
                    'quantity' => $iQuantity
                ];
            } else {
                $obOrderPosition->price = $fPrice;
                $obOrderPosition->old_price = $this->getOldPriceFromDiscounts($arDiscounts, $fPrice);
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
     * Get order position old price from discount info
     * @param array $arDiscountList
     * @param float $fPositionPrice
     * @return float
     */
    private function getOldPriceFromDiscounts(array $arDiscountList, float $fPositionPrice): float
    {
        $fOldPriceValue = 0;
        $fDiscountPercent = 0;

        if (empty($arDiscountList)) {
            return $fOldPriceValue;
        }

        foreach ($arDiscountList as $arDiscount) {
            $fItemDiscountPercent = (float)array_get($arDiscount, 'percent');
            $bIsTakenInSum = (bool)array_get($arDiscount, 'is_taken_in_sum');

            if (empty($fItemDiscountPercent) || !$bIsTakenInSum) {
                continue;
            }

            $fDiscountPercent = $fDiscountPercent + $fItemDiscountPercent;
        }

        if (empty($fDiscountPercent)) {
            return $fOldPriceValue;
        }

        return $fPositionPrice * (1 + $fDiscountPercent / 100);
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
