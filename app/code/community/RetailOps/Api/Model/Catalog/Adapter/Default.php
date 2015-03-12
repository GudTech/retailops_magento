<?php
/**
{license_text}
 */

class RetailOps_Api_Model_Catalog_Adapter_Default extends RetailOps_Api_Model_Catalog_Adapter_Abstract
{
    protected $_section = 'general';

    /** @var array */
    protected $_productTypes;

    public function _construct()
    {
        $this->_productTypes = array_keys(Mage::getModel('catalog/product_type')->getOptionArray());
        $this->_errorCodes = array(
            'product_type_not_passed' => 401,
            'invalid_product_data'    => 402,
            'error_saving_product'    => 403,
            'product_type_not_exists' => 404
        );
    }

    /**
     * @param array $productData
     * @param Mage_Catalog_Model_Product $product
     * @return mixed
     */
    public function processData(array &$productData, $product)
    {
        $attributeSetId = $productData['attribute_set_id'];
        $sku = $productData['sku'];

        $product->setAttributeSetId($attributeSetId);

        if (!$product->getId()) {
            if (empty($productData['type_id'])) {
                $this->_throwException('Product type is not specified', 'product_type_not_passed');
            }
            $type = $productData['type_id'];
            $this->_checkProductTypeExists($type);
            $product->setTypeId($type)->setSku($sku);
            if (!isset($productData['stock_data']) || !is_array($productData['stock_data'])) {
                //Set default stock_data if not exist in product data
                $product->setStockData(array('use_config_manage_stock' => 0));
            }
        }
        $this->_prepareDataForSave($product, $productData);

        try {
            if (is_array($errors = $product->validate())) {
                $strErrors = array();
                foreach($errors as $code => $error) {
                    if ($error === true) {
                        $error = Mage::helper('catalog')->__('Attribute "%s" is invalid.', $code);
                    }
                    $strErrors[] = $error;
                }
                $this->_throwException(implode("\n", $strErrors), 'invalid_product_data');
            }

            $product->save();
        } catch (Mage_Core_Exception $e) {
            $this->_throwException($e->getMessage(), 'error_saving_product');
        }

        $productData['product_id'] = $product->getId();

        return $product->getId();
    }

    /**
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    public function outputData($product)
    {
        $data = array();

        $data['parents'] = array();

        if ($product->isComposite()) {
            return $data;
        }
        $parents = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
        $parents = array_merge($parents, Mage::getModel('bundle/product_type')->getParentIdsByChild($product->getId()));
        $parents = $this->_getResource()->getSkuByProductIds($parents);

        $data['parents'] = $parents;

        return $data;
    }

     /**
     *  Set additional data before product saved
     *
     *  @param    Mage_Catalog_Model_Product $product
     *  @param    array $productData
     *  @return   object
     */
    protected function _prepareDataForSave($product, $productData)
    {
        $product->addData($productData);

        if (isset($productData['website_ids']) && is_array($productData['website_ids'])) {
            $product->setWebsiteIds($productData['website_ids']);
        }

        if (isset($productData['websites']) && is_array($productData['websites'])) {
            foreach ($productData['websites'] as &$website) {
                if (is_string($website)) {
                    try {
                        $website = Mage::app()->getWebsite($website)->getId();
                    } catch (Exception $e) { }
                }
            }
            $product->setWebsiteIds($productData['websites']);
        }

        if (Mage::app()->isSingleStoreMode()) {
            $product->setWebsiteIds(array(Mage::app()->getStore(true)->getWebsite()->getId()));
        }

        if (isset($productData['stock_data']) && is_array($productData['stock_data'])) {
            $product->setStockData($productData['stock_data']);
        }

        if (isset($productData['tier_price']) && is_array($productData['tier_price'])) {
             $tierPrices = Mage::getModel('catalog/product_attribute_tierprice_api')
                 ->prepareTierPrices($product, $productData['tier_price']);
             $product->setData(Mage_Catalog_Model_Product_Attribute_Tierprice_Api::ATTRIBUTE_CODE, $tierPrices);
        }
    }

    /**
     * Check if product type exists
     *
     * @param  $productType
     * @throw Mage_Api_Exception
     * @return void
     */
    protected function _checkProductTypeExists($productType)
    {
        if (!in_array($productType, $this->_productTypes)) {
            $this->_throwException('Product type not exists', 'product_type_not_exists');
        }
    }
}