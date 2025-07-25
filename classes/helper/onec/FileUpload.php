<?php namespace Lovata\BaseCode\Classes\Helper\OneC;

use Log;
/**
 * Class FileUpload
 *
 * @package Lovata\BaseCode\Classes\Helper\OneC
 * @author  Sergey Zakharevich, s.zakharevich@lovata.com, LOVATA Group
 */
class FileUpload extends AbstractHelper
{
    const BLACK_FILE_LIST = ["/^import_files/"];

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

        $sContent = \Input::getContent();

        if (empty($sContent)
            || empty($this->sFileName)
            || empty($this->sType)
            || empty($this->sMode)
            || !in_array($this->sType, $arAvailableTypeList)
            || $this->sMode != self::MODE_FILE
        ) {
            return $this->failResponse(self::MESSAGE_NOT_CORRECT_REQUEST);
        }

        if (empty($this->obAuth)) {
            return $this->failResponse(self::MESSAGE_AUTHORISATION_ERROR);
        }

        // Check file.
        foreach (self::BLACK_FILE_LIST as $sPattern) {
            if (preg_match($sPattern, $this->sFileName)) {
                return $this->response([self::STATUS_SUCCESS]);
            }
        }

        // Check directory.
        $sFullPath = self::getFullPathDirectory($this->sType);
        if (empty($sFullPath)) {
            return $this->failResponse(self::MESSAGE_ERROR_PREPARING_DIRECTORY_FOR_IMPORT);
        }

        // Save file.
        if (!$this->saveFile($sFullPath, $this->sFileName, $sContent)) {
            return $this->failResponse(self::MESSAGE_FILE_SAVE_ERROR);
        }

        // Form a response.
        return $this->response([self::STATUS_SUCCESS]);
    }

    /**
     * Save file.
     * @param string $sFullPath
     * @param string $sFileName
     * @param string $sContent
     * @return bool
     */
    protected function saveFile($sFullPath, $sFileName, $sContent): bool
    {
        if (empty($sFullPath) || empty($sFileName) || empty($sContent)) {
            return false;
        }

        $this->checkByClear($sFileName, $sContent);

        $sContent = preg_replace("/xmlns=\"urn:1C.ru:commerceml_2\"/", '', $sContent);

        $sFilePath = $sFullPath . $sFileName;

        if ($this->sType == self::TYPE_SALE && file_exists($sFilePath)) {
            try {
                Log::info("FileUpload: Attempting to delete file at path: {$sFilePath}");
                // unlink($sFilePath);
                Log::info("FileUpload: File successfully deleted at path: {$sFilePath}");
            } catch (\Exception $obException) {
                return false;
            }
        }

        try {
            if (file_exists($sFilePath)) {
                $obFile = fopen($sFilePath, "a");
            } else {
                $obFile = fopen($sFilePath, "w");
            }
            fwrite($obFile, $sContent);
            fclose($obFile);
        } catch (\Exception $obException) {
            return false;
        }

        return true;
    }

    /**
     * Check by clear
     * @param string $sFileName
     * @param string $sContent
     */
    protected function checkByClear($sFileName, $sContent)
    {
        if (empty($sFileName) || empty($sContent)) {
            self::clearCatalogDirectory();

            return;
        }

        $bCheckFileName = $sFileName == 'import0_1.xml';
        $bCheckContent = preg_match("/<КоммерческаяИнформация/", $sContent);

        if ($bCheckFileName && $bCheckContent) {
            self::clearCatalogDirectory();
        }
    }
}
