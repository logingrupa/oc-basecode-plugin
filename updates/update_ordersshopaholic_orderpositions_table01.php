<?php namespace Lovata\BaseCode\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateOrdersShopaholicOrderPositionsTable01
 * @package Lovata\BaseCode\Updates
 */
class UpdateOrdersShopaholicOrderPositionsTable01 extends Migration
{
    const TABLE_NAME = 'lovata_orders_shopaholic_order_positions';
    const COLUMNS = ['one_c_external_id'];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || Schema::hasColumns(self::TABLE_NAME, self::COLUMNS)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->string('one_c_external_id')->nullable();
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || !Schema::hasColumns(self::TABLE_NAME, self::COLUMNS)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->dropColumn(self::COLUMNS);
        });
    }
}
