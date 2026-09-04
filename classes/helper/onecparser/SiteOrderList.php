<?php namespace Lovata\BaseCode\Classes\Helper\OneCParser;

use Lovata\BaseCode\Classes\Event\Order\OrderModelHandler;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\BaseCode\Models\Settings;
use Carbon\Carbon;
use XMLWriter;

/**
 * Class SiteOrderList
 *
 * @package Lovata\BaseCode\Classes\Helper\OneCParser
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class SiteOrderList
{
    const CACHE_KEY = 'orders_one_s';

    /** Generated content */
    protected $sContent = '';
    /** @var XMLWriter */
    protected $obXMLWriter;
    /** @var array */
    protected $arCacheOrderIdList = [];

    /**
     * Get.
     * @return string
     */
    public function get(): string
    {
        Settings::set('order_id_lis_one_s', []);

        $this->processing();

        Settings::set('order_id_lis_one_s', $this->arCacheOrderIdList);

        return $this->sContent;
    }

    /**
     * Execute the console command.
     */
    public function processing()
    {
        $obOrderList = Order::with(['user', 'order_position', 'payment_method', 'shipping_type', 'status'])
            ->where('one_c_status_id', OrderModelHandler::ONE_C_STATUS_NEW)
            ->get();

        $this->obXMLWriter = new XMLWriter();

        $this->start();

        foreach ($obOrderList as $obOrder) {
            $this->parseOrder($obOrder);
        }

        $this->stop();
    }

    /**
     * Start xml content generation
     */
    protected function start()
    {
        $this->obXMLWriter->openMemory();
        $this->obXMLWriter->setIndent(1);

        $this->obXMLWriter->startDocument('1.0', 'UTF-8');
        $this->obXMLWriter->startElement('КоммерческаяИнформация');
        $this->obXMLWriter->writeAttribute('ВерсияСхемы', '2.05');
        $this->obXMLWriter->writeAttribute('ДатаФормирования', Carbon::now()->format('Y-m-d H:i:s'));
        $this->obXMLWriter->writeAttribute('ФорматДаты', 'ДФ=yyyy-MM-dd; ДЛФ=DT');
        $this->obXMLWriter->writeAttribute('ФорматВремени', 'ДФ=ЧЧ:мм:сс; ДЛФ=T');
        $this->obXMLWriter->writeAttribute('РазделительДатаВремя', ' ');
        $this->obXMLWriter->writeAttribute('ФорматСуммы', 'ЧЦ=18; ЧДЦ=2; ЧРД=.');
        $this->obXMLWriter->writeAttribute('ФорматКоличества', 'ЧЦ=18; ЧДЦ=2; ЧРД=.');
    }

    /**
     * Parse order
     * @param Order $obOrder
     */
    protected function parseOrder(Order $obOrder)
    {
        $obUser = $obOrder->user;
        if ($obOrder->order_position->isEmpty()) {
            return;
        }
        if (!empty($obUser)) {
            $obUser = $obOrder->user;
        } else {

        }


        // # Документ.
        $this->obXMLWriter->startElement('Документ'); // # Документ.

        $this->obXMLWriter->writeElement('Ид', $obOrder->id);
        $this->obXMLWriter->writeElement('Номер', $obOrder->order_number);
        $this->obXMLWriter->writeElement('Дата', $obOrder->created_at->format('Y-m-d'));
        $this->obXMLWriter->writeElement('ХозОперация', 'Заказ товара');
        $this->obXMLWriter->writeElement('Роль', 'Продавец');
        if (!empty($obOrder->currency)) {
            $this->obXMLWriter->writeElement('Валюта', $obOrder->currency->code);
        }
        $this->obXMLWriter->writeElement('Сумма', number_format($obOrder->total_price_value, 2, '.', ''));
        $this->obXMLWriter->writeElement('Время', $obOrder->created_at->format('H:i:s'));

        // ## Контрагенты.
       
            $this->obXMLWriter->startElement('Контрагенты'); // ## Контрагенты.

            $this->obXMLWriter->startElement('Контрагент'); // ### Контрагент.
        // Sometimes user does not agree to CREATE AN ACCOUNT, in that case, user name and last_name is empty 
        // So we check is that is the case, if person is now willing to register we take values form $obOrder->property
        // name and last name - so 1C does not throw error 
        if (!empty($obUser->name)) {
            $this->obXMLWriter->writeElement('Ид', $obUser->id);
            $this->obXMLWriter->writeElement('Наименование', trim($obUser->last_name . ' ' . $obUser->name . ' ' . $obUser->middle_name));
            $this->obXMLWriter->writeElement('ПолноеНаименование', trim($obUser->last_name . ' ' . $obUser->name . ' ' . $obUser->middle_name));
            $this->obXMLWriter->writeElement('Имя', $obUser->name);
            $this->obXMLWriter->writeElement('Роль', 'Покупатель');
        } else {
            $this->obXMLWriter->writeElement('Ид', $obUser->id);
            $this->obXMLWriter->writeElement('Наименование', trim(array_get($obOrder->property, 'last_name', '') . ' ' .array_get($obOrder->property, 'name', '') . ' ' . array_get($obOrder->property, 'middle_name', '')));
            $this->obXMLWriter->writeElement('ПолноеНаименование', trim(array_get($obOrder->property, 'last_name', '') . ' ' .array_get($obOrder->property, 'name', '') . ' ' . array_get($obOrder->property, 'middle_name', '')));
            $this->obXMLWriter->writeElement('Имя', trim(array_get($obOrder->property, 'name', '')));
            $this->obXMLWriter->writeElement('Роль', 'Покупатель');
        }
            // #### АдресРегистрации.
            $arShippingAddress1 = [
                array_get($obOrder->property, 'shipping_address1', ''),
                array_get($obOrder->property, 'shipping_address2', ''),
                array_get($obOrder->property, 'shipping_postcode', ''),
                array_get($obOrder->property, 'shipping_city', ''),
                array_get($obOrder->property, 'shipping_state', ''),
                array_get($obOrder->property, 'shipping_country', ''),
            ];
            foreach ($arShippingAddress1 as $sKey => $sItem) {
                if (empty($sItem)) {
                    unset($arShippingAddress1[$sKey]);
                }
            }
            $sShippingAddress1 = implode(', ', $arShippingAddress1);
            $sShippingAddressImploded = implode(', ', $arShippingAddress1);
            $r = array('undefined', 'NaN');
            $w = array('', '');
            $sShippingAddress = str_replace($r, $w, $sShippingAddressImploded);

            $this->obXMLWriter->startElement('АдресРегистрации'); // #### АдресРегистрации.
            $this->obXMLWriter->writeElement('Представление', $sShippingAddress);

            // ##### АдресноеПоле.
            $this->obXMLWriter->startElement('АдресноеПоле'); // ##### АдресноеПоле.
            $this->obXMLWriter->writeElement('Тип', 'Страна');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'country'));
            $this->obXMLWriter->endElement(); // ##### /АдресноеПоле.

            $this->obXMLWriter->startElement('АдресноеПоле'); // ##### АдресноеПоле.
            $this->obXMLWriter->writeElement('Тип', 'Регион');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'state'));
            $this->obXMLWriter->endElement(); // ##### /АдресноеПоле.

            $this->obXMLWriter->startElement('АдресноеПоле'); // ##### АдресноеПоле.
            $this->obXMLWriter->writeElement('Тип', 'Улица');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'shipping_address1'));
            $this->obXMLWriter->endElement(); // ##### /АдресноеПоле.

            $this->obXMLWriter->startElement('АдресноеПоле'); // ##### АдресноеПоле.

            $this->obXMLWriter->writeElement('Тип', 'Адрес доставки 1');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'shipping_address1', ''));
            $this->obXMLWriter->endElement(); // ##### /АдресноеПоле.

            $this->obXMLWriter->startElement('АдресноеПоле'); // ##### АдресноеПоле.
            $this->obXMLWriter->writeElement('Тип', 'Адрес доставки 2');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'shipping_address2'));
            $this->obXMLWriter->endElement(); // ##### /АдресноеПоле.

            $this->obXMLWriter->endElement(); // #### /АдресРегистрации.

            // #### Контакты.
            $this->obXMLWriter->startElement('Контакты'); // #### Контакты.
            $this->obXMLWriter->startElement('Контакт'); // ##### Контакт.
            $this->obXMLWriter->writeElement('Тип', 'ТелефонРабочий');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'phone'));
            $this->obXMLWriter->endElement(); // ##### /Контакт.
            $this->obXMLWriter->startElement('Контакт'); // ##### Контакт.
            $this->obXMLWriter->writeElement('Тип', 'Почта');
            $this->obXMLWriter->writeElement('Значение', array_get($obOrder->property, 'email'));
            $this->obXMLWriter->endElement(); // ##### /Контакт.
            $this->obXMLWriter->endElement(); // #### /Контакты.

            $this->obXMLWriter->endElement(); // ### /Контрагент.

            $this->obXMLWriter->endElement(); // ## /Контрагенты.
      

        if (!empty($obOrder->total_price_data)) {
            $this->obXMLWriter->startElement('Налоги'); // ## Налоги.
            $this->obXMLWriter->startElement('Налог'); // ### Налог.
            $this->obXMLWriter->writeElement('Наименование', 'НДС');
            $this->obXMLWriter->writeElement('УчтеноВСумме', 'true');
            $this->obXMLWriter->writeElement('Сумма', number_format($obOrder->total_price_data->tax_price_value, 2, '.', ''));
            $this->obXMLWriter->endElement(); // ### /Налог.
            $this->obXMLWriter->endElement(); // ## /Налоги.
        }

        // ## Товары.
        $this->obXMLWriter->startElement('Товары'); // ## Товары.
        foreach ($obOrder->order_position as $obOrderPosition) {
            $this->parseOrderPosition($obOrderPosition);
        }
        // Доставка.
        $this->prepareShippingType($obOrder);
        $this->obXMLWriter->endElement(); // ## /Товары.

        // ## ЗначенияРеквизитов.
        $this->obXMLWriter->startElement('ЗначенияРеквизитов'); // ## ЗначенияРеквизитов.

        if (!empty($obOrder->shipping_type)) {
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Адрес доставки');
            $this->obXMLWriter->writeElement('Значение', $sShippingAddress);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Способ доставки');
            $this->obXMLWriter->writeElement('Значение', $sShippingAddress);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Способ доставки ИД');
            $this->obXMLWriter->writeElement('Значение', $obOrder->shipping_type->id);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Способ доставки КОД');
            $this->obXMLWriter->writeElement('Значение', $obOrder->shipping_type->code);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
        }
        if (!empty($obOrder->payment_method)) {
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Метод оплаты');
            $this->obXMLWriter->writeElement('Значение', $obOrder->payment_method->name);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Метод оплаты ИД');
            $this->obXMLWriter->writeElement('Значение', $obOrder->payment_method->id);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
        }
        if (!empty($obOrder->status)) {
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Статус');
            $this->obXMLWriter->writeElement('Значение', $obOrder->status->name);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
            $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
            $this->obXMLWriter->writeElement('Наименование', 'Статус КОД');
            $this->obXMLWriter->writeElement('Значение', $obOrder->status->code);
            $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
        }

        $this->obXMLWriter->endElement(); // ## /ЗначенияРеквизитов.

        $this->obXMLWriter->endElement(); // # /Документ.

        $this->arCacheOrderIdList[] = $obOrder->id;
    }

    /**
     * Parse order position.
     * @param OrderPosition $obOrderPosition
     */
    protected function parseOrderPosition(OrderPosition $obOrderPosition)
    {
        $sOfferName = !empty($obOrderPosition->offer) ? $obOrderPosition->offer->name : '';

        $this->obXMLWriter->startElement('Товар'); // # Товар.

        $this->obXMLWriter->writeElement('Ид', $obOrderPosition->one_c_external_id);
        $this->obXMLWriter->writeElement('Наименование', $sOfferName);
        $this->obXMLWriter->startElement('БазоваяЕдиница'); // ## БазоваяЕдиница.
        $this->obXMLWriter->writeAttribute('Код', '796');
        $this->obXMLWriter->writeAttribute('НаименованиеПолное', 'Штука');
        $this->obXMLWriter->writeAttribute('МеждународноеСокращение', 'PCE');
        $this->obXMLWriter->writeRaw('шт');
        $this->obXMLWriter->endElement(); // ## /БазоваяЕдиница.
        $this->obXMLWriter->writeElement('ЦенаЗаЕдиницу', $this->getOrderPositionPriceWithTax($obOrderPosition));
        $this->obXMLWriter->writeElement('Количество', $obOrderPosition->quantity);
        $this->obXMLWriter->writeElement('Сумма', number_format($obOrderPosition->total_price_value, 2, '.', ''));

        $this->obXMLWriter->startElement('ЗначенияРеквизитов'); // ## ЗначенияРеквизитов.

        $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
        $this->obXMLWriter->writeElement('Наименование', 'ВидНоменклатуры');
        $this->obXMLWriter->writeElement('Значение', 'Товар');
        $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
        $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
        $this->obXMLWriter->writeElement('Наименование', 'ТипНоменклатуры');
        $this->obXMLWriter->writeElement('Значение', 'Товар');
        $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.

        $this->obXMLWriter->endElement(); // ## /ЗначенияРеквизитов.

        $this->obXMLWriter->startElement('Налоги'); // ## Налоги.
        $this->obXMLWriter->startElement('Налог'); // ### Налог.
        $this->obXMLWriter->writeElement('Наименование', 'НДС');
        $this->obXMLWriter->writeElement('УчтеноВСумме', 'true');
        $this->obXMLWriter->writeElement('Сумма', number_format($obOrderPosition->tax_price_value, 2, '.', ''));
        $this->obXMLWriter->writeElement('Ставка', number_format($obOrderPosition->tax_percent, 0));
        $this->obXMLWriter->endElement(); // ### /Налог.
        $this->obXMLWriter->endElement(); // ## /Налоги.

        $this->obXMLWriter->startElement('Скидки'); // ## Скидки.
        $this->obXMLWriter->startElement('Скидка'); // ## Скидка.
        $this->obXMLWriter->writeElement('Сумма', $obOrderPosition->discount_price_value * $obOrderPosition->quantity);
        $this->obXMLWriter->writeElement('УчтеноВСумме', 'true');
        $this->obXMLWriter->endElement(); // ### /Скидка.
        $this->obXMLWriter->endElement(); // ## /Скидки.

        $this->obXMLWriter->endElement(); // # /Товар.
    }

    /**
     * Get order position price value with tax
     * @param OrderPosition $obOrderPosition
     * @return float
     */
    protected function getOrderPositionPriceWithTax(OrderPosition $obOrderPosition): float
    {
        if ($obOrderPosition->old_price_with_tax_value > $obOrderPosition->price_with_tax_value) {
            return $obOrderPosition->old_price_with_tax_value;
        }

        return $obOrderPosition->price_with_tax_value;
    }

    /**
     * Parse shipping type position.
     * @param $obOrder $obOrder
     */
    protected function prepareShippingType(Order $obOrder)
    {
        if ($obOrder->shipping_price_value == 0) {
            return;
        }

        $sShippingName = !empty($obOrder->shipping_type) ? $obOrder->shipping_type->name : '';
        $sShippingExternalId = !empty($obOrder->shipping_type) ? $obOrder->shipping_type->external_id : '';

        $this->obXMLWriter->startElement('Товар'); // # Товар.

        $this->obXMLWriter->writeElement('Ид', $sShippingExternalId);
        $this->obXMLWriter->writeElement('Наименование', $sShippingName);

        if (!empty($obOrder->shipping_price_data)) {
            $this->obXMLWriter->writeElement('ЦенаЗаЕдиницу', $obOrder->shipping_price_value);

            $this->obXMLWriter->startElement('Налоги'); // ## Налоги.
            $this->obXMLWriter->startElement('Налог'); // ### Налог.
            $this->obXMLWriter->writeElement('Наименование', 'НДС');
            $this->obXMLWriter->writeElement('УчтеноВСумме', 'true');
            $this->obXMLWriter->writeElement('Сумма', $obOrder->shipping_price_data->tax_price_value);
            $this->obXMLWriter->writeElement('Ставка', number_format($obOrder->shipping_price_data->tax_percent, 0));
            $this->obXMLWriter->endElement(); // ### /Налог.
            $this->obXMLWriter->endElement(); // ## /Налоги.
        }

        $this->obXMLWriter->writeElement('Количество', 1);
        $this->obXMLWriter->writeElement('Сумма', $obOrder->shipping_price_value);

        $this->obXMLWriter->startElement('ЗначенияРеквизитов'); // ## ЗначенияРеквизитов.

        $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
        $this->obXMLWriter->writeElement('Наименование', 'ВидНоменклатуры');
        $this->obXMLWriter->writeElement('Значение', 'Доставка заказа');
        $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.
        $this->obXMLWriter->startElement('ЗначениеРеквизита'); // ### ЗначениеРеквизита.
        $this->obXMLWriter->writeElement('Наименование', 'ТипНоменклатуры');
        $this->obXMLWriter->writeElement('Значение', 'Доставка заказа');
        $this->obXMLWriter->endElement(); // ### /ЗначениеРеквизита.

        $this->obXMLWriter->endElement(); // ## /ЗначенияРеквизитов.

        $this->obXMLWriter->endElement(); // # /Товар.
    }

    /**
     * End xml content generation
     */
    protected function stop()
    {
        $this->obXMLWriter->endElement(); // urlset
        $this->obXMLWriter->endDocument();

        $this->sContent = $this->obXMLWriter->outputMemory();
    }
}
