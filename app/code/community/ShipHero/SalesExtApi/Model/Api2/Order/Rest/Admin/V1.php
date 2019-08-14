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
                $product = $item->getProduct()->getData();
                $ordersData[$order->getId()]['temp_item_array'][$item->getId()]['original_sku'] = $product['sku'];
                $ordersData[$order->getId()]['temp_item_array'][$item->getId()]['options'] = array();

                $options = $item->getProductOptions();
                if(!empty($options['options']))
                {
                    $ordersData[$order->getId()]['temp_item_array'][$item->getId()]['options'] = $options['options'];
                }
            }
        }

        if ($ordersData) {
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
//                    error_log(print_r($p,1));

                    foreach($allAttributeCodes as $a)
                    {
                        if(array_key_exists($a, $p) && ($a == 'color' || $a == 'size'))
                        {
                            $attribute_value = $product->getAttributeText($a);
                            if(!empty($attribute_value))
                                $p_name .= ' / ' . $attribute_value;
                        }
                    }

                    $items[$key]['adjusted_name'] = $p_name;
                    $items[$key]['type_id'] = $p['type_id'];
                    $items[$key]['sku'] = $ordersData[$orderId]['temp_item_array'][$item['item_id']]['original_sku'];
                    $items[$key]['custom_options'] = $ordersData[$orderId]['temp_item_array'][$item['item_id']]['options'];
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