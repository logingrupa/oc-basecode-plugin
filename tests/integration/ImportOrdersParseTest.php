<?php

require_once __DIR__.'/../BaseCodePluginTestCase.php';

use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;

/**
 * The sync payload carries, per 1C line, the list price and the line total
 * the customer was charged. Fixture: the real 1C export of .lv order
 * 260907-0010 with the buyer renamed.
 */
class ImportOrdersParseTest extends BaseCodePluginTestCase
{
    public function testPayloadCarriesListPriceQuantityAndChargedTotalPerLine(): void
    {
        $arData = ImportOrders::parseOrderDocument($this->fixtureDocument('order-260907-0010.xml'));

        $this->assertSame('260907-0010', $arData['order_number']);
        $this->assertSame(ImportOrders::ORDER_STATUS_CODE_COMPLETE, $arData['code_status'], 'shipment number present = complete');
        $this->assertCount(6, $arData['order_position_list'], 'five goods lines and the delivery line');

        $arPrimer = $arData['order_position_list'][0];
        $this->assertSame('b5a39dbf-7480-11e3-806d-00138f293d96#af657c3c-7474-11f1-8b15-cc5ef85a3bbc', $arPrimer['external_id']);
        $this->assertSame('12.72', $arPrimer['price']);
        $this->assertSame('1', $arPrimer['quantity']);
        $this->assertSame('8.9', $arPrimer['total']);

        $arDelivery = $arData['order_position_list'][5];
        $this->assertSame('09f78ef6-de0a-11ea-ab9f-68ecc5c29a9c', $arDelivery['external_id']);
        $this->assertSame('4', $arDelivery['total']);
    }
}
