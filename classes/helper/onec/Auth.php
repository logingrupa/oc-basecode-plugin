<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use Lovata\BaseCode\Models\AuthOneC;

/**
 * Class Auth
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class Auth extends AbstractHelper
{
    /**
     * Processing.
     * @return string
     */
    public function processing()
    {
        // Check input data.
        $arAvailableTypeList = [
            self::TYPE_CATALOG,
            self::TYPE_SALE,
        ];

        if (empty($this->sType)
            || empty($this->sMode)
            || !in_array($this->sType, $arAvailableTypeList)
            || $this->sMode != self::MODE_CHECK_AUTH
        ) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        // Form a response.
        if ($this->sType == self::TYPE_CATALOG) {
            $sCookieName = self::COOKIE_NAME_CATALOG;
        } elseif ($this->sType == self::TYPE_SALE) {
            $sCookieName = self::COOKIE_NAME_SALE;
        } else {
            $sCookieName = '';
        }

        $obAuth = AuthOneC::generate($sCookieName);

        if (empty($obAuth)) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_RESPONSE);
        }

        $sCookieName = $obAuth->code;
        $sCookieValue = $obAuth->value;

        $arResponse = [
            self::STATUS_SUCCESS,
            $sCookieName,
            $sCookieValue,
        ];

        return $this->response($arResponse);
    }
}
