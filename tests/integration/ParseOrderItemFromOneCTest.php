<?php

require_once __DIR__.'/../BaseCodePluginTestCase.php';

use Illuminate\Support\Facades\DB as Db;
use Illuminate\Support\Facades\Event;
use Kharanenka\Helper\Result;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OrderPromoMechanismProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;

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

        (new ParseOrderItemFromOneC())->process(ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER)));

        $this->assertTrue(Result::status(), (string) Result::message());

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

        (new ParseOrderItemFromOneC())->process(ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER)));

        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(49.21, round(Order::find($this->iOrderID)->total_price_value, 2));
    }

    public function testSyncIsIdempotent(): void
    {
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER));

        (new ParseOrderItemFromOneC())->process($arData);
        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertTrue(Result::status(), (string) Result::message());
        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(49.21, round($obOrder->total_price_value, 2));
        $this->assertSame(5, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count());
    }

    public function testZeroQuantityLineFailsTheSyncAndKeepsThePositions(): void
    {
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER));
        $arData['order_position_list'][0]['quantity'] = '0';

        $iStatusID = (int) Order::find($this->iOrderID)->status_id;

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertFalse(Result::status());
        $this->assertSame($iStatusID, (int) Order::find($this->iOrderID)->status_id, 'status rolled back with the lines');
        $this->assertSame(6, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count(), 'rolled back, stale line still there');
        $this->assertSame(1, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
        $this->assertSame(0, Db::transactionLevel());
    }

    public function testDocumentWithoutLinesKeepsTheOrderAndClosesNoTransaction(): void
    {
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER));
        $arData['order_position_list'] = [];

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertSame(0, Db::transactionLevel());
        $this->assertSame(6, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count());
        $this->assertSame(1, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
    }

    public function testExceptionInsideTheSyncRollsBack(): void
    {
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument(self::FIXTURE_ORDER));
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
        $this->assertSame(6, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count());
    }

    public function testServiceLineWithoutShippingTypeIsChargedAsShipping(): void
    {
        $sZpakLine = '<Товар><Ид>1a5901c6-4ef8-11e9-ab4d-68ecc5c29a9c</Ид><Наименование>zPAK - Shipping cost</Наименование>'
            .'<ЗначенияРеквизитов><ЗначениеРеквизита><Наименование>ТипНоменклатуры</Наименование><Значение>Pakalpojums</Значение></ЗначениеРеквизита></ЗначенияРеквизитов>'
            .'<ЦенаЗаЕдиницу>50</ЦенаЗаЕдиницу><Количество>1</Количество><Сумма>50</Сумма></Товар>';
        $sXml = str_replace('</Товары>', $sZpakLine.'</Товары>', file_get_contents($this->fixturePath(self::FIXTURE_ORDER)));
        $obDocument = simplexml_load_string($sXml, XMLObjectClass::class)->xpath(ImportOrders::XML_PATH_ORDER_LIST)[0];

        (new ParseOrderItemFromOneC())->process(ImportOrders::parseOrderDocument($obDocument));

        $this->assertTrue(Result::status(), (string) Result::message());
        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(6, (int) $obOrder->shipping_type_id, 'shipping type from the matching 1C line');
        $this->assertSame(54.0, (float) $obOrder->shipping_price_value, '4.00 parcel locker + 50 zPAK');
        $this->assertSame(5, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count(), 'service lines are never positions');
        $this->assertSame(99.21, round($obOrder->total_price_value, 2));
    }
}
