<?php
/**
{license_text}
 */

class RetailOps_Api_Model_Catalog_Api extends Mage_Catalog_Model_Product_Api
{
    /** @var  array */
    protected $_dataAdapters;

    /**
    * Constructor. Initializes default values.
    */
    public function __construct()
    {
        $this->_dataAdapters = array(
            'attributes'   => new RetailOps_Api_Model_Catalog_Adapter_Attribute($this),
            'category'     => new RetailOps_Api_Model_Catalog_Adapter_Category($this),
            'media'        => new RetailOps_Api_Model_Catalog_Adapter_Media($this),
            'option'       => new RetailOps_Api_Model_Catalog_Adapter_Option($this),
            'configurable' => new RetailOps_Api_Model_Catalog_Adapter_Configurable($this),
            'tag'          => new RetailOps_Api_Model_Catalog_Adapter_Tag($this),
            'default'      => new RetailOps_Api_Model_Catalog_Adapter_Default($this),
            'link'         => new RetailOps_Api_Model_Catalog_Adapter_Link($this),
        );
        Mage::dispatchEvent('retailops_catalog_adapter_init_after', array('api' => $this));
    }

    /**
     * @param $code
     * @return bool
     */
    public function getAdapter($code)
    {
        if (isset($this->_dataAdapters[$code])) {
            return $this->_dataAdapters[$code];
        }

        return false;
    }

    /**
     * @param $code
     * @param $adapter
     */
    public function addAdapter($code, $adapter)
    {
        if (!($adapter instanceof RetailOps_Api_Model_Catalog_Adapter_Abstract)) {
             $this->_fault('wrong_data_adapter', 'Wrong data adapter class');
        }
        $this->_dataAdapters[$code] = $adapter;
    }

    /**
     * @return RetailOps_Api_Helper_Data
     */
    public function getHelper()
    {
        return Mage::helper('retailops_api');
    }

    /**
     * @return RetailOps_Api_Model_Resource_Api
     */
    protected function _getResource()
    {
        return Mage::getResourceModel('retailops_api/api');
    }
}
