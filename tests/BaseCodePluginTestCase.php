<?php

use Illuminate\Support\Facades\DB as Db;
use Illuminate\Support\Facades\Schema;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\WithoutConditionDiscountPosition;
use October\Rain\Database\Schema\Blueprint;
use System\Models\SettingModel;

/**
 * The full Shopaholic migration chain does not run on SQLite, so the order
 * tables the 1C sync touches are created as stubs with the columns the real
 * models read and write. The shared fixture is the real 1C export of .lv
 * order 260907-0010 with the buyer renamed (goods 45.21 + delivery 4.00).
 */
abstract class BaseCodePluginTestCase extends PluginTestCase
{
    const OFFER_TYPE = 'Lovata\Shopaholic\Models\Offer';
    const FIXTURE_ORDER = 'order-260907-0010.xml';
    const DELIVERY_EXTERNAL_ID = '09f78ef6-de0a-11ea-ab9f-68ecc5c29a9c';

    /** Shop values at checkout: 1C external id, shop price */
    const CHECKOUT_LINES = [
        ['b5a39dbf-7480-11e3-806d-00138f293d96#af657c3c-7474-11f1-8b15-cc5ef85a3bbc', 12.72],
        ['63d3e754-fa02-11ed-bad0-68ecc5c29a9c#7b09ed19-fa02-11ed-bad0-68ecc5c29a9c', 16.90],
        ['8b4a0206-1fad-11e9-ab33-68ecc5c29a9c#98da999c-911a-11ea-ab9b-68ecc5c29a9c', 8.30],
        ['2c9d60e0-292a-11ed-bab4-68ecc5c29a9c#42e4a7a0-292a-11ed-bab4-68ecc5c29a9c', 10.90],
        ['6dad7526-bc15-11ee-baeb-68ecc5c29a9c#80b8f471-bc15-11ee-baeb-68ecc5c29a9c', 10.90],
    ];

    protected $autoMigrate = false;

    /**
     * Modules must be migrated BEFORE PluginTestCase::setUp() calls
     * Mail::pretend() - resolving the mailer queries system_settings.
     */
    public function createApplication()
    {
        $obApp = parent::createApplication();

        $this->migrateModules();

        return $obApp;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->createOrderTables();

        SettingModel::clearInternalCache();
    }

    protected function fixturePath(string $sFileName): string
    {
        return __DIR__.'/fixtures/'.$sFileName;
    }

    protected function fixtureDocument(string $sFileName): XMLObjectClass
    {
        $obXml = ImportOrders::getXmlObject($this->fixturePath($sFileName));
        $arDocumentList = $obXml->xpath(ImportOrders::XML_PATH_ORDER_LIST);

        $this->assertCount(1, $arDocumentList, 'fixture holds exactly one order document');

        return $arDocumentList[0];
    }

    /**
     * Order 260907-0010 as the shop stored it at checkout: waiting for payment,
     * five goods lines at shop prices plus one stale line 1C never had, and one
     * shop mechanism (10 % on every position) still attached.
     */
    protected function seedCheckoutOrder(): int
    {
        Db::table('lovata_orders_shopaholic_shipping_types')->insert([
            'id' => 6, 'active' => 1, 'name' => 'Pakomats', 'code' => 'omniva', 'external_id' => self::DELIVERY_EXTERNAL_ID, 'price' => 4.00,
        ]);

        $iOrderID = (int) Db::table('lovata_orders_shopaholic_orders')->insertGetId([
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
            $this->insertPosition($iOrderID, $sExternalID, $iIndex + 1, $fPrice);
        }
        $this->insertPosition($iOrderID, 'deadbeef#stale', 99, 5.00);

        Db::table('lovata_orders_shopaholic_order_promo_mechanism')->insert([
            'order_id' => $iOrderID,
            'mechanism_id' => 75,
            'name' => 'Apjoma Boittle gel 5gab',
            'type' => WithoutConditionDiscountPosition::class,
            'priority' => 1,
            'discount_value' => 10,
            'discount_type' => 'percent',
            'property' => '[]',
        ]);

        return $iOrderID;
    }

    private function insertPosition(int $iOrderID, string $sExternalID, int $iItemID, float $fPrice): void
    {
        Db::table('lovata_orders_shopaholic_order_positions')->insert([
            'order_id' => $iOrderID,
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

    protected function createOrderTables(): void
    {
        $this->createStubTable('lovata_orders_shopaholic_orders', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('order_number')->nullable();
            $obTable->string('secret_key')->nullable();
            $obTable->integer('user_id')->nullable();
            $obTable->integer('status_id')->nullable();
            $obTable->integer('one_c_status_id')->nullable();
            $obTable->integer('shipping_type_id')->nullable();
            $obTable->integer('payment_method_id')->nullable();
            $obTable->integer('currency_id')->nullable();
            $obTable->integer('manager_id')->nullable();
            $obTable->integer('site_id')->nullable();
            $obTable->decimal('shipping_price', 15, 2)->nullable();
            $obTable->decimal('shipping_tax_percent', 8, 2)->nullable();
            $obTable->text('property')->nullable();
            $obTable->text('payment_data')->nullable();
            $obTable->text('payment_response')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_orders_shopaholic_order_positions', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('item_id');
            $obTable->string('item_type');
            $obTable->string('one_c_external_id')->nullable();
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('quantity')->nullable();
            $obTable->string('code')->nullable();
            $obTable->decimal('tax_percent', 8, 2)->nullable();
            $obTable->text('property')->nullable();
            $obTable->decimal('weight', 15, 2)->nullable();
            $obTable->decimal('height', 15, 2)->nullable();
            $obTable->decimal('length', 15, 2)->nullable();
            $obTable->decimal('width', 15, 2)->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_orders_shopaholic_order_promo_mechanism', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('mechanism_id');
            $obTable->string('name');
            $obTable->string('type');
            $obTable->integer('priority');
            $obTable->float('discount_value');
            $obTable->string('discount_type');
            $obTable->boolean('final_discount')->default(0);
            $obTable->boolean('increase')->default(0);
            $obTable->text('property')->nullable();
            $obTable->integer('element_id')->nullable();
            $obTable->string('element_type')->nullable();
            $obTable->mediumText('element_data')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_orders_shopaholic_statuses', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('sort_order')->nullable();
        });

        $this->createStubTable('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(1);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_orders_shopaholic_payment_methods', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(1);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_shopaholic_currency', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('symbol')->nullable();
            $obTable->boolean('is_default')->default(0);
            $obTable->boolean('active')->default(1);
            $obTable->integer('sort_order')->nullable();
        });

        // TaxHelper lists active taxes while a promo processor recalculates, an empty table means 0 %.
        $this->createStubTable('lovata_shopaholic_taxes', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(1);
            $obTable->string('name')->nullable();
            $obTable->decimal('percent', 8, 2)->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
        });

        Db::table('lovata_orders_shopaholic_statuses')->insert([
            ['id' => 2, 'name' => 'Waiting for payment', 'code' => 'in_progress'],
            ['id' => 3, 'name' => 'Completed', 'code' => 'complete'],
            ['id' => 4, 'name' => 'Canceled', 'code' => 'canceled'],
        ]);
        Db::table('lovata_shopaholic_currency')->insert([
            ['id' => 1, 'name' => 'EUR', 'code' => 'EUR', 'symbol' => 'EUR', 'is_default' => 1, 'active' => 1],
        ]);
    }

    protected function createStubTable(string $sTableName, callable $fnDefineTable): void
    {
        if (Schema::hasTable($sTableName)) {
            return;
        }

        Schema::create($sTableName, $fnDefineTable);
    }
}
