<?php

use Illuminate\Support\Facades\DB as Db;
use Illuminate\Support\Facades\Schema;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
use October\Rain\Database\Schema\Blueprint;
use System\Models\SettingModel;

/**
 * The full Shopaholic migration chain does not run on SQLite, so the order
 * tables the 1C sync touches are created as stubs with the columns the real
 * models read and write.
 */
abstract class BaseCodePluginTestCase extends PluginTestCase
{
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

    protected function fixtureDocument(string $sFileName): XMLObjectClass
    {
        $obXml = ImportOrders::getXmlObject(__DIR__.'/fixtures/'.$sFileName);
        $arDocumentList = $obXml->xpath(ImportOrders::XML_PATH_ORDER_LIST);

        $this->assertCount(1, $arDocumentList, 'fixture holds exactly one order document');

        return $arDocumentList[0];
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
