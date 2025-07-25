<?php namespace Lovata\BaseCode\Classes\Console;

use Illuminate\Console\Command;
use Lovata\BaseCode\Models\AuthOneC;
use October\Rain\Argon\Argon;

/**
 * Class ClearAuthOneC
 * @package Lovata\BaseCode\Classes\Console
 * @author Ilya Belyukov, i.beltyukov@lovata.com, LOVATA Group
 */
class ClearAuthOneC extends Command
{
    /**
     * @var string
     */
    protected $name = 'basecode:1c.clear_auth';

    /**
     * @var string
     */
    protected $description = 'Clear auth 1c';

    /**
     * Handler/
     * @throws \Exception
     */
    public function handle()
    {
        $obDate = clone Argon::now();
        $obDate->subDay();

        $obAuthList = AuthOneC::whereDate('created_at', '<=', $obDate)->get();

        if ($obAuthList->isEmpty()) {
            return;
        }

        foreach ($obAuthList as $obAuth) {
            $obAuth->delete();
        }
    }
}
