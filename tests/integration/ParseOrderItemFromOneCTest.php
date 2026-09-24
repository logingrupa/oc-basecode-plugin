<?php

require_once __DIR__.'/../BaseCodePluginTestCase.php';

use Illuminate\Support\Facades\DB as Db;
use Kharanenka\Helper\Result;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OrderPromoMechanismProcessor;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\WithoutConditionDiscountPosition;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * After the 1C export the shop shows the manager's numbers: list price as
 * old_price, the charged line total as price, and no shop-side mechanism
 * left to discount them a second time. Fixture: the real 1C export of .lv
 * order 260907-0010 (goods 45.21 + delivery 4.00 = 49.21).
 */
class ParseOrderItemFromOneCTest extends BaseCodePluginTestCase
{
    const OFFER_TYPE = 'Lovata\Shopaholic\Models\Offer';
    const DELIVERY_EXTERNAL_ID = '09f78ef6-de0a-11ea-ab9f-68ecc5c29a9c';

    /** Shop values at checkout: shop price, 1C external id */
    const CHECKOUT_LINES = [
        ['b5a39dbf-7480-11e3-806d-00138f293d96#af657c3c-7474-11f1-8b15-cc5ef85a3bbc', 12.72],
        ['63d3e754-fa02-11ed-bad0-68ecc5c29a9c#7b09ed19-fa02-11ed-bad0-68ecc5c29a9c', 16.90],
        ['8b4a0206-1fad-11e9-ab33-68ecc5c29a9c#98da999c-911a-11ea-ab9b-68ecc5c29a9c', 8.30],
        ['2c9d60e0-292a-11ed-bab4-68ecc5c29a9c#42e4a7a0-292a-11ed-bab4-68ecc5c29a9c', 10.90],
        ['6dad7526-bc15-11ee-baeb-68ecc5c29a9c#80b8f471-bc15-11ee-baeb-68ecc5c29a9c', 10.90],
    ];

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

        Db::table('lovata_orders_shopaholic_shipping_types')->insert([
            'id' => 6, 'active' => 1, 'name' => 'Pakomats', 'code' => 'omniva', 'external_id' => self::DELIVERY_EXTERNAL_ID, 'price' => 4.00,
        ]);

        $this->iOrderID = (int) Db::table('lovata_orders_shopaholic_orders')->insertGetId([
            'order_number' => '260907-0010',
            'status_id' => 2,
            'shipping_type_id' => 6,
            'shipping_price' => 4.00,
            'currency_id' => 1,
            'property' => '{}',
            'created_at' => '2026-09-07 19:44:26',
            'updated_at' => '2026-09-07 19:44:26',
        ]);

        foreach (self::CHECKOUT_LINES as $iIndex => [$sExternalID, $fPrice]) {
            $this->insertPosition($sExternalID, $iIndex + 1, $fPrice);
        }
        $this->insertPosition('deadbeef#stale', 99, 5.00);

        Db::table('lovata_orders_shopaholic_order_promo_mechanism')->insert([
            'order_id' => $this->iOrderID,
            'mechanism_id' => 75,
            'name' => 'Apjoma Boittle gel 5gab',
            'type' => WithoutConditionDiscountPosition::class,
            'priority' => 1,
            'discount_value' => 10,
            'discount_type' => 'percent',
            'property' => '[]',
        ]);
    }

    public function testSyncMirrorsOneCLineTotalsAndDropsShopMechanisms(): void
    {
        $obOrder = Order::find($this->iOrderID);
        $this->assertLessThan(64.72, $obOrder->position_total_price_value, 'before sync the shop mechanism still discounts the checkout prices');

        (new ParseOrderItemFromOneC())->process(ImportOrders::parseOrderDocument($this->fixtureDocument('order-260907-0010.xml')));

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
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument('order-260907-0010.xml'));

        (new ParseOrderItemFromOneC())->process($arData);
        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertTrue(Result::status(), (string) Result::message());
        $obOrder = Order::find($this->iOrderID);
        $this->assertSame(49.21, round($obOrder->total_price_value, 2));
        $this->assertSame(5, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count());
    }

    public function testZeroQuantityLineFailsTheSyncAndKeepsThePositions(): void
    {
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument('order-260907-0010.xml'));
        $arData['order_position_list'][0]['quantity'] = '0';

        (new ParseOrderItemFromOneC())->process($arData);

        $this->assertFalse(Result::status());
        $this->assertSame(6, Db::table('lovata_orders_shopaholic_order_positions')->where('order_id', $this->iOrderID)->count(), 'rolled back, stale line still there');
        $this->assertSame(1, Db::table('lovata_orders_shopaholic_order_promo_mechanism')->where('order_id', $this->iOrderID)->count());
    }

    private function insertPosition(string $sExternalID, int $iItemID, float $fPrice): void
    {
        Db::table('lovata_orders_shopaholic_order_positions')->insert([
            'order_id' => $this->iOrderID,
            'item_id' => $iItemID,
            'item_type' => self::OFFER_TYPE,
            'one_c_external_id' => $sExternalID,
            'price' => $fPrice,
            'old_price' => 0,
            'quantity' => 1,
            'created_at' => '2026-09-07 19:44:26',
            'updated_at' => '2026-09-07 19:44:26',
        ]);
    }
}
