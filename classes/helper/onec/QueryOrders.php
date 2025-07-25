<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use Lovata\BaseCode\Classes\Helper\OneCParser\SiteOrderList;

/**
 * Class QueryOrders
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class QueryOrders extends AbstractHelper
{
    /**
     * Processing.
     * @return string
     */
    public function processing()
    {
        if (empty($this->sType)
            || empty($this->sMode)
            || $this->sType != self::TYPE_SALE
            || $this->sMode != self::MODE_QUERY
        ) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        // Form a response.
        $obSiteOrderList = new SiteOrderList();

        // Form a response.
        return $obSiteOrderList->get();
    }
}
