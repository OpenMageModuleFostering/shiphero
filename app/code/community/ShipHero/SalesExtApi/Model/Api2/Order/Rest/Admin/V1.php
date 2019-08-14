<?php

class ShipHero_SalesExtApi_Model_Api2_Order_Rest_Admin_V1 extends Mage_Sales_Model_Api2_Order_Rest_Admin_V1
{

    /**
     * Get orders list
     *
     * @return array
     */
    protected function _retrieveCollection()
    {
        $collection = $this->_getCollectionForRetrieve();
        $total_orders = $collection->getSize();

        // Get all frontend attributes
        $allAttributeCodes = array();
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')->getItems();

        foreach ($attributes as $attribute){
            $_attribute = $attribute->getData();
            if($_attribute['is_visible_on_front'])
            {
                $allAttributeCodes[] = $attribute->getAttributeCode();
            }
        }

        if ($this->_isPaymentMethodAllowed()) {
            $this->_addPaymentMethodInfo($collection);
        }
        if ($this->_isGiftMessageAllowed()) {
            $this->_addGiftMessageInfo($collection);
        }
        $this->_addTaxInfo($collection);

        $ordersData = array();

        foreach ($collection->getItems() as $order) {
            $ordersData[$order->getId()] = $order->toArray();
            $ordersData[$order->getId()]['total_orders'] = $total_orders;

            foreach($order->getAllItems() as $item)
            {
                $product = $item->getProduct();
                $pData = $product->getData();
                $ordersData[$order->getId()]['temp_item_array'][$item->getId()]['original_sku'] = $pData['sku'];
                $ordersData[$order->getId()]['temp_item_array'][$item->getId()]['options'] = array();

                $options = $item->getProductOptions();
                if(!empty($options['options']))
                {
                    // Loop through our options for the ordered item
                    foreach($options['options'] as $key => $itemOption)
                    {
                        // Get product options
                        foreach($product->getOptions() as $opt)
                        {
                            // Get the values for the options
                            $opts = $opt->getValues();

                            // Loop through options and extract the values for the selected item's options
                            foreach($opts as $o)
                            {
                                $oData = $o->getData();
                                if($itemOption['option_id'] == $oData['option_id'] && $itemOption['print_value'] == $oData['default_title'])
                                {
                                    $options['options'][$key]['sku'] = $oData['sku'];
                                    $options['options'][$key]['price'] = $oData['price'];
                                }
                            }
                        }
                    }

                    $ordersData[$order->getId()]['temp_item_array'][$item->getId()]['options'] = $options['options'];
                }
            }
        }

        if ($ordersData) {
            $modules = Mage::getConfig()->getNode('modules')->children();
            $modulesArray = (array)$modules;
            /**
             * Retrieve the read connection
             */
            $resource = Mage::getSingleton('core/resource');
            $readConnection = $resource->getConnection('core_read');

            foreach ($this->_getAddresses(array_keys($ordersData)) as $orderId => $addresses) {
                $ordersData[$orderId]['addresses'] = $addresses;
            }
            foreach ($this->_getItems(array_keys($ordersData)) as $orderId => $items) {
                foreach($items as $key => $item)
                {
                    $product_id = Mage::getModel("catalog/product")->getIdBySku($item['sku']);
                    $product = Mage::getModel('catalog/product')->load($product_id);
                    $p = $product->getData();
                    $p_name = $item['name'];
                    $attribute_output = array();

                    foreach($allAttributeCodes as $a)
                    {
                        if(array_key_exists($a, $p))
                        {
                            $attribute_value = $product->getAttributeText($a);
                            if(!empty($attribute_value))
                            {
                                $attribute_output[] = array('label' => $a, 'value' => $attribute_value);
                                if($a == 'color' || $a == 'size')
                                    $p_name .= ' / ' . $attribute_value;
                            }
                        }
                    }

                    $items[$key]['adjusted_name'] = $p_name;
                    $items[$key]['type_id'] = $p['type_id'];
                    $items[$key]['sku'] = $ordersData[$orderId]['temp_item_array'][$item['item_id']]['original_sku'];
                    $items[$key]['custom_options'] = $ordersData[$orderId]['temp_item_array'][$item['item_id']]['options'];
                    $items[$key]['attributes'] = $attribute_output;

                    $stock_id = 1;
                    if(isset($modulesArray['Innoexts_Warehouse'])) {
                        $query = 'SELECT stock_id FROM warehouse_flat_order_grid_warehouse WHERE entity_id = ' . (int)$orderId . ' LIMIT 1';
                        $stock_id = $readConnection->fetchOne($query);
                    }
                    $items[$key]['stock_id'] = (int)$stock_id;

                }
                $ordersData[$orderId]['order_items'] = $items;
                unset($ordersData[$orderId]['temp_item_array']);
            }

            foreach ($this->_getComments(array_keys($ordersData)) as $orderId => $comments) {
                $ordersData[$orderId]['order_comments'] = $comments;
            }
        }

        return $ordersData;
    }
    
}