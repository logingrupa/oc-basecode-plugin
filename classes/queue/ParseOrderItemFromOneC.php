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

    /** @var \October\Rain\Database\Collection|ShippingType[] keyed by id */
    protected $obShippingTypeList;

    /** @var ShippingType[] 1C GUID => shipping type */
    protected $arShippingTypeMap = [];

    /** @var float|null */
    protected $fShippingPrice;

    /** @var int|null */
    protected $iShippingTypeID;

    /** @var string[] 1C service line GUIDs no shipping type maps */
    protected $arUnmappedServiceIdList = [];

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
        // Result is process-wide, a queue worker would otherwise carry an earlier job's failure into this sync.
        Result::setTrue()->setMessage('');
        $this->arData = $arData;

        if (empty($this->arData)) {
            return;
        }

        // A 1C document without lines (cleared or cancelled in 1C) is not mirrored, the shop order stays as it is.
        if (empty($this->arData['order_position_list'])) {
            Log::warning('1C order ' . array_get($this->arData, 'order_number') . ' has no lines, sync skipped');

            return;
        }

        // OrderModelHandler does not flag the order for re-export to 1C while the sync writes it.
        Import1CHelper::instance()->setTrueStatus();

        try {
            $this->syncOrder();
        } finally {
            Import1CHelper::instance()->setFalseStatus();
        }
    }

    /**
     * Inactive shipping types count too, a hidden type may exist only to map a 1C service.
     * A GUID shared by several types maps to an active one first, then to the lowest id.
     */
    private function getShippingTypeMap()
    {
        $this->arShippingTypeMap = [];

        // orderBy() replaces the Sortable sort_order scope.
        $this->obShippingTypeList = ShippingType::orderBy('active', 'desc')->orderBy('id')->get(['id', 'active', 'external_id'])->keyBy('id');

        foreach ($this->obShippingTypeList as $obShippingType) {
            $sExternalId = $this->formatString($obShippingType->external_id);

            if ($sExternalId === '' || isset($this->arShippingTypeMap[$sExternalId])) {
                continue;
            }

            $this->arShippingTypeMap[$sExternalId] = $obShippingType;
        }
    }

    /**
     * Shipping price = every 1C service line (shipping type lines and extra charges like "zPAK - Shipping cost").
     * The shipping type only changes when a line maps to one.
     */
    protected function getOrderShippingData()
    {
        $fPrice = null;
        $arLineShippingTypeList = [];
        $this->arUnmappedServiceIdList = [];

        foreach (array_get($this->arData, 'order_position_list') as $arOrderPosition) {
            $sLineExternalId = $this->formatString(array_get($arOrderPosition, 'external_id'));
            $obShippingType = $this->arShippingTypeMap[$sLineExternalId] ?? null;

            if (empty($obShippingType) && empty($arOrderPosition['is_service'])) {
                continue;
            }

            if (empty($obShippingType)) {
                $this->arUnmappedServiceIdList[] = $sLineExternalId;
            } elseif (!isset($arLineShippingTypeList[$sLineExternalId])) {
                $arLineShippingTypeList[$sLineExternalId] = $obShippingType;
            }

            $fPrice += PriceHelper::toFloat($this->formatString(array_get($arOrderPosition, 'total')));
        }

        $this->fShippingPrice = $fPrice;
        $this->iShippingTypeID = $this->getShippingTypeID($arLineShippingTypeList);
    }

    /**
     * The order keeps its shipping type while a 1C line carries that type's GUID, which other types may share.
     * Otherwise the first line mapped to an active type wins over a hidden mapping-only type.
     * @param ShippingType[] $arLineShippingTypeList 1C GUID => shipping type, in document order
     * @return int|null
     */
    private function getShippingTypeID(array $arLineShippingTypeList): ?int
    {
        $obOrderShippingType = $this->obShippingTypeList->get($this->obOrder->shipping_type_id);

        if (!empty($obOrderShippingType) && isset($arLineShippingTypeList[$this->formatString($obOrderShippingType->external_id)])) {
            return (int) $obOrderShippingType->id;
        }

        $obLineShippingTypeList = collect($arLineShippingTypeList);
        $obShippingType = $obLineShippingTypeList->first(fn (ShippingType $obLineShippingType) => (bool) $obLineShippingType->active)
            ?? $obLineShippingTypeList->first();

        return empty($obShippingType) ? null : (int) $obShippingType->id;
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
     * Lines, status and shipping in one transaction, every exit commits or rolls it back.
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

        $this->getShippingTypeMap();
        $this->getOrderShippingData();

        DB::beginTransaction();

        try {
            $bSynced = $this->mirrorOrderPositionList($this->arData['order_position_list']);

            if ($bSynced) {
                $this->updateOrderData();
            }
        } catch (\Throwable $obException) {
            DB::rollBack();

            throw $obException;
        }

        if (!$bSynced) {
            DB::rollBack();
            Log::warning('1C order ' . $sOrderNumber . ' sync failed: ' . Result::message());

            return;
        }

        DB::commit();

        foreach ($this->arUnmappedServiceIdList as $sExternalId) {
            // The shop->1C export sends the shipping type GUID, this charge cannot round-trip.
            Log::warning('1C order ' . $sOrderNumber . ' service line ' . $sExternalId . ' maps to no shipping type');
        }

        Result::setTrue();
    }

    /**
     * Status, shipping and totals in one order save, after the lines are mirrored.
     * Order listeners (Meta Purchase on a paid status) see the final 1C numbers.
     * @throws \Exception
     */
    protected function updateOrderData()
    {
        $sCodeStatus = $this->formatString(array_get($this->arData, 'code_status'));
        // Status::getByCode('') adds no condition and would return the first status.
        $obStatus = empty($sCodeStatus) ? null : Status::getByCode($sCodeStatus)->first();

        if (!empty($obStatus)) {
            $this->obOrder->status_id = $obStatus->id;
        }

        // A 1C order without a service line (store pickup) keeps the shipping chosen at checkout.
        if ($this->fShippingPrice !== null) {
            $this->obOrder->shipping_price = $this->fShippingPrice;
        }

        if (!empty($this->iShippingTypeID)) {
            $this->obOrder->shipping_type_id = $this->iShippingTypeID;
        }

        // The 1C line totals already contain every discount the manager kept, nothing may be applied on top.
        $this->obOrder->order_promo_mechanism()->delete();
        $this->obOrder->reloadRelations('order_position');
        // The processor reads the unsaved shipping price, listeners of the save below get the final totals.
        OrderPromoMechanismProcessor::update($this->obOrder);

        $this->obOrder->save();
    }

    /**
     * Write the 1C goods lines, each line takes its own position with that 1C Ид.
     * @param array $arOrderPositionList
     * @return bool
     * @throws \Exception
     */
    protected function mirrorOrderPositionList(array $arOrderPositionList): bool
    {
        $arMatchedPositionIdList = [];
        $arOrderPositionDataToAdd = [];

        foreach ($arOrderPositionList as $arOrderPosition) {
            $sExternalId = $this->formatString(array_get($arOrderPosition, 'external_id'));

            if (empty($sExternalId) || isset($this->arShippingTypeMap[$sExternalId]) || !empty($arOrderPosition['is_service'])) {
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

            $obSameIdPositionList = OrderPosition::where('order_id', $this->obOrder->id)
                ->where('one_c_external_id', $sExternalId)
                ->orderBy('id')
                ->get();
            $obOrderPosition = $obSameIdPositionList->whereNotIn('id', $arMatchedPositionIdList)->first();

            if (empty($obOrderPosition)) {
                $arItem = $this->getNewPositionItem($sExternalId, $obSameIdPositionList->first());

                if (empty($arItem)) {
                    break;
                }

                $arOrderPositionDataToAdd[] = $arItem + [
                    'order_id' => $this->obOrder->id,
                    'one_c_external_id' => $sExternalId,
                    'price' => $fPrice,
                    'old_price' => $fListPrice,
                    'quantity' => $iQuantity
                ];

                continue;
            }

            $arMatchedPositionIdList[] = $obOrderPosition->id;

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

        if (!Result::status()) {
            return false;
        }

        $this->deleteUnmatchedOrderPositionList($arMatchedPositionIdList);
        $this->addOrderPositionList($arOrderPositionDataToAdd);

        return Result::status();
    }

    /**
     * Item of a position the sync adds. A 1C Ид listed again takes the item of the position its first line
     * matched, a bare product Ид (product with one offer, see OrderPositionModelHandler) names no offer.
     * @param string $sExternalId
     * @param OrderPosition|null $obSameIdPosition
     * @return array|null item_id, item_type
     */
    private function getNewPositionItem(string $sExternalId, ?OrderPosition $obSameIdPosition): ?array
    {
        if (!empty($obSameIdPosition)) {
            return $obSameIdPosition->only(['item_id', 'item_type']);
        }

        $sOfferExternalId = $this->getOfferExternalId($sExternalId);
        $obOffer = Offer::where('external_id', $sOfferExternalId)->first();

        if (empty($obOffer)) {
            Result::setFalse()->setMessage('Not found offer with external_id ' . $sOfferExternalId);

            return null;
        }

        return ['item_id' => $obOffer->id, 'item_type' => Offer::class];
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
     * Delete the order positions no 1C line matched
     * @param array $arMatchedPositionIdList
     */
    private function deleteUnmatchedOrderPositionList(array $arMatchedPositionIdList)
    {
        try {
            OrderPosition::where('order_id', $this->obOrder->id)
                ->whereNotIn('id', $arMatchedPositionIdList)
                ->delete();
        } catch (\Exception $obException) {
            Result::setFalse()->setMessage($obException->getMessage());
            Log::error($obException);
        }
    }

    /**
     * Add order positions for the 1C lines no position matched
     * @param array $arOrderPositionDataToAdd
     */
    private function addOrderPositionList(array $arOrderPositionDataToAdd)
    {
        if (!Result::status()) {
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
            // create() takes price and old_price from the catalog and OrderPositionModelHandler rebuilds the Ид, the 1C values are written back.
            $obOrderPosition->fill(array_only($arOrderPositionData, ['one_c_external_id', 'price', 'old_price', 'quantity']))->save();
        } catch (ModelException $obException) {
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
