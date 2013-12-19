<?php
require_once Mage::getBaseDir('lib').DS.'SecureSubmit'.DS.'hpsChargeService.php';

class Hps_Securesubmit_Model_Payment extends Mage_Payment_Model_Method_Cc
{
    protected $_code                        = 'hps_securesubmit';
    protected $_isGateway                   = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = true;
    protected $_canRefund                   = true;
    protected $_canAuthorize                = true;

    protected $_supportedCurrencyCodes = array('USD');
    protected $_minOrderTotal = 0.5;

    protected $_formBlockType = 'hps_securesubmit/form';
    protected $_infoBlockType = 'hps_securesubmit/info';

    public function __construct()
    {
    }

    public function validate()
    {
        return $this;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        try
        {
            $this->_authorize($payment, $amount, true);
        }
        catch (Exception $e) {
            Mage::throwException(sprintf('There was an error capturing the transaction. (%s)', $e->getMessage()));
        }
    }

    public function authorize(Varien_Object $payment, $amount)
    {
        $this->_authorize($payment, $amount, false);
    }

    private function _authorize(Varien_Object $payment, $amount, $capture)
    {
        $order = $payment->getOrder();
        $billing = $order->getBillingAddress();

        try {
            if (isset($_POST['payment']['securesubmit_token']) && $_POST['payment']['securesubmit_token'])
            {
                $secureToken = $_POST['payment']['securesubmit_token'];
            }

            $config = new HpsServicesConfig();
            $config->secretAPIKey = $this->getConfigData('secretapikey');
            $config->versionNbr = '1509';
            $config->developerId = '002914';

            $chargeService = new HpsChargeService($config);

            $address = new HpsAddressInfo(
                $billing->getStreet(1),
                $billing->getCity(),
                $billing->getRegion(),
                str_replace('-', '', $billing->getPostcode()),
                $billing->getCountry());

            $cardHolder = new HpsCardHolderInfo(
                $billing->getData('firstname'),
                $billing->getData('lastname'),
                $billing->getTelephone(),
                $billing->getData('email'),
                $address);

            $token = new HpsToken(
                $secureToken,
                null,
                null);

            try
            {
                if ($capture)
                {
                    if ($payment->getCcTransId())
                    {
                        $response = $chargeService->Capture(
                            $payment->getCcTransId());
                    }
                    else
                    {
                        $response = $chargeService->Charge(
                            $amount,
                            strtolower($order->getBaseCurrencyCode()),
                            $token,
                            $cardHolder);
                    }
                }
                else
                {
                    $response = $chargeService->Authorize(
                        $amount,
                        strtolower($order->getBaseCurrencyCode()),
                        $token,
                        $cardHolder);
                }
            }
            catch (CardException $e)
            {
                Mage::throwException(Mage::helper('paygate')->__($e->Message()));
                return;
            }
        } catch (Exception $e) {
            Mage::throwException(Mage::helper('paygate')->__($e->getMessage()));
        }

        if ($response->TransactionDetails->RspCode == '00' || $response->TransactionDetails->RspCode == '0')
        {
            $this->setStore($payment->getOrder()->getStoreId());
            $payment->setStatus(self::STATUS_APPROVED);
            $payment->setAmount($amount);
            $payment->setLastTransId($response->TransactionId);
            $payment->setCcTransId($response->TransactionId);
            $payment->setTransactionId($response->TransactionId);
            $payment->setIsTransactionClosed(0);
        }
        else
        {
            if (!$payment->getCcTransId())
            {
                $this->setStore($payment->getOrder()->getStoreId());
                $payment->setStatus(self::STATUS_ERROR);
                Mage::throwException(Mage::helper('paygate')->__($response->TransactionDetails->ResponseMessage));
            }
        }

        return $this;
    }
    
    public function refund(Varien_Object $payment, $amount)
    {
        $transactionId = $payment->getCcTransId();
        $order = $payment->getOrder();

        $config = new HpsServicesConfig();
        $config->secretAPIKey = $this->getConfigData('secretapikey');
        $config->versionNbr = '1509';
        $config->developerId = '002914';

        try {

            $chargeService = new HpsChargeService($config);

            $refundResponse = $chargeService->RefundWithTransactionId(
                $amount,
                strtolower($order->getBaseCurrencyCode()),
                $transactionId);

        } catch (Exception $e) {
            Mage::throwException(Mage::helper('paygate')->__('Payment refunding error.'));
        }

        $payment
            ->setTransactionId($transactionId . '-' . Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND)
            ->setParentTransactionId($transactionId)
            ->setIsTransactionClosed(1)
            ->setShouldCloseParentTransaction(1);

        return $this;
    }    
    
    public function isAvailable($quote = null)
    {
        if($quote && $quote->getBaseGrandTotal()<$this->_minOrderTotal) {
            return false;
        }

        return $this->getConfigData('secretapikey', ($quote ? $quote->getStoreId() : null))
            && parent::isAvailable($quote);
    }
    
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }

        return true;
    }

}
