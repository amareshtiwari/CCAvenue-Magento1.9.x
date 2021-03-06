<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

class Codesbug_Ccavenue_PaymentController extends Mage_Core_Controller_Front_Action
{

    // The redirect action is triggered when someone places an order
    public function redirectAction()
    {
        $_order = new Mage_Sales_Model_Order();
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $_order->loadByIncrementId($orderId);
        $paymentDetails = array();
        $paymentDetails['merchant_id'] = Mage::getStoreConfig('payment/ccavenue/merchant_id');
        $paymentDetails['order_id'] = $_order->getIncrementId();
        $paymentDetails['amount'] = str_replace(',', '', number_format($_order->getBaseGrandTotal(), '2', '.', ','));
        $paymentDetails['currency'] = Mage::app()->getStore()->getCurrentCurrencyCode();
        $paymentDetails['redirect_url'] = Mage::getUrl('ccavenue/payment/response');
        $paymentDetails['cancel_url'] = Mage::getUrl('ccavenue/payment/cancel');
        $paymentDetails['cancel_url'] = Mage::app()->getLocale()->getLocaleCode();
        $shippingAddress = Mage::getModel('sales/order_address')->load($_order->getBillingAddressId());
        $paymentDetails['billing_name'] = $shippingAddress['firstname'] . ' ' . $shippingAddress['lastname'];
        $paymentDetails['billing_address'] = $shippingAddress['street'];
        $paymentDetails['billing_city'] = $shippingAddress['city'];
        $paymentDetails['billing_state'] = $shippingAddress['region'];
        $paymentDetails['billing_zip'] = $shippingAddress['postcode'];
        $paymentDetails['billing_country'] = Mage::app()->getLocale()->getCountryTranslation($shippingAddress['country_id']);
        $paymentDetails['billing_tel'] = $shippingAddress['telephone'];
        $paymentDetails['billing_email'] = $shippingAddress['email'];
        $billingAddress = Mage::getModel('sales/order_address')->load($_order->getBillingAddressId());
        $paymentDetails['delivery_name'] = $billingAddress['firstname'] . ' ' . $billingAddress['lastname'];
        $paymentDetails['delivery_address'] = $billingAddress['street'];
        $paymentDetails['delivery_city'] = $billingAddress['city'];
        $paymentDetails['delivery_state'] = $billingAddress['region'];
        $paymentDetails['delivery_zip'] = $billingAddress['postcode'];
        $paymentDetails['delivery_country'] = Mage::app()->getLocale()->getCountryTranslation($billingAddress['country_id']);
        $paymentDetails['delivery_tel'] = $billingAddress['telephone'];
        $paymentDetails['billing_email'] = $billingAddress['email'];
        $hiddenArray = array();
        $hiddenArray['tid'] = $_order->getIncrementId();
        $hiddenArray['access_code'] = Mage::getStoreConfig('payment/ccavenue/access_code'); //Shared by CCAVENUES
        $working_key = Mage::getStoreConfig('payment/ccavenue/working_key');
        foreach ($paymentDetails as $key => $value) {
            $merchant_data .= $key . '=' . urlencode($value) . '&';
        }
        $hiddenArray['encRequest'] = $this->encrypt($merchant_data, $working_key); // Method for encrypting the data.
        Mage::register('ccavenueformdata', $hiddenArray);
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'ccavenue', array('template' => 'ccavenues/redirect.phtml'));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    // The response action is triggered when your gateway sends back a response after processing the customer's payment
    public function responseAction()
    {
        $working_key = Mage::getStoreConfig('payment/ccavenue/working_key');
        $rcvdString = $this->decrypt($_POST['encResp'], $working_key); //Crypto Decryption used as per the specified working key.
        $order_status = "";
        $decryptValues = explode('&', $rcvdString);
        $dataSize = sizeof($decryptValues);

        for ($i = 0; $i < $dataSize; $i++) {
            $information = explode('=', $decryptValues[$i]);
            if ($i == 1) {
                $tracking_id = trim($information[1]);
            }
            if ($i == 3) {
                $order_status = trim($information[1]);
            }
            if ($i == 5) {
                $payment_mode = trim($information[1]);
            }
        }
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($orderId);
        if ($order_status == 'Success') {
            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Gateway has authorized the payment.' . "<br>" . ' Your Transaction ID is ' . $tracking_id . "<br>" . 'your payment mode is ' . $payment_mode);
            $order->sendNewOrderEmail();
            $order->setEmailSent(true);
            $order->save();
            $cartHelper = Mage::helper('checkout/cart');
            $items = $cartHelper->getCart()->getItems();
            foreach ($items as $item) {
                $itemId = $item->getItemId();
                $cartHelper->getCart()->removeItem($itemId)->save();
            }
            Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => true));
        } else if ($order_status == 'Aborted') {
            $session = Mage::getSingleton('checkout/session');
            $orderId = $session->getLastRealOrderId();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($orderId);
            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(true)->save();
                $session->setQuoteId($quoteId);
            }
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Customer cancelled the payment.' . "<br>" . ' Your Transaction ID is ' . $tracking_id . "<br>" . 'your payment mode is ' . $payment_mode);
            $order->save();
            $er = 'Customer cancelled the payment.';
            $session->addError($er);
            $this->_redirect('checkout/cart');
        } else if ($order_status == 'Failure') {
            $session = Mage::getSingleton('checkout/session');
            $orderId = $session->getLastRealOrderId();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($orderId);
            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(true)->save();
                $session->setQuoteId($quoteId);
            }
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Gateway declined the payment.' . "<br>" . ' Your Transaction ID is ' . $tracking_id . "<br>" . 'your payment mode is ' . $payment_mode);
            $order->save();
            $er = 'Gateway declined the payment status - Failure';
            $session->addError($er);
            $this->_redirect('checkout/cart');
        } else {
            $session = Mage::getSingleton('checkout/session');
            $orderId = $session->getLastRealOrderId();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($orderId);
            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(true)->save();
                $session->setQuoteId($quoteId);
            }
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Security Error. Illegal access detected.' . "<br>" . ' Your Transaction ID is ' . $tracking_id . "<br>" . 'your payment mode is ' . $payment_mode);
            $order->save();
            $er = 'Security Error. Illegal access detected';
            $session->addError($er);
            $this->_redirect('checkout/cart');
        }
    }

    // The cancel action is triggered when an order is to be cancelled
    public function cancelAction()
    {

        if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId(Mage::getSingleton('checkout/session')->getLastRealOrderId());
            if ($order->getId()) {
                // Flag the order as 'cancelled' and save it
                $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Gateway has declined the payment.')->save();
                Mage::getSingleton('checkout/session')->unsQuoteId();
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));
            }
        }
    }

    public function encrypt($plainText, $key)
    {
        $secretKey = $this->hextobin(md5($key));
        $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
        $openMode = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', 'cbc', '');
        $blockSize = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, 'cbc');
        $plainPad = $this->pkcs5_pad($plainText, $blockSize);
        if (mcrypt_generic_init($openMode, $secretKey, $initVector) != -1) {
            $encryptedText = mcrypt_generic($openMode, $plainPad);
            mcrypt_generic_deinit($openMode);
        }
        return bin2hex($encryptedText);
    }

    public function decrypt($encryptedText, $key)
    {
        $secretKey = $this->hextobin(md5($key));
        $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
        $encryptedText = $this->hextobin($encryptedText);
        $openMode = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', 'cbc', '');
        mcrypt_generic_init($openMode, $secretKey, $initVector);
        $decryptedText = mdecrypt_generic($openMode, $encryptedText);
        $decryptedText = rtrim($decryptedText, "\0");
        mcrypt_generic_deinit($openMode);
        return $decryptedText;
    }

//*********** Padding Function *********************

    public function pkcs5_pad($plainText, $blockSize)
    {
        $pad = $blockSize - (strlen($plainText) % $blockSize);
        return $plainText . str_repeat(chr($pad), $pad);
    }

//********** Hexadecimal to Binary function for php 4.0 version ********

    public function hextobin($hexString)
    {
        $length = strlen($hexString);
        $binString = "";
        $count = 0;
        while ($count < $length) {
            $subString = substr($hexString, $count, 2);
            $packedString = pack("H*", $subString);
            if ($count == 0) {
                $binString = $packedString;
            } else {
                $binString .= $packedString;
            }

            $count += 2;
        }
        return $binString;
    }

}
