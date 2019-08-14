<?php
 
class ShipHero_CatalogExtApi_Model_Api2_Product_Rest_Admin_V1 extends Mage_Catalog_Model_Api2_Product_Rest_Admin_V1
{

    /**
     * Retrieve product count
     *
     * @return array
     */
    protected function _retrieve()
    {
        /** @var $collection Mage_Catalog_Model_Resource_Product_Collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $store = $this->_getStore();
        $collection->setStoreId($store->getId());
        $collection->addAttributeToSelect(array_keys(
            $this->getAvailableAttributes($this->getUserType(), Mage_Api2_Model_Resource::OPERATION_ATTRIBUTE_READ)
        ));
        $this->_applyCategoryFilter($collection);
        $this->_applyCollectionModifiers($collection);
        $products = $collection->load()->toArray();

        // Get all attribute sets
        $attributeSets = array();
//        $attributeSetCollection = Mage::getResourceModel('eav/entity_attribute_set_collection')->load();
        $entityType = Mage::getModel('catalog/product')->getResource()->getTypeId();
        $attributeSetCollection = Mage::getResourceModel('eav/entity_attribute_set_collection')->setEntityTypeFilter($entityType);
        foreach ($attributeSetCollection as $attributeSet) {
            $id = $attributeSet->getAttributeSetId();
            $name = $attributeSet->getAttributeSetName();
            $attributeSets[] = array('id' => $id, 'name' => $name);
        }

        $productInfo = array('catalog_size' => $collection->getSize(), 'attribute_sets' => $attributeSets);

        return $productInfo;
    }

    /**
     * Retrieve list of products
     *
     * @return array
     */
    protected function _retrieveCollection()
    {
        /** @var $collection Mage_Catalog_Model_Resource_Product_Collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $store = $this->_getStore();
        $collection->setStoreId($store->getId());
        $collection->addAttributeToSelect(array_keys(
            $this->getAvailableAttributes($this->getUserType(), Mage_Api2_Model_Resource::OPERATION_ATTRIBUTE_READ)
        ));
        $this->_applyCategoryFilter($collection);
        $this->_applyCollectionModifiers($collection);
        $products = $collection->load()->toArray();
        $total_products = $collection->getSize();

        // Get all frontend attributes
        $allFrontendAttributeCodes = array();
        $allAttributeCodes = array();
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')->getItems();

        foreach ($attributes as $attribute){
            $_attribute = $attribute->getData();
            if($_attribute['is_visible_on_front'])
            {
                $allFrontendAttributeCodes[] = $attribute->getAttributeCode();
            }

            $allAttributeCodes[] = $attribute->getAttributeCode();

        }

        foreach($products as $k => $p)
        {
            $images = array();
            $product = Mage::getModel('catalog/product')->load($p['entity_id']);
            $product_attributes = array();
            $custom_options = array();

            $p_name = $p['name'];
            foreach($allFrontendAttributeCodes as $a)
            {
                if(array_key_exists($a, $p) && ($a == 'color' || $a == 'size'))
                {
                    $attribute_value = $product->getAttributeText($a);

                    if(!empty($attribute_value))
                        $p_name .= ' / ' . $attribute_value;
                }
            }


            foreach($allAttributeCodes as $b)
            {
                if(array_key_exists($b, $p))
                {
                    $attribute_value = $product->getAttributeText($b);
                    if($attribute_value == false)
                    {
                        $attr = $product->getResource()->getAttribute('design_number');
                        if(!empty($attr))
                            $attribute_value = $attr->getFrontend()->getValue($product);
                    }
                    $product_attributes[] = array($b => $attribute_value);
                }
            }

            foreach($product->getOptions() as $o)
            {
                $values = $o->getValues();
                $typeData = $o->getData();
                $label = $typeData['title'];
                foreach($values as $key => $val)
                {
                    $v = $val->getData();
                    $custom_options[] = array(
                        'label' => $label,
                        'value' => $v['title'],
                        'sku' => $v['sku'],
                        'price' => $v['price']
                    );
                }
            }

            // Check if Advanced Stock Module is installed so we can pull stock from all warehouses
            $stock = array();
            $modules = Mage::getConfig()->getNode('modules')->children();
            $modulesArray = (array)$modules;

            if(isset($modulesArray['MDN_AdvancedStock'])) {
                $collection = Mage::getModel('cataloginventory/stock_item')
                    ->getCollection()
                    ->join('AdvancedStock/Warehouse', 'main_table.stock_id=`AdvancedStock/Warehouse`.stock_id')
                    ->addFieldToFilter('product_id', $p['entity_id']);

                $stock = $collection->getData();
            } elseif(isset($modulesArray['Innoexts_Warehouse'])) {
                $collection = Mage::getModel('cataloginventory/stock_item')
                    ->getCollection()
                    ->addFieldToFilter('product_id', $p['entity_id']);

                $stock = $collection->getData();
            } else {
                $stock = $product['stock_item']->getData();
            }

            foreach ($product->getMediaGalleryImages() as $image) {
                $images[] = array('url' => $image->getUrl(), 'position' => $image->getPosition());
            }

            if(empty($images))
            {
                $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($p['entity_id']);
                if(!empty($parentIds))
                {
                    $productParent = Mage::getModel('catalog/product')->load($parentIds[0]);
                    foreach($productParent->getMediaGalleryImages() as $image)
                    {
                        $images[] = array('url' => $image->getUrl(), 'position' => $image->getPosition());
                    }
                }
            }

            $products[$k]['adjusted_name'] = $p_name;
            $products[$k]['images'] = $images;
            $products[$k]['stock'] = $stock;
            $products[$k]['attributes'] = $product_attributes;
            $products[$k]['custom_options'] = $custom_options;
            $products[$k]['catalog_size'] = $total_products;
        }

        return $products;
    }

    /**
     * Update product by its ID
     *
     * @param array $data
     * TODO: Finish update product endpoint
     */
//    protected function _update(array $data)
//    {
//        error_log('in update');
//        error_log(print_r($data,1));
//        $product = $this->_getProduct();
//        if(empty($product))
//        {
//            $this->_critical('Invalid product id: ' . $this->getRequest()->getParam('id'), Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
//        }
//
//        $colors = array();
//        $attribute = Mage::getModel('eav/config')->getAttribute('catalog_product', 'color');
//        foreach ($attribute->getSource()->getAllOptions(true, true) as $instance)
//        {
//            $colors[$instance['value']] = $instance['label'];
//        }
//
//        // Make sure that the product has the default attribute set id
//        $defaultAttributeSetId = Mage::getModel('catalog/product')->getDefaultAttributeSetId();
//        $productData = $product->getData();
////        $product->setData('color', 20);
////        $attributes = Mage::getModel('catalog/product_attribute_api')->items($data['attribute_set_id']);
////        error_log(print_r($attributes,1));
////        if($product->getAttributeSetId() == $defaultAttributeSetId)
////        {
////            $attributes = Mage::getModel('catalog/product_attribute_set_api')->items($data['attribute_set_id']);
////            $product->setAttributeSetId($data['attribute_set_id']);
////        }
////        if (isset($data['sku'])) {
////            $product->setSku($data['sku']);
////        }
//
//        try {
//            $product->save();
//        } catch (Mage_Eav_Model_Entity_Attribute_Exception $e) {
//            $this->_critical(sprintf('Invalid attribute "%s": %s', $e->getAttributeCode(), $e->getMessage()),
//                Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
//        } catch (Mage_Core_Exception $e) {
//            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
//        } catch (Exception $e) {
//            $this->_critical(self::RESOURCE_UNKNOWN_ERROR);
//        }
//    }
}