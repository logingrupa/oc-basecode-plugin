<?php namespace Lovata\BaseCode\Classes\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Kharanenka\Helper\Result;
use Lovata\BaseCode\Classes\Helper\OneC\AbstractHelper;
use Lovata\BaseCode\Classes\Helper\OneC\ImportOrders;
use Lovata\BaseCode\Classes\Queue\ParseOrderItemFromOneC;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Re-run the retained 1C sale files through the order sync. Only the latest
 * export of each order is applied, older exports would move the status backwards.
 */
class ReplayOrdersFromOneC extends Command
{
    /** @var string */
    protected $signature = 'basecode:1c.replay_orders
        {--order= : Sync only this order number}
        {--dir= : Directory with the 1C sale files, default storage/temp/1c/orders}';

    /** @var string */
    protected $description = 'Replay retained 1C sale files through the order sync, latest export per order';

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

        // A replayed export is not a new purchase, order model listeners (Meta Purchase) stay quiet here.
        Event::forget('eloquent.created: ' . Order::class);
        Event::forget('eloquent.updated: ' . Order::class);

        $arLatestDocumentList = $this->collectLatestDocuments($arFileList, $sOnlyOrder);

        $iSynced = 0;
        $iSkipped = 0;
        $iFailed = 0;

        foreach ($arLatestDocumentList as $sOrderNumber => [$sFileName, $arData]) {
            if (empty(Order::getByNumber($sOrderNumber)->first())) {
                $iSkipped++;
                $this->line($sFileName . ' ' . $sOrderNumber . ' not in shop, skipped');
                continue;
            }

            Result::setTrue()->setMessage('');
            (new ParseOrderItemFromOneC())->process($arData);

            if (Result::status()) {
                $iSynced++;
                $this->line($sFileName . ' ' . $sOrderNumber . ' synced');
            } else {
                $iFailed++;
                $this->error($sFileName . ' ' . $sOrderNumber . ' FAILED: ' . Result::message());
            }
        }

        $this->info(sprintf('files %d, orders %d, synced %d, skipped %d, failed %d', count($arFileList), count($arLatestDocumentList), $iSynced, $iSkipped, $iFailed));

        return $iFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Latest payload per order number, files are already sorted oldest first.
     * @param string[] $arFileList
     * @param string $sOnlyOrder
     * @return array order number => [file name, payload]
     */
    private function collectLatestDocuments(array $arFileList, string $sOnlyOrder): array
    {
        $arResult = [];

        foreach ($arFileList as $sFilePath) {
            $obXmlObject = ImportOrders::getXmlObject($sFilePath);
            $arDocumentList = empty($obXmlObject) ? [] : $obXmlObject->xpath(AbstractHelper::XML_PATH_ORDER_LIST);

            foreach ($arDocumentList as $obDocument) {
                $arData = ImportOrders::parseOrderDocument($obDocument);
                if (empty($arData) || ($sOnlyOrder !== '' && $arData['order_number'] !== $sOnlyOrder)) {
                    continue;
                }

                $arResult[$arData['order_number']] = [basename($sFilePath), $arData];
            }
        }

        return $arResult;
    }
}
