<?php

require_once __DIR__.'/../BaseCodePluginTestCase.php';

use Illuminate\Support\Facades\DB as Db;
use Kharanenka\Helper\Result;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OrderPromoMechanismProcessor;
use Lovata\OrdersShopaholic\Models\Order;

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

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertFalse(Result::status());
        $this->assertSame(6, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count(), 'rolled back, stale line still there');
        $this->assertSame(1, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
    }
}
