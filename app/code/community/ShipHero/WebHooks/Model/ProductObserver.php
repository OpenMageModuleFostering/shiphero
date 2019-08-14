<?php

class ShipHero_WebHooks_Model_ProductObserver
{
    protected $url = 'http://api.shiphe.ro/api/api2/magento/webhooks/';
    protected $attributes = array();

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

    /**
     * Magento passes a Varien_Event_Observer object as
     * the first parameter of dispatched events.
     */
    public function productSaveAfter(Varien_Event_Observer $observer)
    {
        // error_log('in product save after');
        $product = $observer->getEvent()->getProduct();
        
        // A sku must always be present
        // On product duplicate theire is no sku initially so we don't want' to 
        // fire the webhook
        if(empty($product['sku'])) return true;

        $this->_productCreatedUpdated($product);
    }

    public function productDelete(Varien_Event_Observer $observer)
    {
        // error_log('in product delete');
        $product = $observer->getEvent()->getProduct();
        $images = array();
        foreach($product->getMediaGalleryImages() as $image) 
        {
            $images[] = array('url' => $image->getUrl(), 'position' => $image->getPosition());
        }

        // Get Creds
        $creds = $this->_getCredentials();
        $url = $this->url . 'product-deletion/'; 
        $fields = array(
            "product_id" => $product["entity_id"],
            "sku" => $product["sku"],
            "consumer_key" => $creds['key'],
            "consumer_secret" => $creds['secret']
        ); 

        $response = $this->_sendData($url, $fields);
    }

    private function _productCreatedUpdated($product)
    {
        // error_log('in product create update');
        // Retrieve the product being updated from the event observer
        $stock = $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product["entity_id"])->getData();

        // Check if Advanced Stock Module is installed so we can pull stock from all warehouses
        $stockData = array();
        $modules = Mage::getConfig()->getNode('modules')->children();
        $modulesArray = (array)$modules;
        if(isset($modulesArray['MDN_AdvancedStock'])) {
            $collection = Mage::getModel('cataloginventory/stock_item')
                ->getCollection()
                ->join('AdvancedStock/Warehouse', 'main_table.stock_id=`AdvancedStock/Warehouse`.stock_id')
                ->addFieldToFilter('product_id', $product["entity_id"]);

            $stocks = $collection->getData();
            foreach($stocks as $s){
                $stockData[] = array(
                    'quantity' => $s['qty'],
                    'stock_item_id' => $s['item_id'],
                    'stock_id' => $s['stock_id']
                );
            }
        } elseif(isset($modulesArray['Innoexts_Warehouse'])) {
            $collection = Mage::getModel('cataloginventory/stock_item')
                ->getCollection()
                ->addFieldToFilter('product_id', $product["entity_id"]);

            $stocks = $collection->getData();
            foreach($stocks as $s){
                $stockData[] = array(
                    'quantity' => $s['qty'],
                    'stock_item_id' => $s['item_id'],
                    'stock_id' => $s['stock_id']
                );
            }
        }

        $images = array();
        foreach($product->getMediaGalleryImages() as $image) 
        {
            $images[] = array('url' => $image->getUrl(), 'position' => $image->getPosition());
        }

        if(empty($images))
        {
            $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')->getParentIdsByChild($product['entity_id']);
            if(!empty($parentIds))
            {
                $productParent = Mage::getModel('catalog/product')->load($parentIds[0]);
                foreach($productParent->getMediaGalleryImages() as $image)
                {
                    $images[] = array('url' => $image->getUrl(), 'position' => $image->getPosition());
                }
            }
        }

        $name = $this->_getProductName($product);
        $stock_item_id = $stock['item_id'];
        $store_id = (isset($stock['store_id'])) ? $stock['store_id'] : $product['store_id'];
        $weight = (isset($product['weight'])) ? $product['weight'] : 0;
        $cost = (isset($product['cost'])) ? $product["cost"] : 0;

        // Get Creds
        $creds = $this->_getCredentials();
        $url = $this->url . 'product-update/'; 
        $fields = array(
            "product_id" => $product["entity_id"],
            "name" => $name,
            "sku" => $product["sku"],
            "price" => $product["price"],
            "weight" => $weight,
            "cost" => $cost,
            "status" => $product["status"],
            "quantity" => $stock['qty'],
            "stock_id" => $stock['stock_id'],
            "stock_item_id" => $stock_item_id,
            "multi_stock_data" => $stockData,
            "store_id" => $store_id, 
            "images" => $images,
            "attributes" => $this->attributes,
            "consumer_key" => $creds['key'],
            "consumer_secret" => $creds['secret']
        );

        $response = $this->_sendData($url, $fields);
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
    private function _sendData($url, $fields)
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
            $error_msg = "Call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", culr_errno " . curl_errno($curl);
            Mage::log(
                "ProductObserverError: " . $error_msg,
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
        $allFrontendAttributecodes = array();
        $p_name = $product['name'];
        foreach ($attributes as $attribute){
            $_attribute = $attribute->getData();
            if($_attribute['is_visible_on_front'])
            { 
                $allFrontendAttributecodes[] = $attribute->getAttributeCode();
            }

            $allAttributecodes[] = $attribute->getAttributeCode();
        }
        
        $p = $product->debug();
        foreach($p as $key => $val)
        {
            if(in_array($key, $allFrontendAttributecodes) && ($key == 'color' || $key == 'size'))
            {
                $attribute_value = $product->getAttributeText($key);

                if(!empty($attribute_value))
                    $p_name .= ' / ' . $attribute_value;
            }

            if(in_array($key, $allAttributecodes))
            {
                $attribute_value = $product->getAttributeText($key);
                if($attribute_value == false)
                {
                    $attr = $product->getResource()->getAttribute($key);
                    if(!empty($attr))
                        $attribute_value = $attr->getFrontend()->getValue($product);

                    $attr2 = $product->getResource()->getAttribute('design_number');
                    if(!empty($attr2))
                        $attribute_value = $attr2->getFrontend()->getValue($product);
                }
                $this->attributes[] = array($key => $attribute_value);
            }
        }

        return $p_name;
    }
}