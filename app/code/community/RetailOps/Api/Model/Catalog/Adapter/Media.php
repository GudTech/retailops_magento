<?php
/**
The MIT License (MIT)

Copyright (c) 2015 Gud Technologies Incorporated (RetailOps by GüdTech)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
 */

class RetailOps_Api_Model_Catalog_Adapter_Media extends RetailOps_Api_Model_Catalog_Adapter_Abstract
{
    /**
     * Attribute code for media gallery
     */
    const ATTRIBUTE_CODE      = 'media_gallery';

    const CRON_DOWNLOAD_LIMIT = 10;

    protected $_section   = 'media';

    protected $_mediaDataToSave = array();
    protected $_straightMediaProcessing = false;
    protected $_colors = array();
    protected $_configurableAllMediaData = array();

    protected function _construct()
    {
        $this->_errorCodes = array(
            'no_media_attribute'   => 801,
        );
        parent::_construct();
    }

    /**
     * @param array $productData
     * @param Mage_Catalog_Model_Product $product
     * @return mixed|void
     */
    public function processData(array &$productData, $product)
    {
        //mage::log(print_r($productData, true), null, 'productData.log');
        if ($product->getId() && (!isset($productData['unset_other_media']) || $productData['unset_other_media'])) {
            $this->clearProductGallery($product);
        }
        
        if(isset($productData['attributes'])) {
            foreach($productData['attributes'] as $attributes) {
                if(strtolower($attributes['attribute_code']) == "color") {
                    if($attributes['value']) {
                        $color = $attributes['value'];
                    }
                    break;
                }
            }
        }

        if (isset($productData['media'])) {
            $allMediaData = array();
            $colors = array();
  
            foreach ($productData['media'] as $mediaData) {
                
                $mediaData = new Varien_Object($mediaData);
                $mediaData->setColor($color);
                
                if($mediaData['position'] == '1') { $mediaData->setTag('base'); }
                if($mediaData['position'] == '2') { $mediaData->setTag('rollover'); }
                if($productData['sequence']) { $mediaData->setSequence($productData['sequence']); }

                if($productData['type_id'] == 'configurable') {
                    $mediaData->setConfigurable(true);
                    $configSku = $productData['configurable_sku'][0];
                    $mediaData->setConfigurableSku($configSku);
                }
            
                Mage::dispatchEvent('retailops_catalog_media_process_before',
                    array('media_data' => $mediaData));

                $allMediaData[] = $mediaData->getData();

                $this->_configurableAllMediaData[$configSku] = $mediaData->getData();

            }

            $this->_mediaDataToSave[$productData['sku']] = json_encode($allMediaData);
        }
        if (isset($productData['straight_media_process']) && $productData['straight_media_process']) {
            $this->_straightMediaProcessing = true;
        }
    }

    /**
     * @param array $skuToIdMap
     * @return $this|void
     */
    public function afterDataProcess(array &$skuToIdMap)
    {
        $this->_processMediaToSave($this->_mediaDataToSave, $skuToIdMap);
        $this->_processMediaToSave($this->_configurableAllMediaData, $skuToIdMap);
    }

    /**
     * Unset product image gallery
     *
     * @param $product
     */
    public function clearProductGallery($product)
    {
        $galleryData = $this->_prepareGallery($product);

        if (isset($galleryData['images']) && is_array($galleryData['images'])) {
            foreach ($galleryData['images'] as &$image) {
                $image['removed'] = 1;
            }
        }

        $product->setData(self::ATTRIBUTE_CODE, $galleryData);
    }

    /**
     * Download products media
     *
     * @param Varien_Object|null $item
     * @return array
     */
    public function downloadProductImages($item = null)
    {
        $ioAdapter = new Varien_Io_File();
        $tmpDirectory = Mage::getBaseDir('var') . DS . 'api' . DS . uniqid();
        $ioAdapter->checkAndCreateFolder($tmpDirectory);
        $ioAdapter->open(array('path' => $tmpDirectory));
        $remoteCopyRetryLimit = 3;
        $errorLogPath = '/tmp/retailops_magento_image_error.log';
        if (!$item) {
            $items = Mage::getModel('retailops_api/catalog_media_item')->getCollection();
            $limit = $this->getHelper()->getConfig('media_processing_products_limit');
            if (!is_numeric($limit)) {
                $limit = self::CRON_DOWNLOAD_LIMIT;
            }
            $items->getSelect()->limit($limit);
        } else {
            $items = array($item);
        }
        $result = array();
        /** @var $item RetailOps_Api_Model_Catalog_Media_Item */
        foreach ($items as $item) {
            $productId = $item->getProductId();
            $product = Mage::getModel('catalog/product')->load($productId);
            $product->setStoreId(0); //using default store for images import
            $gallery = $this->_getGalleryAttribute($product);
            $data = json_decode($item->getMediaData(), true);
            $allImages = $this->_getResource()->getProductMedia($productId);
            $existingImageMap = array();
            foreach ($allImages as $image) {
                $existingImageMap[$image['value_id']]
                    = array( 'mediakey' => $image['retailops_mediakey'], 'filename' => $image['value'] );
            }
            $sku = $product->getSku();
            $result[$sku] = array();
            try {
                $imageResult = array();
                $newImages = array();
                $swatches = array();
                $order = array();

                foreach ($data as $newImage) {
                    try {
                        $file = $this->_existingImage($existingImageMap, $newImage);

                        if (!$file) {
                            $url = $newImage['download_url'];
                            if (!$this->_httpFileExists($url)) {
                                Mage::throwException('Image does not exist.');
                            }
                            $fileName = $this->_getFileName($url, $newImage['mediakey']);
                            $fileName = $tmpDirectory . DS . $fileName;
                            $ioAdapter->cp($url, $fileName);

                            $color_id = $this->_getOptionId('color', $newImage['color']);
                            
                            $retry = 0;
                            $remoteCopySuccess = false;
                            while ($retry++ < $remoteCopyRetryLimit && !$remoteCopySuccess) {
                                $remoteCopySuccess = $ioAdapter->cp($url, $fileName);
                            }

                            if (!$remoteCopySuccess) {
                                $remoteCopyError = error_get_last();

                                throw new Exception($remoteCopyError['message']);
                            }

                            // Adding image to gallery
                            $file = $gallery->getBackend()->addImage(
                                $product,
                                $fileName,
                                null,
                                true
                            );

                            $newImages[$file] = $newImage['mediakey'];
                            $swatches[$file] = $newImage['tag'];
                            $sequence[$file] = $newImage['sequence'];
                            $order[$file] = $newImage['position'];

                        }

                        $gallery->getBackend()->updateImage($product, $file, $newImage);
                        if (isset($newImage['types'])) {
                            $gallery->getBackend()->setMediaAttribute($product, $newImage['types'], $file);
                        }
                    } catch (Exception $e) {
                        $message = sprintf("Could not process image %s, error message: %s", $newImage['download_url'], $e->getMessage());
                        $imageResult[] = $message;
                        file_put_contents($errorLogPath, "$message\n", FILE_APPEND);
                    }
                }
                if ($imageResult) {
                    $result[$sku]['images'] = $imageResult;
                }
                

                $product->save();
                $this->_updateMediaKeys($product->getId(), $newImages, $newImage['color'], $swatches, $sequence, $order);
                if ($item->getId()) {
                    $item->delete();
                }
                $product->clearInstance();
            } catch (Exception $e) {
                $result[$sku]['general'] = $e->getMessage();
                file_put_contents($errorLogPath, "{$e->getMessage()}\n", FILE_APPEND);
            }
        }

        // Apply CJM dropdown values
        //$this->_applyCjmValues($items);
        
        // Remove temporary directory
        $ioAdapter->rmdir($tmpDirectory, true);

        return $result;
    }

    /**
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    public function outputData($product)
    {
        $galleryData = $this->_prepareGallery($product);
        $data = array();
        $data['media'] = array();

        if (!isset($galleryData['images']) || !is_array($galleryData['images'])) {
            return $data;
        }

        $result = array();
        $mediaWithMediaKey = $this->_getResource()->getProductMedia($product->getId());
        $valueIdToMediaKey = array();
        foreach ($mediaWithMediaKey as $media) {
            $valueIdToMediaKey[$media['value_id']] = $media['retailops_mediakey'];
        }
        foreach ($galleryData['images'] as &$image) {
            if (!empty($valueIdToMediaKey[$image['value_id']])) {
                $image['mediakey'] = $valueIdToMediaKey[$image['value_id']];
            }
            $result[] = $this->_imageToArray($image, $product);
        }

        $data['media'] = $result;

        return $data;
    }

    /**
     * Converts image to api array data
     *
     * @param array $image
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _imageToArray(&$image, $product)
    {
        $result = array(
            'file'      => $image['file'],
            'label'     => $image['label'],
            'position'  => $image['position'],
            'exclude'   => $image['disabled'],
            'mediakey'  => $image['mediakey'],
            'url'       => $this->_getMediaConfig()->getMediaUrl($image['file']),
            'types'     => array()
        );


        foreach ($product->getMediaAttributes() as $attribute) {
            if ($product->getData($attribute->getAttributeCode()) == $image['file']) {
                $result['types'][] = $attribute->getAttributeCode();
            }
        }

        return $result;
    }

    /**
     * Retrieve media config
     *
     * @return Mage_Catalog_Model_Product_Media_Config
     */
    protected function _getMediaConfig()
    {
        return Mage::getSingleton('catalog/product_media_config');
    }

    /**
     * Prepare product's gallery data
     *
     * @param $product
     * @return mixed
     */
    protected function _prepareGallery($product)
    {
        $gallery = $this->_getGalleryAttribute($product);
        $gallery->getBackend()->afterLoad($product);
        $galleryData = $product->getData(self::ATTRIBUTE_CODE);

        return $galleryData;
    }

    /**
     * Check if image download url is valid
     *
     * @param $url
     * @return bool
     */
    protected function _httpFileExists($url)
    {
        $headers = @get_headers($url);

        return !(strpos($headers[0], '200') === false);
    }

    /**
     * Get existing image filename, if any, based on mediakey and filename
     *
     * @param $existingImageMap
     * @param $imageData
     * @return mixed
     */
    protected function _existingImage($existingImageMap, $imageData)
    {
        // Prioritize mediakeys. Search all existing images for mediakey before considering filename_match.
        foreach ($existingImageMap as $existingImageData) {
            if ($imageData['mediakey'] == $existingImageData['mediakey']) {
                return $existingImageData['filename'];
            }
        }
        
        foreach ($existingImageMap as $existingImageData) {
            $fileNameMatch = preg_quote($data['filename_match'], '~');

            if (strlen($fileNameMatch) && preg_grep('~' . $fileNameMatch . '~', $existingImageData['filename'])) {
                return $existingImageData['filename'];
            }
        }
        
        return false;
    }

    protected function _getFileName($url, $mediakey)
    {
        $fileName  = Varien_File_Uploader::getCorrectFileName(basename($url));
        $fileName = trim($fileName, '_');

        return $fileName;
    }

    /**
     * Update product's gallery with mediakeys
     *
     * @param $productId
     * @param $newImages
     */
    protected function _updateMediaKeys($productId, $newImages, $color, $swatches, $sequence, $order)
    {
        $allImages = Mage::getResourceModel('retailops_api/api')->getProductMedia($productId);

        $dataToUpdate = array();
        foreach ($allImages as $image) {
            if (isset($newImages[$image['value']])) {
                $dataToUpdate[] = array(
                    'value_id' => $image['value_id'],
                    'retailops_mediakey' => $newImages[$image['value']],
                    'color' => $color,
                    'tag' => $swatches[$image['value']],
                    'sequence' => $sequence[$image['value']],
                    'position' => $order[$image['value']],
                 );
            }
        }
        if ($dataToUpdate) {
            Mage::log(print_r($dataToUpdate, true), null, 'updateMediaKeys.log');
            Mage::getResourceModel('retailops_api/api')->updateMediaKeys($dataToUpdate);
        }
    }

     /**
     * Retrieve gallery attribute from product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param Mage_Catalog_Model_Resource_Eav_Mysql4_Attribute|boolean
     */
    protected function _getGalleryAttribute($product)
    {
        $attributes = $product->getTypeInstance(true)
            ->getSetAttributes($product);

        if (!isset($attributes[self::ATTRIBUTE_CODE])) {
            $this->_throwException('Product has no media attribute', 'no_media_attribute');
        }

        return $attributes[self::ATTRIBUTE_CODE];
    }

    /**
    * Case sensitive option ID lookup by attribute label
    *
    * @param string $attribute_code
    * @param string $label
    * @return string Option ID
    */
    protected function _getOptionId($attribute_code, $label)
    {
        $attribute_model = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;
        $attribute_code = $attribute_model->getIdByCode('catalog_product', $attribute_code);
        $attribute = $attribute_model->load($attribute_code);

        $attribute_table = $attribute_options_model->setAttribute($attribute);
        $options = $attribute_options_model->getAllOptions(false);

        foreach($options as $option)
        {
            if ($option['label'] == $label)
            {
                $optionId = $option['value'];
                break;
            }
        }
        return $optionId;
    }

    /**
     * Apply CJM Color Swatch values to new downloaded images
     */
    protected function _applyCjmValues($items) 
    {
        $cjmImageswitcher = array();
        $cjmMouseover = array();
        $cjmMoreviews = array();
        $colors = array();
        $count = 0;
        
        foreach ($items as $item) {
            $productId = $item->getProductId();
            $product = Mage::getModel('catalog/product')->load($productId);
            $product->setStoreId(0); 
            
            $allImages = $this->_getResource()->getProductMedia($productId);

            foreach($allImages as $image) {

                $color = $image['color'];
                $tag = $image['tag'];
                $valueId = $image['value_id'];
                $colorId = $this->_getOptionId("color", $color);

                $cjmMoreviews[$valueId] = $colorId;

                if (strpos($tag,'base') !== false) {
                    $cjmImageswitcher[$valueId] = $colorId;
                } else {
                    $cjmImageswitcher[$valueId] = '';
                }
                if (strpos($tag,'rollover') !== false) {
                    $cjmMouseover[$valueId] = $colorId;
                } else {
                    $cjmMouseover[$valueId] = '';
                }

                mage::log(print_r($cjmImageswitcher, true), null, 'applyCjmValues.log');
                $product->setCjmImageswitcher(serialize($cjmImageswitcher));
                mage::log(print_r($cjmMoreviews, true), null, 'applyCjmValues.log');
                $product->setCjmMoreviews(serialize($cjmMoreviews));
                mage::log(print_r($cjmMouseover, true), null, 'applyCjmValues.log');
                $product->setCjmMouseover(serialize($cjmMouseover));
            }

            try {
                $product->save();
            } catch (Exception $e) {
                Mage::logException($e);
            }    

        }        
    }

    protected function _moveToTop(&$array, $key) {
        $temp = array($key => $array[$key]);
        unset($array[$key]);
        $array = $temp + $array;
        return $array;
    }

    protected function _moveToBottom(&$array, $key) {
        $value = $array[$key];
        unset($array[$key]);
        $array[$key] = $value;
    }

    /** 
     * Process media and copy down to configurables
     * 
     * @param array $mediaToSave
     * @param array $skuToIdMap
     */
    protected function _processMediaToSave($mediaToSave, $skuToIdMap) {
        if ($mediaToSave) {

            $retailOpsMediaItemModel = Mage::getModel('retailops_api/catalog_media_item');
            unset($configurableId);

            foreach ($mediaToSave as $sku => $data) {
                $productId = $skuToIdMap[$sku];
                $dataToSave['media_data'] = $data;
                $dataToSave['product_id'] = $productId;

                // Get Configurable Id from SKU
                if (strpos($sku,'P') !== false) {
                    $configurableId = $skuToIdMap[$sku];
                }

                // grab media data from simples for configurables
                $dataDecoded = json_decode($data, true);
                foreach($dataDecoded as $d) {
                    if($d['color']) {
                        if(!in_array($d['color'], $this->colors)) {
                            $confData[] = $data;
                            $this->colors[] = $d['color'];
                        }
                    }
                }

                mage::log(print_r($dataToSave, true), null, 'processMediaToSave.log');
                $item = $retailOpsMediaItemModel->setData($dataToSave);
                if (!$this->_straightMediaProcessing) {
                    $item->save();
                } else {
                    $this->downloadProductImages($item);
                }
            }
            unset($item);

            // mage::log(print_r($confData, true), null, 'movetotop.log');
            // // move the first item in sequence to top of array
            // $i=0;
            // foreach($confData as $orderData) {
            //     $dataDecoded = json_decode($orderData, true);
            //     foreach($dataDecoded as $od) {
            //         if($od['sequence'] == 1) {
            //             $confData = $this->_moveToBottom($confData, $i);
            //         }
            //         $i++;
            //     }
            // }
            // mage::log(print_r($confData, true), null, 'movetotop.log');

            // add configurable item data
            foreach($confData as $cData) {
                
                $configDataToSave['media_data'] = $cData;
                $configDataToSave['product_id'] = $configurableId;
                
                mage::log(print_r($configDataToSave, true), null, 'finalconfData.log');                
                $item = $retailOpsMediaItemModel->setData($configDataToSave);
                
                if (!$this->_straightMediaProcessing) {
                    $item->save();
                } else {
                    $this->downloadProductImages($item);
                }                
            }
        }
    }
}