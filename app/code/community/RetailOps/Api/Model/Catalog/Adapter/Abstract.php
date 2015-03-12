<?php
/**
{license_text}
 */

abstract class RetailOps_Api_Model_Catalog_Adapter_Abstract
{
    protected $_section     = 'general';
    protected $_errorCodes  = array();
    /** @var RetailOps_Api_Model_Catalog_Api */
    protected $_api;

    public function __construct($api)
    {
        $this->_api = $api;
        $this->_construct();
    }

    /**
     * @return RetailOps_Api_Model_Resource_Api
     */
    protected function _getResource()
    {
        return Mage::getResourceModel('retailops_api/api');
    }

    /**
     * @return RetailOps_Api_Helper_Data
     */
    public function getHelper()
    {
        return Mage::helper('retailops_api');
    }

    /**
     * @return $this
     */
    protected function _construct()
    {
        return $this;
    }

    /**
     * @param $message
     * @param string $code
     * @param null $sku
     * @throws RetailOps_Api_Model_Catalog_Exception
     */
    protected function _throwException($message, $code, $sku = null)
    {
        if (isset($this->_errorCodes[$code])) {
            $code = $this->_errorCodes[$code];
        }
        throw new RetailOps_Api_Model_Catalog_Exception($message, $code, $sku, $this->_section);
    }

    /**
     * Will be called before preparing
     *
     * @return $this
     */
    public function beforeDataPrepare()
    {
        return $this;
    }

    /**
     * @param array $data
     * @return $this
     */
    public function prepareData(array &$data)
    {
        return $this;
    }

    /**
     * Will be called when all rows prepared
     *
     * @return $this
     */
    public function afterDataPrepare()
    {
        return $this;
    }

    /**
     * @return $this
     */
    public function beforeDataProcess()
    {
        return $this;
    }

    /**
     * @param array $productData
     * @param $product
     * @return mixed
     */
    abstract public function processData(array &$productData, $product);

    /**
     * @param array $skuToIdMap
     * @return $this
     */
    public function afterDataProcess(array &$skuToIdMap)
    {
        return $this;
    }

    /**
     * Prepare data for pull api
     *
     * @param $productCollection
     * @return $this
     */
    public function prepareOutputData($productCollection)
    {
        return $this;
    }

    /**
     * Output data for pull api
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    public function outputData($product)
    {
        return array();
    }
}
