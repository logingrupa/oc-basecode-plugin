<?php namespace Lovata\BaseCode\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class CreateAuthOneCTable
 * @package Lovata\BaseCode\Updates
 */
class CreateAuthOneCTable extends Migration
{
    const TABLE = 'lovata_basecode_auth_one_c';

    /**
     * Up.
     */
    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable) {

            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->string('code');
            $obTable->string('value');
            $obTable->timestamps();

            $obTable->index(['code', 'value']);
        });
    }

    /**
     * Down.
     */
    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
