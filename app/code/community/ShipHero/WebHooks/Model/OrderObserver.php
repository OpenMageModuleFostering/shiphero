<?php

class ShipHero_WebHooks_Model_OrderObserver
{
    protected $url = 'http://api.shiphe.ro/api/api2/magento/webhooks/';
    protected $attribute_output = array();

    /**
     * Constructor
     * Determine our endpoint url
     */
    public function __construct()
    {
        switch($_SERVER['HTTP_HOST'])
        {
            case 'dev.magento.shiphero.com':
                $this->url = 'http://api.shiphe.ro/api/api2/magento/webhooks/';
                break;

            case 'magento.dev':
                $this->url = 'http://api.v1.shiphero.dev/api2/magento/webhooks/';
                break;
            
            default:
                $this->url = 'http://api.shiphe.ro/api/api2/magento/webhooks/';
                break;
        }
    }

    public function orderSaveBefore(Varien_Event_Observer $observer)
    {
        // error_log('in order save before');
    }

    /**
     * Magento passes a Varien_Event_Observer object as
     * the first parameter of dispatched events.
     */
    public function orderSaveAfter(Varien_Event_Observer $observer)
    {
        // error_log('in order save after');
        $sale = $observer->getEvent()->getOrder();
        $status = $sale['status'];
        $state = (isset($sale['state'])) ? $sale['state'] : NULL;
        // error_log(print_r($sale,1));
        // Determine how to process the order event
        // error_log($status . ', ' . $state);
        if(($status == 'canceled' && $state == 'canceled') || ($status == 'closed' && $state == 'closed')) $this->_orderCanceled($sale);
        if(($status == 'pending' && $state == 'new') || ($status == 'processing' && $state == 'processing') || ($status == 'complete' && $state == 'complete') || ($status == 'holded' && $state == 'holded')) $this->_orderCreatedUpdated($sale);
    }

    private function _orderCreatedUpdated($sale)
    {
        // Get modules
        $modules = Mage::getConfig()->getNode('modules')->children();
        $modulesArray = (array)$modules;
        if(isset($modulesArray['Innoexts_Warehouse'])) {
            // Don't Push, we'll poll these orders instead
            return TRUE;
        }

        //error_log('in order created');
        $origData = $sale->getOrigData();
        $origDataUpdated = (!empty($origData)) ? $origData['updated_at'] : $sale['updated_at'];
        $incrementId = $sale['increment_id'];
        $updating = 'no';
        if($sale['updated_at'] != $origDataUpdated)
        {
            $updating = 'yes';
        }
        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        $billingAddress = $order->getBillingAddress()->getData();
        $shippingAddress = $billingAddress;
        if(gettype($order->getShippingAddress()) == 'object'){
            $shippingAddress = $order->getShippingAddress()->getData();
        }
        //$items = $order->getAllVisibleItems();
        $items = Mage::getResourceModel('sales/order_item_collection')->addFieldToFilter('order_id',array('eq'=>$order->getID()));
        $payment = $order->getPayment()->getMethodInstance()->getCode();
        $remoteIp = (!empty($order['remote_ip'])) ? $order['remote_ip'] : NULL;
        $giftMessage = Mage::getModel('giftmessage/message');
        $giftMessageArr = array();
        if(!is_null($order['gift_message_id'])) {
            $message = $giftMessage->load((int)$order['gift_message_id']);
            $giftMessageArr = array(
                'from' => $message->getData('sender'),
                'to' => $message->getData('recipient'),
                'message' => $message->getData('message')
            );
        }

        $orderItems = array();
        $supportedProductTypes = array('configurable', 'bundle');
        foreach($items as $item)
        {
            $this->attribute_output = array();
            $product = Mage::getModel('catalog/product');
            $product->load($item['product_id']);
            $productData = $item->getProduct()->getData();
            $options = $item->getProductOptions();

            if(in_array($product->getTypeID(), $supportedProductTypes))
            {
                $productOptions = $item->getData();
                $simpleProduct = array();
                if(isset($productOptions['product_options']))
                {
                    $simpleProduct = unserialize($productOptions['product_options']);
                }

                // Loop through our options for the ordered item
                if(!empty($simpleProduct))
                {
                    if(empty($simpleProduct['options']))
                    {
                        $simpleProduct['options'] = array();
                    }

                    foreach ($simpleProduct['options'] as $key => $itemOption)
                    {
                        // Get product options
                        foreach ($product->getOptions() as $opt)
                        {
                            // Get the values for the options
                            $opts = $opt->getValues();

                            // Loop through options and extract the values for the selected item's options
                            foreach ($opts as $o)
                            {
                                $oData = $o->getData();
                                if ($itemOption['option_id'] == $oData['option_id'] && $itemOption['print_value'] == $oData['default_title'])
                                {
                                    $simpleProduct['options'][$key]['sku'] = $oData['sku'];
                                    $simpleProduct['options'][$key]['price'] = $oData['price'];
                                }
                            }
                        }
                    }

                    $product['name'] = $simpleProduct['simple_name'];
                    $productData['sku'] = $simpleProduct['simple_sku'];
                    $product->load($product->getIdBySku($simpleProduct['simple_sku']));
                    $options['options'] = $simpleProduct['options'];
                }
            }
            $p_name = $this->_getProductName($product);
            $item['sku'] = $productData['sku'];
            $customOptions = array();

            if(!empty($options['options']))
            {
                $customOptions = $options['options'];
            }

            $orderItems[] = array(
                'item_id' => $item['item_id'],
                'parent_item_id' => $item['parent_item_id'],
                'sku' => $item['sku'],
                'qty_ordered' => $item['qty_ordered'],
                'price' => $item['price'],
                'adjusted_name' => $p_name,
                'qty_shipped' => $item['qty_shipped'],
                'type_id' => $product->getTypeID(),
                'custom_options' => $customOptions,
                'attributes' => $this->attribute_output
            );
        }

        // Get Creds
        $creds = $this->_getCredentials();
        $fields = array(
            'order_id' => $order['entity_id'],
            'increment_id' => $order['increment_id'],
            'store_name' => $order['store_name'],
            'subtotal' => $order['subtotal'],
            'discount' => $order['discount_amount'],
            'total_tax' => $order['tax_amount'],
            'total_price' => $order['grand_total'],
            'shipping_amount' => $order['shipping_amount'],
            'shipping_tax_amount' => $order['shipping_tax_amount'],
            'addresses' => array($billingAddress, $shippingAddress),
            'payment_method' => $payment,
            'shipping_description' => $order['shipping_description'],
            'comment' => $order['customer_note'],
            'gift_message' => $giftMessageArr,
            'order_items' => $orderItems,
            'remote_ip' => $remoteIp,
            'status' => $sale['status'],
            'consumer_key' => $creds['key'],
            'consumer_secret' => $creds['secret'],
            'created_at' => $order['created_at'],
            'is_update' => $updating
        );
        
        $url = $this->url . 'ordercreation/';
        $request = $this->_sendData($url, $fields);
    }

    private function _orderCanceled($sale)
    {
        // error_log('in order cancel');
        // Get Creds
        $creds = $this->_getCredentials();
        $fields = array(
            'order_id' => $sale['increment_id'],
            'consumer_key' => $creds['key'],
            'consumer_secret' => $creds['secret']
        );

        $url = $this->url . 'ordercancellation/';
        $request = $this->_sendData($url, $fields);
    }

    /**
     *  Helper Functions
     */
    // Get Credentials for ShipHero Authentication
    private function _getCredentials()
    {
        //database read adapter 
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $oauthConsumerTable = Mage::getSingleton('core/resource')->getTableName('oauth_consumer');
        $result = $read->fetchRow("
            SELECT oc.key, oc.secret 
            FROM $oauthConsumerTable AS oc
            WHERE name = 'ShipHero'
        ");

        return $result;
    }

    // Send Data
    private function _sendData($url, $fields = array())
    {
        $content = json_encode($fields);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER,
            array("Content-type: application/json")
        );
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $content);

        $json_response = curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($status != 200) 
        {
            // Write a new line to var/log/shiphero-webhook-observers.log
            $error_msg = "Call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl);
            Mage::log(
                "OrderObserverError: " . $error_msg,
                null,
                'shiphero-webhook-observers.log'
            );
        }

        curl_close($curl);

        $response = json_decode($json_response, true);
        return $response;
    }

    // Get all frontend attributes
    private function _getProductName($product)
    {
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')->getItems();
        $allAttributecodes = array();
        $p_name = $product['name'];
        foreach ($attributes as $attribute){
            $_attribute = $attribute->getData();

            if($_attribute['is_visible_on_front'])
            { 
                $allAttributecodes[] = $attribute->getAttributeCode();
            }
        }
        
        $p = $product->debug();
        foreach($p as $key => $val)
        {
            if(in_array($key, $allAttributecodes))
            {
                $attribute_value = $product->getAttributeText($key);
                if(!empty($attribute_value))
                {
                    $this->attribute_output[] = array('label' => $key, 'value' => $attribute_value);
                    if($key == 'color' || $key == 'size')
                        $p_name .= ' / ' . $attribute_value;
                }
            }
        }
        return $p_name;
    }
}