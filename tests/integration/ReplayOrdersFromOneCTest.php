<?php

require_once __DIR__.'/../BaseCodePluginTestCase.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB as Db;
use Illuminate\Support\Facades\Event;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * The replay applies only the latest 1C export of each order and wakes no
 * order model listener: on prod the first replay ran an older export first,
 * moved the status back and forward, and the Meta Purchase watcher fired.
 */
class ReplayOrdersFromOneCTest extends BaseCodePluginTestCase
{
    /** @var string */
    private $sDirectory;

    public function setUp(): void
    {
        parent::setUp();

        $this->sDirectory = sys_get_temp_dir().'/basecode-replay-'.uniqid();
        mkdir($this->sDirectory);

        // Older export of the same order: shipped attributes not there yet, 1C status "in progress".
        $sOlder = preg_replace('~<ЗначениеРеквизита>\s*<Наименование>(Номер|Дата) отгрузки по 1С</Наименование>.*?</ЗначениеРеквизита>~su', '', file_get_contents($this->fixturePath(self::FIXTURE_ORDER)));
        $this->assertStringNotContainsString('PRO035378', $sOlder);
        file_put_contents($this->sDirectory.'/1cbitrix-older.xml', $sOlder);
        touch($this->sDirectory.'/1cbitrix-older.xml', time() - 7200);

        copy($this->fixturePath(self::FIXTURE_ORDER), $this->sDirectory.'/1cbitrix-newer.xml');
        touch($this->sDirectory.'/1cbitrix-newer.xml', time() - 3600);
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->sDirectory.'/*.xml'));
        rmdir($this->sDirectory);

        parent::tearDown();
    }

    public function testOnlyTheLatestExportIsAppliedAndOrderListenersStayQuiet(): void
    {
        $iOrderID = $this->seedCheckoutOrder();
        $iUpdatedEvents = 0;
        Event::listen('eloquent.updated: '.Order::class, function () use (&$iUpdatedEvents) {
            $iUpdatedEvents++;
        });

        $iExit = Artisan::call('basecode:1c.replay_orders', ['--dir' => $this->sDirectory]);
        $sOutput = Artisan::output();

        $this->assertSame(0, $iExit, $sOutput);
        $this->assertStringContainsString('1cbitrix-newer.xml 260907-0010 synced', $sOutput);
        $this->assertStringNotContainsString('1cbitrix-older.xml', $sOutput, 'the older export is never applied');
        $this->assertStringContainsString('files 2, orders 1, synced 1, skipped 0, failed 0', $sOutput);
        $this->assertSame(3, (int) Db::table('lovata_orders_shopaholic_orders')->where('id', $iOrderID)->value('status_id'), 'complete, never moved back to in progress');
        $this->assertSame(0, $iUpdatedEvents, 'order model listeners are muted during the replay');
        $this->assertSame(49.21, round(Order::find($iOrderID)->total_price_value, 2));
    }

    public function testOrderFilterSkipsUnknownOrdersWithoutFailing(): void
    {
        $iExit = Artisan::call('basecode:1c.replay_orders', ['--dir' => $this->sDirectory, '--order' => '260907-0010']);
        $sOutput = Artisan::output();

        $this->assertSame(0, $iExit, $sOutput);
        $this->assertStringContainsString('260907-0010 not in shop, skipped', $sOutput);
        $this->assertStringContainsString('orders 1, synced 0, skipped 1, failed 0', $sOutput);
    }
}
