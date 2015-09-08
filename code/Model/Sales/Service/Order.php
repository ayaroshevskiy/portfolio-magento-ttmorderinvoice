<?php
class Totm_OrderInvoice_Model_Sales_Service_Order extends Mage_Sales_Model_Service_Order
{
    
    /**
     * Class constructor
     *
     * @param Mage_Sales_Model_Order $order
     */
    public function __construct(Mage_Sales_Model_Order $order)
    {
        $this->_order       = $order;
        $this->_convertor   = Mage::getModel('totm_orderinvoice/sales_convert_order');
    }
    
}