<?php
/* ShipHero Shipment REST API
*
* @category ShipHero
* @package ShipHero_Shipment
* @author Chuck Hudson (used with permission). For more recipes, see Chuck's book http://shop.oreilly.com/product/0636920023968.do
*/

class ShipHero_ShipmentExtApi_Model_Api2_Shipment_Rest_Admin_V1 extends ShipHero_ShipmentExtApi_Model_Api2_Shipment
{

    /**
     * Retrieve
     *
     * @return array
     */
    protected function _retrieve()
    {
        // Not implemented for Beta release
        $this->_critical(self::RESOURCE_METHOD_NOT_IMPLEMENTED);
    }

    /**
     * Retrieve Collection
     *
     * @return array
     */
    protected function _retrieveCollection()
    {
        // Not implemented for Beta release
        $this->_critical(self::RESOURCE_METHOD_NOT_IMPLEMENTED);
    }

    /**
     * Create
     *
     * @return string|void
     */
    protected function _create(array $data)
    {
        // error_log("In create shipment");
        // error_log(print_r($data,1));
        if(empty($data['order_id']))
        {
            $this->_critical(self::RESOURCE_REQUEST_DATA_INVALID);
        }

        $orderId = $data['order_id'];
        $order = Mage::getModel('sales/order')->load($orderId);

        if($order['status'] == 'complete') $this->_errorMessage("The order is already complete.", Mage_Api2_Model_Server::HTTP_BAD_REQUEST);

        /**
         * Provide the Shipment Tracking Number,
         * which will be sent out by any warehouse to Magento
         */
        $shipmentTrackingNumber = $data['tracking_number'];

        /**
         * This can be blank also.
         */
        $customerEmailComments = 'Shipment Submitted by ShipHero App.';
        if ($order->canShip()) {
            try {
                // $shipment = Mage::getModel('sales/service_order', $order)
                //                 ->prepareShipment($this->_getItemQtys($order, $data['line_items']));
                $shipment = Mage::getModel('sales/service_order', $order)
                    ->prepareShipment($this->_getItemQtys($order, $data['line_items']));

                /**
                 * Carrier Codes can be like "ups" / "fedex" / "custom",
                 * but they need to be active from the System Configuration area.
                 * These variables can be provided custom-value, but it is always
                 * suggested to use Order values
                 */
                $originalCarrierCode = strtolower($order->getShippingCarrier()->getCarrierCode());
                $originalCarrierTitle = $order->getShippingCarrier()->getConfigData('title');
                $shipmentCarrierCode = $data['shipping_carrier'];
                $shipmentCarrierTitle = $data['shipping_method'];

                if(!empty($shipmentCarrierCode))
                {
                    $customerEmailComments = 'Shipped via ' . $shipmentCarrierCode;
                }
                else
                {
                    $shipmentCarrierCode = $originalCarrierCode;
                }

                if(empty($shipmentCarrierTitle))
                {
                    $shipmentCarrierTitle = $originalCarrierTitle;
                }

                $arrTracking = array(
                    'carrier_code' => $shipmentCarrierCode,
                    'title' => $shipmentCarrierTitle,
                    'number' => $shipmentTrackingNumber,
                );

                $track = Mage::getModel('sales/order_shipment_track')->addData($arrTracking);
                $shipment->addTrack($track);

                // Register Shipment
                $shipment->register();

                // Save the Shipment
                $this->_saveShipment($shipment, $order, $customerEmailComments);

                // Finally, Save the Order
                $this->_saveOrder($order, $customerEmailComments);

            } catch (Exception $e) {
                // error_log("Top level error");
                // error_log($e->getMessage());
                $this->_errorMessage($e->getMessage(), Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            }
        }

    }

    /**
     * Update
     *
     * @return void
     */
    protected function _update(array $data)
    {
        // Not Implemented for beta release
        $this->_critical(self::RESOURCE_METHOD_NOT_IMPLEMENTED);
    }

    /**
     * HELPER FUNCTIONS
     */

    /**
     * Get the Quantities shipped for the Order, based on an item-level
     * This method can also be modified, to have the Partial Shipment functionality in place
     *
     * @param $order Mage_Sales_Model_Order
     * @return array
     */
    protected function _getItemQtys(Mage_Sales_Model_Order $order, $lineItems)
    {
        $qty = array();

        foreach ($order->getAllItems() as $_eachItem) {
            $orderQty = (int)$_eachItem->getQtyOrdered();
            $shippedQty = (int)$_eachItem->getQtyShipped();
            $remainingQty = $orderQty - $shippedQty;

            if ($_eachItem->getParentItemId()) {
                $lineItemQty = (int)$lineItems[$_eachItem->getParentItemId()];
                $actualQty = ($lineItemQty > $remainingQty) ? $remainingQty : $lineItemQty;
                $qty[$_eachItem->getParentItemId()] = $actualQty;
            } else {
                $lineItemQty = (int)$lineItems[$_eachItem->getId()];
                $actualQty = ($lineItemQty > $remainingQty) ? $remainingQty : $lineItemQty;
                $qty[$_eachItem->getId()] = $actualQty;
            }
        }

        return $qty;
    }

    /**
     * Saves the Shipment changes in the Order
     *
     * @param $shipment Mage_Sales_Model_Order_Shipment
     * @param $order Mage_Sales_Model_Order
     * @param $customerEmailComments string
     */
    protected function _saveShipment(Mage_Sales_Model_Order_Shipment $shipment, Mage_Sales_Model_Order $order, $customerEmailComments = '')
    {
        $shipment->getOrder()->setIsInProcess(true);
        $transactionSave = Mage::getModel('core/resource_transaction')
            ->addObject($shipment)
            ->addObject($order)
            ->save();

        $emailSentStatus = $shipment->getData('email_sent');
        // error_log('Email Check');
        // error_log($customerEmailComments . ', ' . $emailSentStatus);
        if (!is_null($customerEmailComments) && !$emailSentStatus) {
            try {
                $emailed = $shipment->sendEmail(true, $customerEmailComments);
            }catch (Exception $e){
                error_log("Email Error");
                error_log($e->getMessage());
            }
            $shipment->setEmailSent(true);
        }

        return $this;
    }

    /**
     * Saves the Order, to complete the full life-cycle of the Order
     * Order status will now show as Complete
     *
     * @param $order Mage_Sales_Model_Order
     */
    protected function _saveOrder(Mage_Sales_Model_Order $order, $customerEmailComments = '')
    {
        if(!empty($customerEmailComments))
        {
            $order->addStatusHistoryComment($customerEmailComments, false);
        }
        // $order->setData('state', Mage_Sales_Model_Order::STATE_PROCESSING);
        // $order->setData('status', Mage_Sales_Model_Order::STATE_PROCESSING);

        $order->save();

        return $this;
    }

}