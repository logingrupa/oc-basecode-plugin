<?php

require_once __DIR__.'/../BaseCodePluginTestCase.php';

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB as Db;
use Illuminate\Support\Facades\Event;
use Kharanenka\Helper\Result;
use Lovata\BaseCode\Classes\Event\Order\OrderModelHandler;
use Lovata\BaseCode\Classes\Helper\OneC\Import1CHelper;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OrderPromoMechanismProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\OrdersShopaholic\Models\ShippingType;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * After the 1C export the shop shows the manager's numbers: list price as
 * old_price, the charged line total as price, and no shop-side mechanism
 * left to discount them a second time.
 */
class ParseOrderItemFromOneCTest extends BaseCodePluginTestCase
{
    /** 1C result per line: list price, charged */
    const ONE_C_LINES = [
        [12.72, 8.90],
        [16.90, 12.50],
        [8.30, 6.91],
        [10.90, 8.18],
        [10.90, 8.72],
    ];

    /** Index of the delivery line in the fixture payload */
    const DELIVERY_LINE = 5;

    const ZPAK_EXTERNAL_ID = '1a5901c6-4ef8-11e9-ab4d-68ecc5c29a9c';

    const COURIER_EXTERNAL_ID = '5c0d1e7a-3b2f-11ec-ba95-68ecc5c29a9c';

    /** Shared on prod by the active store pickup and the inactive pickup points */
    const PICKUP_EXTERNAL_ID = '2fdaf0af-5160-11ec-ba95-68ecc5c29a9c';

    /** @var int */
    private $iOrderID;

    public function setUp(): void
    {
        parent::setUp();

        $this->iOrderID = $this->seedCheckoutOrder();
    }

    public function testSyncMirrorsOneCLineTotalsAndDropsShopMechanisms(): void
    {
        $obOrder = Order::find($this->iOrderID);
        $this->assertLessThan(64.72, $obOrder->position_total_price_value, 'before sync the shop mechanism still discounts the checkout prices');

        $this->sync($this->fixturePayload());

        $obOrder = Order::find($this->iOrderID);
        OrderPromoMechanismProcessor::update($obOrder);

        $arPositionList = Db::table('lovata_orders_shopaholic_order_positions')
            ->where('order_id', $this->iOrderID)
            ->orderBy('item_id')
            ->get(['one_c_external_id', 'price', 'old_price', 'quantity'])
            ->map(fn ($obRow) => [(float) $obRow->old_price, (float) $obRow->price])
            ->all();

        $this->assertSame(self::ONE_C_LINES, $arPositionList, 'old_price = 1C list, price = 1C charged line total / qty; stale line gone');
        $this->assertSame(0, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
        $this->assertSame(3, (int) $obOrder->status_id, 'complete');
        $this->assertSame(4.0, (float) $obOrder->shipping_price_value);
        $this->assertSame(45.21, round($obOrder->position_total_price_value, 2));
        $this->assertSame(49.21, round($obOrder->total_price_value, 2), 'order total = 1C document total');
    }

    public function testEarlierFailureInTheSameProcessDoesNotBlockTheSync(): void
    {
        Result::setFalse()->setMessage('failure left by an earlier queue job');

        $this->sync($this->fixturePayload());

        $this->assertSame(49.21, round(Order::find($this->iOrderID)->total_price_value, 2));
    }

    public function testSyncIsIdempotent(): void
    {
        $arData = $this->fixturePayload();

        $this->sync($arData);
        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(49.21, round($obOrder->total_price_value, 2));
        $this->assertSame(5, $this->positionCount());
    }

    public function testPositionCreatedForALineTheShopOrderLacksKeepsTheOneCNumbers(): void
    {
        [$sExternalID] = self::CHECKOUT_LINES[1];
        Db::table('lovata_orders_shopaholic_order_positions')->where('one_c_external_id', $sExternalID)->delete();
        $iOfferID = $this->seedOffer($sExternalID, 21.90, 24.90);

        $this->sync($this->fixturePayload());

        $arCreated = $this->positionRows($sExternalID);
        $this->assertCount(1, $arCreated, 'one position per 1C line, stored under the 1C Ид');
        $this->assertSame([$iOfferID, 12.50, 16.90, 1], array_slice($arCreated[0], 1), 'price = 1C charged, old_price = 1C list, not the catalog 21.90 / 24.90');
        $this->assertSame(49.21, round(Order::find($this->iOrderID)->total_price_value, 2));

        $this->sync($this->fixturePayload());

        $this->assertSame($arCreated, $this->positionRows($sExternalID), 'the next sync matches the created position instead of replacing it');
    }

    public function testSameOneCLineTwiceUpdatesTwoShopPositions(): void
    {
        [$sExternalID] = self::CHECKOUT_LINES[0];
        $this->insertPosition($this->iOrderID, $sExternalID, 1, 12.72);
        $arShopPositionIDList = array_column($this->positionRows($sExternalID), 0);

        $arData = $this->fixturePayload();
        $arData['order_position_list'][] = $this->oneCLine($sExternalID, '12.67', '1', '12.67');

        $this->sync($arData);

        $this->assertSame([
            [$arShopPositionIDList[0], 1, 8.90, 12.72, 1],
            [$arShopPositionIDList[1], 1, 12.67, 12.67, 1],
        ], $this->positionRows($sExternalID), 'each 1C line updates its own shop position');
        $this->assertSame(6, $this->positionCount());
        $this->assertSame(61.88, round(Order::find($this->iOrderID)->total_price_value, 2), '45.21 + 12.67 + 4.00 delivery');
    }

    public static function shopPositionIdProvider(): array
    {
        [$sProductOfferID] = self::CHECKOUT_LINES[0];

        return [
            'product#offer Ид' => [$sProductOfferID],
            // OrderPositionModelHandler stores the bare product Ид when the product has one offer, the offer keeps its own GUID.
            'bare product Ид' => [explode('#', $sProductOfferID)[0]],
        ];
    }

    #[DataProvider('shopPositionIdProvider')]
    public function testSameOneCLineTwiceAgainstOneShopPositionCreatesTheSecond(string $sExternalID): void
    {
        [$sCheckoutID] = self::CHECKOUT_LINES[0];
        $iOfferID = $this->seedOffer($sCheckoutID, 21.90, 24.90);
        Db::table('lovata_orders_shopaholic_order_positions')
            ->where('one_c_external_id', $sCheckoutID)
            ->update(['one_c_external_id' => $sExternalID, 'item_id' => $iOfferID]);
        [$iShopPositionID] = $this->positionRows($sExternalID)[0];

        $arData = $this->fixturePayload();
        $arData['order_position_list'][0]['external_id'] = $sExternalID;
        $arData['order_position_list'][] = $this->oneCLine($sExternalID, '12.67', '1', '12.67');

        $this->sync($arData);

        $arRowList = $this->positionRows($sExternalID);
        $this->assertSame([$iShopPositionID, $iOfferID, 8.90, 12.72, 1], $arRowList[0], 'the shop position takes the first 1C line');
        $this->assertSame([$iOfferID, 12.67, 12.67, 1], array_slice($arRowList[1], 1), 'the second 1C line becomes a new position with the 1C numbers');
        $this->assertCount(2, $arRowList);
        $this->assertSame(61.88, round(Order::find($this->iOrderID)->total_price_value, 2));
    }

    public function testSameOneCLineTwiceWithoutAShopPositionCreatesTwo(): void
    {
        [$sExternalID] = self::CHECKOUT_LINES[1];
        Db::table('lovata_orders_shopaholic_order_positions')->where('one_c_external_id', $sExternalID)->delete();
        $iOfferID = $this->seedOffer($sExternalID, 21.90, 24.90);

        $arData = $this->fixturePayload();
        $arData['order_position_list'][] = $this->oneCLine($sExternalID, '16.90', '1', '16.90');

        $this->sync($arData);

        $this->assertSame([
            [$iOfferID, 12.50, 16.90, 1],
            [$iOfferID, 16.90, 16.90, 1],
        ], array_map(fn (array $arRow) => array_slice($arRow, 1), $this->positionRows($sExternalID)), 'one new position per 1C line');
        $this->assertSame(66.11, round(Order::find($this->iOrderID)->total_price_value, 2), '45.21 + 16.90 + 4.00 delivery');
    }

    public function testSecondShopPositionWithTheSameIdIsDeletedWhenOneCListsItOnce(): void
    {
        [$sExternalID] = self::CHECKOUT_LINES[0];
        [$iShopPositionID] = $this->positionRows($sExternalID)[0];
        $this->insertPosition($this->iOrderID, $sExternalID, 1, 12.72);

        $this->sync($this->fixturePayload());

        $this->assertSame([[$iShopPositionID, 1, 8.90, 12.72, 1]], $this->positionRows($sExternalID), 'the position no 1C line matched is deleted by id');
        $this->assertSame(49.21, round(Order::find($this->iOrderID)->total_price_value, 2));
    }

    public static function unresolvedStatusProvider(): array
    {
        return [
            'no status in 1C' => [null],
            'status code the shop does not know' => ['shipped'],
        ];
    }

    #[DataProvider('unresolvedStatusProvider')]
    public function testServiceLinesApplyWhenTheStatusDoesNotResolve(?string $sCodeStatus): void
    {
        // Not the first status by sort_order, the one an unfiltered status query returns.
        Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->update(['status_id' => 4]);
        $arData = $this->fixturePayload();
        $arData['code_status'] = $sCodeStatus;
        $arData['order_position_list'][self::DELIVERY_LINE]['total'] = '5';

        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(4, (int) $obOrder->status_id, 'status stays as it was');
        $this->assertSame(5.0, (float) $obOrder->shipping_price_value, 'shipping from the 1C delivery line');
        $this->assertSame(50.21, round($obOrder->total_price_value, 2), 'lines mirrored, 45.21 + 5.00');
    }

    public function testDocumentWithoutServiceLineIsFreeShipping(): void
    {
        $arData = $this->fixturePayload();
        unset($arData['order_position_list'][self::DELIVERY_LINE]);

        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(6, (int) $obOrder->shipping_type_id, 'the checkout shipping type stays');
        $this->assertSame(0.0, (float) $obOrder->shipping_price_value, 'free shipping, the checkout discount row is gone');
        $this->assertSame(45.21, round($obOrder->total_price_value, 2), 'order total = 1C document total');
    }

    public function testInactiveShippingTypeMappedToAServiceLineIsAssigned(): void
    {
        Db::table('lovata_orders_shopaholic_shipping_types')->insert([
            'id' => 9, 'active' => 0, 'name' => 'zPAK', 'external_id' => self::ZPAK_EXTERNAL_ID, 'price' => 0,
        ]);
        $arData = $this->fixturePayload();
        $arData['order_position_list'][self::DELIVERY_LINE] = $this->oneCLine(self::ZPAK_EXTERNAL_ID, '50', '1', '50', true);

        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(9, (int) $obOrder->shipping_type_id, 'the export sends this GUID back to 1C');
        $this->assertSame(50.0, (float) $obOrder->shipping_price_value);
        $this->assertSame(5, $this->positionCount());
    }

    public function testSharedGuidResolvesToTheActiveShippingTypeWithTheLowestId(): void
    {
        Db::table('lovata_orders_shopaholic_shipping_types')->where('id', 6)->update(['sort_order' => 5]);
        Db::table('lovata_orders_shopaholic_shipping_types')->insert([
            ['id' => 2, 'active' => 0, 'name' => 'Pakomats old', 'external_id' => self::DELIVERY_EXTERNAL_ID, 'sort_order' => 1],
            ['id' => 7, 'active' => 1, 'name' => 'Pakomats copy', 'external_id' => self::DELIVERY_EXTERNAL_ID, 'sort_order' => 0],
        ]);
        Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->update(['shipping_type_id' => null]);
        $this->assertSame(7, ShippingType::where('external_id', self::DELIVERY_EXTERNAL_ID)->first()->id, 'the Sortable scope alone would pick the lowest sort_order');

        $this->sync($this->fixturePayload());

        $this->assertSame(6, (int) Order::find($this->iOrderID)->shipping_type_id);
    }

    public static function mappedServiceLineOrderProvider(): array
    {
        return [
            'checkout type, then zPAK' => [[self::DELIVERY_EXTERNAL_ID, self::ZPAK_EXTERNAL_ID], 6],
            'zPAK, then checkout type' => [[self::ZPAK_EXTERNAL_ID, self::DELIVERY_EXTERNAL_ID], 6],
            'other active type, then zPAK' => [[self::COURIER_EXTERNAL_ID, self::ZPAK_EXTERNAL_ID], 11],
            'zPAK, then other active type' => [[self::ZPAK_EXTERNAL_ID, self::COURIER_EXTERNAL_ID], 11],
        ];
    }

    #[DataProvider('mappedServiceLineOrderProvider')]
    public function testHiddenMappedTypeNeverReplacesTheCheckoutOrAnActiveType(array $arServiceIDList, int $iShippingTypeID): void
    {
        Db::table('lovata_orders_shopaholic_shipping_types')->insert([
            ['id' => 9, 'active' => 0, 'name' => 'zPAK', 'external_id' => self::ZPAK_EXTERNAL_ID],
            ['id' => 11, 'active' => 1, 'name' => 'Courier', 'external_id' => self::COURIER_EXTERNAL_ID],
        ]);
        $arData = $this->fixturePayload();
        $arServiceLineList = array_map(fn (string $sExternalID) => $this->oneCLine($sExternalID, '25', '1', '25', true), $arServiceIDList);
        array_splice($arData['order_position_list'], self::DELIVERY_LINE, 1, $arServiceLineList);

        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame($iShippingTypeID, (int) $obOrder->shipping_type_id, 'checkout type first, then an active type, whatever the 1C line order');
        $this->assertSame(50.0, (float) $obOrder->shipping_price_value, 'every service line is charged');
    }

    public function testCheckoutTypeSharingTheLineGuidIsKept(): void
    {
        Db::table('lovata_orders_shopaholic_shipping_types')->insert([
            ['id' => 2, 'active' => 1, 'name' => 'Store pickup', 'external_id' => self::PICKUP_EXTERNAL_ID],
            ['id' => 10, 'active' => 0, 'name' => 'Seminar reservation pickup', 'external_id' => self::PICKUP_EXTERNAL_ID],
        ]);
        Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->update(['shipping_type_id' => 10]);
        $arData = $this->fixturePayload();
        $arData['order_position_list'][self::DELIVERY_LINE] = $this->oneCLine(self::PICKUP_EXTERNAL_ID, '3', '1', '3', true);

        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(10, (int) $obOrder->shipping_type_id, 'the GUID maps to the active type 2, the order keeps its own pickup point');
        $this->assertSame(3.0, (float) $obOrder->shipping_price_value);
    }

    public static function uncommittedSyncProvider(): array
    {
        return [
            'order not in the shop' => ['260907-9999', '1'],
            'sync rolls back' => ['260907-0010', '0'],
        ];
    }

    #[DataProvider('uncommittedSyncProvider')]
    public function testUnmappedServiceLineIsLoggedOnlyForACommittedSync(string $sOrderNumber, string $sFirstLineQuantity): void
    {
        $arData = $this->fixturePayload();
        $arData['order_number'] = $sOrderNumber;
        $arData['order_position_list'][0]['quantity'] = $sFirstLineQuantity;
        $arData['order_position_list'][] = $this->oneCLine(self::ZPAK_EXTERNAL_ID, '50', '1', '50', true);
        $arLogList = [];
        Event::listen(MessageLogged::class, function (MessageLogged $obMessage) use (&$arLogList) {
            $arLogList[] = $obMessage->message;
        });

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertSame([], preg_grep('~maps to no shipping type~', $arLogList), 'nothing was written, nothing to round-trip');
    }

    public function testServiceLineWithoutShippingTypeIsChargedAsShipping(): void
    {
        $sZpakLine = '<Товар><Ид>'.self::ZPAK_EXTERNAL_ID.'</Ид><Наименование>zPAK - Shipping cost</Наименование>'
            .'<ЗначенияРеквизитов><ЗначениеРеквизита><Наименование>ТипНоменклатуры</Наименование><Значение>Pakalpojums</Значение></ЗначениеРеквизита></ЗначенияРеквизитов>'
            .'<ЦенаЗаЕдиницу>50</ЦенаЗаЕдиницу><Количество>1</Количество><Сумма>50</Сумма></Товар>';
        $sXml = str_replace('</Товары>', $sZpakLine.'</Товары>', file_get_contents($this->fixturePath(self::FIXTURE_ORDER)));
        $obDocument = simplexml_load_string($sXml, XMLObjectClass::class)->xpath(ImportOrders::XML_PATH_ORDER_LIST)[0];
        // A type without a GUID maps no 1C line, also not a service line without Ид.
        Db::table('lovata_orders_shopaholic_shipping_types')->insert(['id' => 1, 'active' => 1, 'name' => 'Pickup', 'external_id' => '']);
        $arData = ImportOrders::parseOrderDocument($obDocument);
        $arData['order_position_list'][] = $this->oneCLine('', '1', '1', '1', true);
        $arLogList = [];
        Event::listen(MessageLogged::class, function (MessageLogged $obMessage) use (&$arLogList) {
            $arLogList[] = $obMessage->level.': '.$obMessage->message;
        });

        $this->sync($arData);

        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(6, (int) $obOrder->shipping_type_id, 'shipping type from the matching 1C line');
        $this->assertSame(55.0, (float) $obOrder->shipping_price_value, '4.00 parcel locker + 50 zPAK + 1 service line without Ид');
        $this->assertSame(5, $this->positionCount(), 'service lines are never positions');
        $this->assertSame(100.21, round($obOrder->total_price_value, 2));
        $this->assertContains('warning: 1C order 260907-0010 service line '.self::ZPAK_EXTERNAL_ID.' maps to no shipping type', $arLogList);
    }

    public static function failingLineProvider(): array
    {
        return [
            'quantity 0' => [0, ['quantity' => '0']],
            'offer not in the catalog' => [1, ['external_id' => '00000000-0000-0000-0000-000000000000#00000000-0000-0000-0000-000000000001']],
        ];
    }

    #[DataProvider('failingLineProvider')]
    public function testFailedLineNeverSavesTheOrder(int $iLine, array $arLineOverride): void
    {
        $arData = $this->fixturePayload();
        $arData['order_position_list'][$iLine] = array_merge($arData['order_position_list'][$iLine], $arLineOverride);
        $arOrderSaveList = [];
        Event::listen('eloquent.saving: '.Order::class, function (Order $obOrder) use (&$arOrderSaveList) {
            $arOrderSaveList[] = $obOrder->status_id;
        });

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertFalse(Result::status());
        $this->assertSame([], $arOrderSaveList, 'order listeners never see the 1C status of a sync that rolls back');
        $this->assertSame(2, (int) Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->value('status_id'));
        $this->assertSame(6, $this->positionCount(), 'rolled back, stale line still there');
        $this->assertSame(1, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
        $this->assertSame(0, Db::transactionLevel());
    }

    public function testOrderIsSavedOnceAfterTheLinesWithTheFinalTotals(): void
    {
        $arData = $this->fixturePayload();
        $arData['order_position_list'][self::DELIVERY_LINE]['total'] = '5';
        $arOrderSaveList = [];
        Event::listen('eloquent.saved: '.Order::class, function (Order $obOrder) use (&$arOrderSaveList) {
            $arOrderSaveList[] = [(int) $obOrder->status_id, (float) $obOrder->shipping_price_value, round($obOrder->total_price_value, 2), $this->positionCount()];
        });

        $this->sync($arData);

        $this->assertSame([[3, 5.0, 50.21, 5]], $arOrderSaveList, 'one save carries status, shipping and the 1C totals');
    }

    public function testOrderSaveFailureReachesTheCallerUnchanged(): void
    {
        $obThrown = new RuntimeException('order save failed');
        Event::listen('eloquent.saving: '.Order::class, function () use ($obThrown) {
            throw $obThrown;
        });

        try {
            (new ParseOrderItemFromOneC())->process($this->fixturePayload());
            $this->fail('the exception must reach the caller');
        } catch (RuntimeException $obException) {
            $this->assertSame($obThrown, $obException);
        }

        $this->assertSame(0, Db::transactionLevel());
        $this->assertSame(6, $this->positionCount());
        $this->assertSame(2, (int) Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->value('status_id'));
    }

    public function testDocumentWithoutLinesKeepsTheOrderAndClosesNoTransaction(): void
    {
        $arData = $this->fixturePayload();
        $arData['order_position_list'] = [];

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertSame(0, Db::transactionLevel());
        $this->assertSame(6, $this->positionCount());
        $this->assertSame(1, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
    }

    public function testExceptionInsideTheSyncRollsBack(): void
    {
        $arData = $this->fixturePayload();
        Event::listen('eloquent.saving: ' . OrderPosition::class, function () {
            throw new RuntimeException('position save failed');
        });

        try {
            (new ParseOrderItemFromOneC())->process($arData);
            $this->fail('the exception must reach the caller');
        } catch (RuntimeException $obException) {
            $this->assertSame('position save failed', $obException->getMessage());
        }

        $this->assertSame(0, Db::transactionLevel());
        $this->assertSame(6, $this->positionCount());
        $this->assertFalse(Import1CHelper::instance()->status(), 'the 1C flag is off again after a sync that threw');
    }

    public function testReExportFlagIsOnOnlyWhileTheSyncRuns(): void
    {
        $arFlagList = [];
        Event::listen('eloquent.saving: '.Order::class, function () use (&$arFlagList) {
            $arFlagList[] = Import1CHelper::instance()->status();
        });

        $this->sync($this->fixturePayload());

        $this->assertSame([true], $arFlagList);
        $this->assertFalse(Import1CHelper::instance()->status());
        $this->assertNull(Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->value('one_c_status_id'), 'a 1C status is not sent back to 1C');

        $obOrder = Order::find($this->iOrderID);
        $obOrder->status_id = 4;
        $obOrder->save();

        $this->assertSame(OrderModelHandler::ONE_C_STATUS_NEW, (int) Db::table('lovata_orders_shopaholic_orders')->where('id', $this->iOrderID)->value('one_c_status_id'), 'a shop status change after the sync is queued for 1C');
    }

    private function sync(array $arData): void
    {
        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertTrue(Result::status(), (string) Result::message());
    }

    private function fixturePayload(): array
    {
        return ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER));
    }

    private function oneCLine(string $sExternalID, string $sPrice, string $sQuantity, string $sTotal, bool $bIsService = false): array
    {
        return ['external_id' => $sExternalID, 'price' => $sPrice, 'quantity' => $sQuantity, 'total' => $sTotal, 'is_service' => $bIsService];
    }

    private function positionCount(): int
    {
        return Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count();
    }

    /**
     * Positions stored under one 1C Ид: id, item_id, price, old_price, quantity.
     */
    private function positionRows(string $sExternalID): array
    {
        return Db::table('lovata_orders_shopaholic_order_positions')
            ->where('order_id', $this->iOrderID)
            ->where('one_c_external_id', $sExternalID)
            ->orderBy('id')
            ->get()
            ->map(fn ($obRow) => [(int) $obRow->id, (int) $obRow->item_id, (float) $obRow->price, (float) $obRow->old_price, (int) $obRow->quantity])
            ->all();
    }
}
