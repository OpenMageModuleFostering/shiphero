<?php
/* ShipHero Shipment REST API
*
* @category ShipHero
* @package ShipHero_Shipment
* @author Chuck Hudson (used with permission). For more recipes, see Chuck's book http://shop.oreilly.com/product/0636920023968.do
*/

class ShipHero_PaymentExtApi_Model_Api2_Payment_Rest_Admin_V1 extends ShipHero_PaymentExtApi_Model_Api2_Payment
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
        if(empty($data['order_id']))
        {
            $this->_critical(self::RESOURCE_REQUEST_DATA_INVALID);
        }

        $orderId = $data['order_id'];
        $order = Mage::getModel('sales/order')->load($orderId);

        // error_log('In Create Invoice');
        if($order['status'] == 'complete') $this->_errorMessage("The order is already complete.", Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
     
        if($order->canInvoice()) {
            // error_log('Can Invoice:');
            // error_log($order->canInvoice());
            try {
                // Generate invoice for this shipment
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice($this->_getItemQtys($order));
                $this->_saveInvoice($invoice, $order);
                // error_log('Saved Invoice');

                // Finally, Save the Order
                $this->_saveOrder($order, $customerEmailComments);
                // error_log('Saved Order');

            } catch (Exception $e){
                 //error_log('Can Invoice Errors');
                 //error_log($e->getMessage());
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
    protected function _getItemQtys(Mage_Sales_Model_Order $order)
    {
        $qty = array();
        
        foreach ($order->getAllItems() as $_eachItem) {
            if ($_eachItem->getParentItemId()) {
                $qty[$_eachItem->getParentItemId()] = (int)$_eachItem->getQtyOrdered();
            } else {
                $qty[$_eachItem->getId()] = (int)$_eachItem->getQtyOrdered();
            }
        }

        return $qty;
    }
     
    /**
     * Saves the Invoice in the Order
     *
     * @param $invoice Mage_Sales_Model_Order_Invoice
     * @param $order Mage_Sales_Model_Order
     */
    protected function _saveInvoice(Mage_Sales_Model_Order_Invoice $invoice, Mage_Sales_Model_Order $order)
    {
        $amount = $invoice->getGrandTotal();
        $invoice->register()->pay();
        $invoice->getOrder()->setIsInProcess(true);

        $history = $invoice->getOrder()->addStatusHistoryComment(
            'Amount of $' . $amount . ' captured automatically.', false
        );
        $history->setIsCustomerNotified(true);

        Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
        $invoice->capture()->save();
        $invoice->sendEmail(true, ''); //set this to false to not send the invoice via email

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