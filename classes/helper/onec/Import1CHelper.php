<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use October\Rain\Support\Traits\Singleton;

/**
 * Class Import1CHelper
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class Import1CHelper
{
    use Singleton;

    /**
     * @var bool
     */
    protected $bStatus = false;

    /**
     * Set true statues.
     */
    public function setTrueStatus()
    {
        $this->bStatus = true;
    }

    /**
     * Set false status.
     */
    public function setFalseStatus()
    {
        $this->bStatus = false;
    }

    /**
     * @return bool
     */
    public function status(): bool
    {
        return $this->bStatus;
    }
}
