<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

/**
 * Class Init
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class Init extends AbstractHelper
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
            || $this->sMode != self::MODE_CHECK_INT
        ) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        if (empty($this->obAuth)) {
            return $this->failResponse(self::MESSAGE_AUTHORISATION_ERROR);
        }

        // Form a response.
        $arResponse = [
            'zip' => self::CONFIG_ARCHIVING_NO,
            'file_limit' => self::CONFIG_MAX_INPUT_FILE_SIZE,
        ];

        return $this->response($arResponse);
    }
}
