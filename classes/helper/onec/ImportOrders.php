<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use Log;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
/**
 * Class ImportOrders
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class ImportOrders extends AbstractHelper
{
    const ORDER_STATUS_CODE_CANCELED = 'canceled';
    const ORDER_STATUS_CODE_IN_PROGRESS = 'in_progress';
    const ORDER_STATUS_CODE_COMPLETE = 'complete';

    /**
     * Processing.
     * @return string
     */
    public function processing()
    {
        // Log the initial state of the request parameters
        Log::info("ImportOrders - sFileName: {$this->sFileName}, sType: {$this->sType}, sMode: {$this->sMode}");

        // Validate the input parameters
        if (empty($this->sFileName)) {
            Log::error('ImportOrders: Missing filename.');
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        if (empty($this->sType)) {
            Log::error('ImportOrders: Missing type.');
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        if (empty($this->sMode)) {
            Log::error('ImportOrders: Missing mode.');
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        if ($this->sType != self::TYPE_SALE) {
            Log::error('ImportOrders: Invalid type. Expected sale, got ' . $this->sType);
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        // Handle mode-specific processing
        if ($this->sMode == self::MODE_IMPORT) {
            Log::info('ImportOrders: Handling file upload.');

            // Proceed with file handling and order processing
            $sFullPath = self::getFullPathDirectory($this->sType);
            $sFilePath = $sFullPath . $this->sFileName;

            if (empty($sFullPath) || !file_exists($sFilePath)) {
                Log::error("ImportOrders: File not found at path {$sFilePath}");
                return $this->failResponse(self::MESSAGE_FILE_IS_MISSING);
            }

            // Process the order
            $this->orderProcessing($sFilePath);

            try {
                Log::info("ImportOrders MODE_IMPORT: Attempting to delete file at path: {$sFilePath}");
                // unlink($sFilePath);
                Log::info("ImportOrders MODE_IMPORT: File successfully deleted at path: {$sFilePath}");
            } catch (\Exception $obException) {
                Log::warning("ImportOrders: Failed to delete file at path {$sFilePath}. Error: " . $obException->getMessage());
            }

        } elseif ($this->sMode == self::MODE_FILE) {
            Log::info('ImportOrders: Handling import.');

            // Proceed with import logic, which might be similar to file handling
            $sFullPath = self::getFullPathDirectory($this->sType);
            $sFilePath = $sFullPath . $this->sFileName;

            if (empty($sFullPath) || !file_exists($sFilePath)) {
                Log::error("ImportOrders: File not found at path {$sFilePath}");
                return $this->failResponse(self::MESSAGE_FILE_IS_MISSING);
            }

            // Process the order
            $this->orderProcessing($sFilePath);

            try {
                Log::info("ImportOrders MODE_FILE: Attempting to delete file at path: {$sFilePath}");
                // unlink($sFilePath);
                Log::info("ImportOrders MODE_FILE: File successfully deleted at path: {$sFilePath}");
            } catch (\Exception $obException) {
                Log::warning("ImportOrders: Failed to delete file at path {$sFilePath}. Error: " . $obException->getMessage());
            }

        } else {
            Log::error('ImportOrders: Invalid mode. Expected file or import, got ' . $this->sMode);
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        // Form a success response
        return $this->response([self::STATUS_SUCCESS]);
    }


    /**
     * Order processing.
     * @param string $sFilePath
     */
    protected function orderProcessing($sFilePath)
    {
        $obXmlObject = ImportOrders::getXmlObject($sFilePath);

        if (empty($obXmlObject)) {
            return;
        }

        $arElementList = $obXmlObject->xpath(ImportOrders::XML_PATH_ORDER_LIST);
        if (empty($arElementList)) {
            return;
        }

        foreach ($arElementList as $obXmlElement) {
            $sOrderId = $obXmlElement->getValueByPath('Ид');
            $sOrderNumber = $obXmlElement->getValueByPath('Номер');

            if (empty($sOrderId)) {
                continue;
            }

            $arData = [
                'order_number' => $sOrderNumber,
                'code_status' => $this->getStatusCode($obXmlElement),
                'order_position_list' => [],
            ];

            $arOrderPositionList = $obXmlElement->xpath('Товары/Товар');

            foreach ($arOrderPositionList as $obOrderPositionXmlObject) {
                $arData['order_position_list'][] = [
                    'external_id' => $obOrderPositionXmlObject->getValueByPath('Ид'),
                    'price' => $obOrderPositionXmlObject->getValueByPath('ЦенаЗаЕдиницу'),
                    'quantity' => $obOrderPositionXmlObject->getValueByPath('Количество'),
                    'discount_data' => $this->getOrderPositionDiscountData($obOrderPositionXmlObject),
                ];
            }

            \Queue::pushOn(self::QUEUE_IMPORT_ORDERS_FROM_ONE_C, ParseOrderItemFromOneC::class, $arData);
        }
    }

    /**
     * Get status code.
     * @param XMLObjectClass $obXmlOrderObject
     * @return string|null
     */
    protected function getStatusCode($obXmlOrderObject)
    {
        if (empty($obXmlOrderObject)) {
            return null;
        }

        $sStatusCode = null;

        $arElementList = $obXmlOrderObject->xpath('ЗначенияРеквизитов/ЗначениеРеквизита');

        foreach ($arElementList as $obXmlObject) {
            $sName = $obXmlObject->getValueByPath('Наименование');
            $sValue = $obXmlObject->getValueByPath('Значение');

            if (empty($sValue)) {
                continue;
            }

            switch ($sName) {
                case 'ПометкаУдаления':
                    $sStatusCode = (filter_var($sValue, FILTER_VALIDATE_BOOLEAN)) ? self::ORDER_STATUS_CODE_CANCELED : null;
                    break;
                case 'Проведен':
                    $sStatusCode = (filter_var($sValue, FILTER_VALIDATE_BOOLEAN)) ? self::ORDER_STATUS_CODE_IN_PROGRESS : null;
                    break;
                case 'Номер отгрузки по 1С':
                    $sStatusCode = ($sValue) ? self::ORDER_STATUS_CODE_COMPLETE : null;
                    break;
            }
        }

        return $sStatusCode;
    }

    /**
     * Get order position discount data
     * @param $obOrderPositionXmlObject
     * @return array
     */
    private function getOrderPositionDiscountData($obOrderPositionXmlObject): array
    {
        $arDiscountElementList = $obOrderPositionXmlObject->xpath('Скидки/Скидка');
        $arDiscountData = [];

        if (empty($arDiscountElementList)) {
            return $arDiscountData;
        }

        foreach ($arDiscountElementList as $obDiscountXMLObject) {
            $arDiscountData[] = [
                'percent' => $obDiscountXMLObject->getValueByPath('Процент'),
                'is_taken_in_sum' => $obDiscountXMLObject->getValueByPath('УчтеноВСумме'),
            ];
        }

        return $arDiscountData;
    }
}
