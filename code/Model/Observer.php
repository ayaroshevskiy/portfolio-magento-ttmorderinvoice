<?php
class Totm_OrderInvoice_Model_Observer
{
    
    public function createInvoiceAfterOrderPlaced(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        try {
            if ($order->canInvoice()) {
                $orderId = $order->getId();
                $profileId = Mage::getModel('sagepay_recurring/recurring_profile_order')
                        ->loadByOrderId($orderId)
                        ->getProfileId();

                $profile = Mage::getModel('sagepay_recurring/recurring_profile')->load($profileId);

                // create invoice - prev

                // Create SagePay Payment

                $_profilePayment = Mage::getModel('sagepay_recurring/recurring_profile_payment')
                        ->getCollection()
                        ->addFieldToFilter('profile_id', $profileId)
                        ->setOrder('scheduled_at', 'ASC')
                        ->getFirstItem();

                $_profilePayment->setExecutedAt(Mage::getModel('core/date')->gmtDate());

                $trn = Mage::getModel('sagepaysuite2/sagepaysuite_transaction')
                        ->loadByParent($orderId);

                $amount = $profile->getPaymentAmount();

                if (!$amount || $amount === 0 || $amount === 0.00) {
                    $amount = $order->getTotalDue();
                }

                $paymentApi = Mage::getModel('sagepaysuite/api_payment');

                $auth = new Varien_Object;

                //If there is already an AUTHORISE we must REPEAT, otherwise just AUTHORISE
                if($trn->getAuthorised()) {

                    //get last authorise for repeat
                    $lastAuthorise = Mage::getModel('sagepaysuite2/sagepaysuite_action')
                                        ->getLastAuthorise($orderId);

                    if($lastAuthorise->getId()) {

                        //Setting data on object needed for REPEAT processing
                        $paymentApi->setMcode($paymentApi->realIntegrationCode($trn->getIntegration()));
                        $lastAuthorise->setIntegration($trn->getIntegration());
                        $lastAuthorise->setVendorname($trn->getVendorname());
                        $lastAuthorise->setTrnCurrency($trn->getTrnCurrency());
                        $lastAuthorise->setVpsProtocol($trn->getVpsProtocol());
                        $lastAuthorise->setOrderId($trn->getOrderId());

                        $repeat = $paymentApi->repeat($lastAuthorise, $amount);
                        if($repeat['Status'] == 'OK') {
                            $auth = Mage::getModel('sagepaysuite2/sagepaysuite_action')
                                        ->load($repeat['_requestvendor_'], 'vendor_tx_code');
                        }
                        else {
                            $_profilePayment->setPaymentDetails("ERROR: Could not repeat payment.");
                            $profile->suspend();

                            $this->_notifyPaymentNotOk($profile);
                        }
                    }

                }
                else {
                    try{
                        $auth = $paymentApi->authorise($trn, $amount, 'OrderInvoice\Observer.php | createInvoiceAfterOrderPlaced');
                    } catch(Exception $e) {
                        Sage_Log::log('debugging bad transaction', null, 'debug.log');

                        $faultKeys = array(
                            '2000 : The Authorisation was Declined by the bank.',
                            '4009 : The Amount including surcharge is outside the allowed range.',
                        );

                        if (in_array($e->getMessage(), $faultKeys)) {

                            Sage_Log::log('catch error', null, 'debug.log');

                            if ($order->canCancel()) {

                                Sage_Log::log('$order->canCancel()', null, 'debug.log');

                                Mage::register('isSecureArea', true);
                                if ($this->_resetQuote($order)){
                                    $order->delete();
                                    throw new Exception('authorise_error');
                                }
                                Mage::unregister('isSecureArea');
                            }
                        } else {
                            throw new Exception($e->getMessage());
                        }

                        Sage_Log::log($e->getMessage(), null, 'debug.log');

                    }
                }

				$this->createInvoice($order, $profile);
				$this->createShipment($order);

                $order
                    ->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE)
                    ->setData('status', 'shipped')
                    ->save();

                if($auth->getId()) {
                    $_profilePayment->setPaymentDetails($auth->getStatusDetail())
                             ->setTransactionId($auth->getId());

                    $this->_notifyPaymentOk($profile, $auth);

                }
                else {
                    $_profilePayment->setPaymentDetails("ERROR: Could not load authorisation.");
                    $profile->suspend();

                    $this->_notifyPaymentNotOk($profile);
                }

                $_profilePayment->save();

            
            }
        } catch(Exception $e) {

            if ($e->getMessage() == 'authorise_error') {
                Mage::getSingleton('core/session')->addError('Sorry there seems to be a problem with your payment, please check your details and try again.');
                throw new Exception('ajax_authorise_error');
            } else {

                Sage_Log::log('does not catch error', null, 'debug.log');

				$_profilePayment
					->setPaymentDetails($e->getMessage())
					->save();

				try {
					$profile->suspend();
				}catch(Exception $ex){
					Mage::logException($e);
				}
			}

            Mage::logException($e);
        }
    }
    
    private function _notifyPaymentNotOk($profile) {
        Mage::helper('sagepay_recurring/emailnotification')->notifyNOk($profile);
    }

    private function _notifyPaymentOk($profile, $payment) {
        Mage::helper('sagepay_recurring/emailnotification')->notifyOk($profile, $payment);
    }

	private function createInvoice($order, $profile)
	{
		$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

		if ($invoice->getTotalQty()) {
			//$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
			$invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID);

			$invoice->register();
			$transactionSave = Mage::getModel('core/resource_transaction')
				->addObject($invoice)
				->addObject($invoice->getOrder());
			$transactionSave->save();

			try {
				$startDate = Mage::helper('core')
					->formatDate($profile->getStartDatetime(), 'short', false);
				$invoice->setDeliveryPeriod($profile->getPeriodFrequency());
				$invoice->setDeliveryStartDate($startDate);
				$invoice->sendEmail(true);
			} catch (Exception $e) {
				Mage::logException($e);
			}
		}
	}

    private function createShipment($order){
        if ($order->canShip()) {

            foreach ($order->getAllItems() as $orderItem) {
                $orderItem->setQtyShipped(0);
            }

            $shipment = Mage::getModel('sales/service_order', $order)
                ->prepareShipment();

            $shipment->register();
            $shipment->getOrder()->setIsInProcess(true);

            $transactionSave = Mage::getModel('core/resource_transaction')
                ->addObject($shipment)
                ->addObject($shipment->getOrder())
                ->save();
        }
    }

    private function _resetQuote($order){
        $quoteId = $order->getQuoteId();
        $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);

        if ( !$quote->getId() ) {
            $emessage = Mage::helper('sales')->__('Cannot find an quote in database.');
            Mage::throwException($emessage);
            return false;
        }

        $quote->setIsActive(true)->save();

        $quoteCustomerId = $quote->getCustomerId();

        // Checking if Quote has customer.
        if ( !$quoteCustomerId ) {
            return false;
        }

        $quoteAppliedRuleIds = explode(',', $quote->getAppliedRuleIds());
        $this->_resetCustomerRule($quoteCustomerId, $quoteAppliedRuleIds);

        if ($code = $order->getCouponCode()) {
            $this->_resetCoupon($code, $quoteCustomerId);
        }

        return true;
    }

    private function _resetCustomerRule($customerId, $ruleIds){
        foreach( $ruleIds as $ruleId ){
            $customerRule = Mage::getModel('salesrule/rule_customer')->loadByCustomerRule($customerId, $ruleId);
            if ( $customerRule->getId() ) {
                $data = $customerRule->getData();
                if (!empty($data)) {
                    $customerRule->setTimesUsed($customerRule->getTimesUsed()-1);
                    $customerRule->save();
                }
            }
        }
    }

    private function _resetCoupon($code, $customerId){
        $coupon = Mage::getModel('salesrule/coupon')->load($code, 'code');
        $couponId = $coupon->getId();
        if ( $couponId ) {
            $coupon->setTimesUsed($coupon->getTimesUsed()-1);
            $coupon->save();
            $this->_updateCustomerCouponTimesUsed($customerId, $couponId);
        }
    }

    private function _updateCustomerCouponTimesUsed($customerId, $couponId)
    {
        $couponUsageModel = Mage::getResourceModel('salesrule/coupon_usage');

        $read = $couponUsageModel->_getReadAdapter();
        $select = $read->select();
        $select->from($couponUsageModel->getMainTable(), array('times_used'))
            ->where('coupon_id = :coupon_id')
            ->where('customer_id = :customer_id');

        $timesUsed = $read->fetchOne($select, array(':coupon_id' => $couponId, ':customer_id' => $customerId));

        if ($timesUsed > 0) {
            $couponUsageModel->_getWriteAdapter()->update(
                $couponUsageModel->getMainTable(),
                array(
                    'times_used' => $timesUsed - 1
                ),
                array(
                    'coupon_id = ?' => $couponId,
                    'customer_id = ?' => $customerId,
                )
            );
        }
    }
}