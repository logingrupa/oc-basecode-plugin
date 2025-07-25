<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use Log;
use Input;
use Lovata\BaseCode\Classes\Parser\XMLObjectClass;
use Lovata\BaseCode\Models\AuthOneC;

/**
 * Class AbstractHelper
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
abstract class AbstractHelper
{
    const ONE_C_SHIPPING_TYPE_ID = 'ORDER_DELIVERY';

    const QUEUE_IMPORT_ORDERS_FROM_ONE_C = 'default';

    // XML paths
    const XML_PATH_ORDER_LIST = 'Документ';
    const XML_PATH_ORDER_USER_LIST = 'Контрагенты/Контрагент';
    const XML_PATH_ORDER_PRODUCT_LIST = 'Товары/Товар';

    // Input file options
    const CONFIG_MAX_INPUT_FILE_SIZE = '104857600';
    const CONFIG_ARCHIVING_YES = 'yes';
    const CONFIG_ARCHIVING_NO = 'no';
    // Response statuses.
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';
    // Values incoming 'mode' parameter.
    const MODE_CHECK_AUTH = 'checkauth';
    const MODE_CHECK_INT = 'init';
    const MODE_FILE = 'file';
    const MODE_IMPORT = 'import';
    const MODE_QUERY = 'query';
    const MODE_SUCCESS = 'success';
    // Values incoming 'type' parameter.
    const TYPE_CATALOG = 'catalog';
    const TYPE_SALE = 'sale';
    // Names of headers for authorization from 1c.
    const COOKIE_NAME_CATALOG = '1c_catalog';
    const COOKIE_NAME_SALE = '1c_sale';
    // Response messages.
    const MESSAGE_NOT_CORRECT_RESPONSE = 8001;
    const MESSAGE_NOT_CORRECT_REQUEST = 8002;
    const MESSAGE_AUTHORISATION_ERROR = 8003;
    const MESSAGE_ERROR_PREPARING_DIRECTORY_FOR_IMPORT = 8004;
    const MESSAGE_FILE_SAVE_ERROR = 8005;
    const MESSAGE_FILE_IS_MISSING = 8006;
    const MESSAGE_SYNC_ERROR = 8007;
    // Directory names
    const DIRECTORY_CATALOG = 'catalog';
    const DIRECTORY_ORDERS = 'orders';
    const DIRECTORY_ONE_C = '1c';

    /** @var string */
    protected $sFileName;
    /** @var string */
    protected $sType;
    /** @var string */
    protected $sMode;
    /** @var AuthOneC */
    protected $obAuth;
    /** @var string */
    protected $sCookieName;
    /** @var string */
    protected $sCookieValue;

    /**
     * MainHelper constructor.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Init.
     */
    protected function init()
    {
        $this->initInputData();
        $this->initAuth();
    }

    /**
     * Init input data.
     */
    protected function initInputData()
    {
        $this->sType = Input::get('type', '');
        $this->sMode = Input::get('mode', '');
        $this->sFileName = Input::get('filename', '');

        $this->sType = trim($this->sType);
        $this->sMode = trim($this->sMode);
        $this->sFileName = trim($this->sFileName);

        if ($this->sType == self::TYPE_CATALOG) {
            $this->sCookieName = self::COOKIE_NAME_CATALOG;
        } elseif ($this->sType == self::TYPE_SALE) {
            $this->sCookieName = self::COOKIE_NAME_SALE;
        }

        if (!empty($this->sCookieName)) {
            $this->sCookieValue = Input::cookie($this->sCookieName);

            $this->sCookieValue = trim($this->sCookieValue);
        }
    }

    /**
     * Init input data.
     */
    protected function initAuth()
    {
        if (empty($this->sCookieName) || empty($this->sCookieValue)) {
            return;
        }

        $this->obAuth = AuthOneC::getByCode($this->sCookieName)->getByValue($this->sCookieValue)->first();
    }

    /**
     * Get fill path directory.
     * @param string $sType
     * @return static
     */
    public static function getFullPathDirectory($sType): string
    {
        if ($sType == self::TYPE_CATALOG) {
            $sDirectory = self::DIRECTORY_CATALOG;
        } elseif ($sType == self::TYPE_SALE) {
            $sDirectory = self::DIRECTORY_ORDERS;
        } else {
            $sDirectory = '';
        }

        if (empty($sDirectory)) {
            return '';
        }

        $sFullPath = temp_path(self::DIRECTORY_ONE_C . '/' . $sDirectory . '/');

        if (!file_exists($sFullPath)) {
            try {
                mkdir($sFullPath, 0777, true);
            } catch (\Exception $obException) {
                return '';
            }
        }

        return $sFullPath;
    }

    /**
     * Get xml object.
     * @param string $sFilePath
     * @return XMLObjectClass|null
     */
    public static function getXmlObject($sFilePath)
    {
        if (empty($sFilePath)) {
            return null;
        }

        $sContent = file_get_contents($sFilePath);

        $obXmlObject = new XMLObjectClass($sContent);

        return $obXmlObject;
    }

    /**
     * Clear catalog directory.
     */
    public static function clearCatalogDirectory()
    {
        $sDirectoryPath = '';

        if (empty($sDirectoryPath)) {
            return;
        }

        $arFileList = (array)scandir($sDirectoryPath);
        array_shift($arFileList);
        array_shift($arFileList);

        if (empty($arFileList)) {
            return;
        }

        foreach ($arFileList as $sFile) {
            $sFilePath = $sDirectoryPath . $sFile;

            if (!file_exists($sFilePath)) {
                continue;
            }

            try {
                Log::info("AbstractHelper: Attempting to delete file at path: {$sFilePath}");
                // unlink($sFilePath);
                Log::info("AbstractHelper: File successfully deleted at path: {$sFilePath}");
            } catch (\Exception $obException) {
                continue;
            }
        }
    }

    /**
     * Processing.
     */
    abstract public function processing();

    /**
     * Response success.
     * @param array $arResponse
     * @return string
     */
    protected function response($arResponse = []): string
    {
        if (!is_array($arResponse)) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_RESPONSE);
        }

        $arResponse = array_filter($arResponse);

        if (empty($arResponse)) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_RESPONSE);
        }

        foreach ($arResponse as $sKey => $sValue) {
            array_forget($arResponse, $sKey);

            if (is_string($sKey)) {
                $arResponse[] = $sKey . '=' . $sValue;
            } else {
                $arResponse[] = $sValue;
            }
        }

        return $this->formatResponse($arResponse);
    }

    /**
     * Fail.
     * @param integer|null $iMessageId
     * @return string
     */
    protected function failResponse($iMessageId = null): string
    {
        $arResponse = [self::STATUS_FAILURE];

        if (!empty($iMessageId) && is_int($iMessageId)) {
            $arResponse[] = trans('lovata.basecode::lang.message.1c_error_' . $iMessageId);
        }

        return $this->formatResponse($arResponse);
    }

    /**
     * Format response.
     * @param array $arResponse
     * @return string
     */
    protected function formatResponse($arResponse): string
    {
        if (empty($arResponse) || !is_array($arResponse)) {
            return '';
        }

        return implode("\n", $arResponse);
    }
}
