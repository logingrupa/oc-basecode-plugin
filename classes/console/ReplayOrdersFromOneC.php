<?php namespace Lovata\BaseCode\Classes\Console;

use Illuminate\Console\Command;
use Kharanenka\Helper\Result;
use Lovata\BaseCode\Classes\Helper\OneC\AbstractHelper;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Re-run the retained 1C sale files through the order sync, oldest file first.
 */
class ReplayOrdersFromOneC extends Command
{
    /** @var string */
    protected $signature = 'basecode:1c.replay_orders
        {--order= : Sync only this order number}
        {--dir= : Directory with the 1C sale files, default storage/temp/1c/orders}';

    /** @var string */
    protected $description = 'Replay retained 1C sale files through the order sync';

    public function handle(): int
    {
        $sDirectory = (string) ($this->option('dir') ?: AbstractHelper::getFullPathDirectory(AbstractHelper::TYPE_SALE));
        $sOnlyOrder = (string) $this->option('order');

        $arFileList = glob(rtrim($sDirectory, '/' . DIRECTORY_SEPARATOR) . '/*.xml') ?: [];
        usort($arFileList, fn (string $sA, string $sB) => filemtime($sA) <=> filemtime($sB));

        if (empty($arFileList)) {
            $this->error('No xml files in ' . $sDirectory);

            return self::FAILURE;
        }

        $iSynced = 0;
        $iSkipped = 0;
        $iFailed = 0;

        foreach ($arFileList as $sFilePath) {
            $obXmlObject = ImportOrders::getXmlObject($sFilePath);
            $arDocumentList = empty($obXmlObject) ? [] : $obXmlObject->xpath(AbstractHelper::XML_PATH_ORDER_LIST);

            foreach ($arDocumentList as $obDocument) {
                $arData = ImportOrders::parseOrderDocument($obDocument);
                if (empty($arData) || ($sOnlyOrder !== '' && $arData['order_number'] !== $sOnlyOrder)) {
                    continue;
                }

                if (empty(Order::getByNumber($arData['order_number'])->first())) {
                    $iSkipped++;
                    $this->line(basename($sFilePath) . ' ' . $arData['order_number'] . ' not in shop, skipped');
                    continue;
                }

                Result::setTrue()->setMessage('');
                (new ParseOrderItemFromOneC())->process($arData);

                if (Result::status()) {
                    $iSynced++;
                    $this->line(basename($sFilePath) . ' ' . $arData['order_number'] . ' synced');
                } else {
                    $iFailed++;
                    $this->error(basename($sFilePath) . ' ' . $arData['order_number'] . ' FAILED: ' . Result::message());
                }
            }
        }

        $this->info(sprintf('files %d, synced %d, skipped %d, failed %d', count($arFileList), $iSynced, $iSkipped, $iFailed));

        return $iFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
