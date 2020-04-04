<?php
class Codesbug_Ccavenue_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'ccavenue';

    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = true;
    protected $_canUseForMultishipping = false;

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('ccavenue/payment/redirect/', array('_secure' => true));
    }
}
