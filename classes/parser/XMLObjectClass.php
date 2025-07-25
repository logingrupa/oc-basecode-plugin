<?php namespace Lovata\BaseCode\Classes\Parser;

/**
 * Class XMLObjectClass
 * @package Lovata\BaseCode\Classes\Parser
 */
class XMLObjectClass extends \SimpleXMLElement
{
    /**
     * Get attribute value
     * @param string $sAttributeName
     * @return string
     */
    public function attr($sAttributeName): string
    {
        if (empty($sAttributeName) || !isset($this[$sAttributeName])) {
            return '';
        }

        return (string)$this[$sAttributeName];
    }

    /**
     * @param \SimpleXMLElement $obNode
     * @param string $sFieldPath
     * @return string|null|array
     */
    public function getValueByPath($sFieldPath)
    {
        if (empty($sFieldPath)) {
            return null;
        }

        $arValueNodeList = $this->xpath($sFieldPath);
        if (empty($arValueNodeList)) {
            return null;
        }

        $arResult = [];
        foreach ($arValueNodeList as $obValueNode) {
            $arResult[] = (string)$obValueNode;
        }

        if (count($arResult) == 1) {
            return array_shift($arResult);
        } elseif (empty($arResult)) {
            return null;
        }

        return $arResult;
    }
}
