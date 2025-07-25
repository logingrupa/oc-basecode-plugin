<?php namespace Lovata\BaseCode\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateOffersTable4
 * @package Lovata\BaseCode\Updates
 */
class UpdateOrdersShopaholicOrderTable04 extends Migration
{
    const TABLE_NAME = 'lovata_orders_shopaholic_orders';
    const COLUMNS = ['one_c_status_id'];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME) || Schema::hasColumns(self::TABLE_NAME, self::COLUMNS)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->integer('one_c_status_id')->nullable()->index();
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
