<?php
class Totm_OrderInvoice_Model_Sales_Convert_Order extends Mage_Sales_Model_Convert_Order
{
    
    /**
     * Convert order object to invoice
     *
     * @param   Mage_Sales_Model_Order $order
     * @return  Totm_OrderInvoice_Model_Sales_Order_Invoice
     */
    public function toInvoice(Mage_Sales_Model_Order $order)
    {
        $invoice = Mage::getModel('totm_orderinvoice/sales_order_invoice');
        $invoice->setOrder($order)
            ->setStoreId($order->getStoreId())
            ->setCustomerId($order->getCustomerId())
            ->setBillingAddressId($order->getBillingAddressId())
            ->setShippingAddressId($order->getShippingAddressId());

        Mage::helper('core')->copyFieldset('sales_convert_order', 'to_invoice', $order, $invoice);
        return $invoice;
    }
    
}