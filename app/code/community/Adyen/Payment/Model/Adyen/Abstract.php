<?php

/**
 * Adyen Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category	Adyen
 * @package	Adyen_Payment
 * @copyright	Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
abstract class Adyen_Payment_Model_Adyen_Abstract extends Mage_Payment_Model_Method_Abstract {

    /**
     * Zend_Log debug level
     * @var unknown_type
     */
    const DEBUG_LEVEL = 7;

    const VISIBLE_INTERNAL = 'backend';
    const VISIBLE_CHECKOUT = 'frontend';
    const VISIBLE_BOTH     = 'both';

    private $_helperLog,$_helper;
    protected $_isGateway = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canVoid = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canRefundInvoicePartial = true;

    /** @var Adyen_Payment_Helper_Pci */
    protected $_pciHelper;

    /**
     * Magento Order Object
     * @var unknown_type
     */
    protected $_order;

    /**
     * Module identifiers
     */
    protected $_code = 'adyen_abstract';
    protected $_paymentMethod = 'abstract';

    /**
     * Internal objects and arrays for SOAP communication
     */
    protected $_service = NULL;
    protected $_accountData = NULL;

    /**
     * Payment Modification Request
     * @var unknown_type
     */
    protected $_paymentRequest = NULL;
    protected $_optionalData = NULL;
    protected $_testModificationUrl = 'https://pal-test.adyen.com/pal/adapter/httppost';
    protected $_liveModificationUrl = 'https://pal-live.adyen.com/pal/adapter/httppost';
    protected $_paymentMethodType = 'api';

    public function getPaymentMethodType() {
        return $this->$_paymentMethodType;
    }

    public function __construct()
    {
        $visibleType = $this->getConfigData('visible_type');
        switch ($visibleType) {
            case self::VISIBLE_INTERNAL:
                $this->_canUseCheckout = false;
                $this->_canUseInternal = true;
                break;

            case self::VISIBLE_CHECKOUT:
                $this->_canUseCheckout = true;
                $this->_canUseInternal = false;
                break;

            case self::VISIBLE_BOTH:
                $this->_canUseCheckout = true;
                $this->_canUseInternal = true;
                break;
        }
    }

    const EVENT_AUTHORIZE = 'authorize';
    const EVENT_REFUND = 'refund';
    const EVENT_VOID = 'void';
    const EVENT_CAPTURE = 'capture';
    
    const ERROR_MESSAGE = 'There was an error processing your payment. Please try again.';


    /**
     * @param Varien_Object $payment
     * @param float         $amount
     * @return $this
     */
    public function refund(Varien_Object $payment, $amount) {
        $this->_getHelperLog()->log("refund fx called", "refund");

        $order = $payment->getOrder();
        $pspReference = Mage::getModel('adyen/event')->getOriginalPspReference($order->getIncrementId());

        // if amound is a full refund send a refund/cancelled request so if it is not captured yet it will cancel the order
        $grandTotal = $order->getBaseGrandTotal();

        if($grandTotal == $amount) {
            $order->getPayment()->getMethodInstance()->SendCancelOrRefund($payment, $pspReference);
            return $this;
        } else {
            $order->getPayment()->getMethodInstance()->sendRefundRequest($payment, $amount, $pspReference);
            return $this;
        }

        $this->_getHelperLog()->log("order " . $order->getIncrementId(), " cant' be refund", "refund");
        Mage::throwException('refund ' . $order->getIncrementId() . ': ' . $e->getMessage());
    }

    /**
     * In the backend it means Authorize only
     * @param Varien_Object $payment
     * @param               $amount
     * @return $this
     */
    public function authorize(Varien_Object $payment, $amount) {
        parent::authorize($payment, $amount);
        $payment->setLastTransId($this->getTransactionId())->setIsTransactionPending(true);

        $order = $payment->getOrder();

        // by zero authentication payment is authorised when api responds is succesfull
        if($order->getGrandTotal() == 0) {
            $payment->setIsTransactionPending(false);
        }

        try {

            /*
             * Do not send a email notification when order is created.
             * Only do this on the AUHTORISATION notification.
             * For Boleto send it on order creation
             */
            if($this->getCode() != 'adyen_boleto') {
                $order->setCanSendNewEmailFlag(false);
            }
            $orderId = $order->getQuote()->getReservedOrderId();
            $this->_getHelperLog()->log("sendAuthoriseRequest orderId : " . $orderId . " amount: $amount", "authorise");
            $merchantAccount = trim($this->_getConfigData('merchantAccount'));
            $payment->setIsTransactionPending(true);

                if ($this->getCode() == 'adyen_boleto' || $this->getCode() == 'adyen_cc' || substr($this->getCode(), 0, 14) == 'adyen_oneclick' || $this->getCode() == 'adyen_elv' || $this->getCode() == 'adyen_sepa') {

                    if(substr($this->getCode(), 0, 14) == 'adyen_oneclick') {

                        // set payment method to adyen_oneclick otherwise backend can not view the order
                        $payment->setMethod("adyen_oneclick");

                        $recurringDetailReference = $payment->getAdditionalInformation("recurring_detail_reference");

                        // load agreement based on reference_id (option to add an index on reference_id in database)
                        $agreement = Mage::getModel('sales/billing_agreement')->load($recurringDetailReference, 'reference_id');

                        // agreement could be a empty object
                        if ($agreement && $agreement->getAgreementId() > 0 && $agreement->isValid()) {
                            $agreement->addOrderRelation($order);
                            $agreement->setIsObjectChanged(true);
                            $order->addRelatedObject($agreement);
                            $message = Mage::helper('adyen')->__('Used existing billing agreement #%s.', $agreement->getReferenceId());

                            $comment = $order->addStatusHistoryComment($message);
                            $order->addRelatedObject($comment);
                        }
                    }
                    $_authorizeResponse = $this->_processRequest($payment, $amount, "authorise");

                            //added unique trans ID with merchant and type of request behind pspreference
                            $payment->setLastTransId((string) $merchantAccount . '-A-' . $_authorizeResponse->paymentResult->pspReference)
                                    ->setTransactionId((string) $merchantAccount . '-A-' . $_authorizeResponse->paymentResult->pspReference)
                                    ->setAdyenPspReference((string) $_authorizeResponse->paymentResult->pspReference)
                                    //raw details is very important for trx debugging in order to get needed data from each succesfull response                        
                                    ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $this->_getHelper()->getResponseArray(json_decode(json_encode($_authorizeResponse), true)));

                }

                $payment->setAmount($amount)
                    ->setStatus(self::STATUS_APPROVED)
                    ->setIsTransactionClosed(false)
                    ->setIsTransactionPending(false);

        } catch (Exception $e) {
            $this->_getHelperLog()->log($e->getMessage(), "authorise");
            Mage::throwException($this->_getHelper()->__(self::ERROR_MESSAGE));
        }
        return $this;
    }

    /**
     * In backend it means Authorize && Capture
     * @param $payment
     * @param $amount
     */
    public function capture(Varien_Object $payment, $amount) {
        parent::capture($payment, $amount);
        $payment->setStatus(self::STATUS_APPROVED)
            ->setTransactionId($this->getTransactionId())
            ->setIsTransactionClosed(0);

        // do capture request to adyen
        $order = $payment->getOrder();
        $pspReference = Mage::getModel('adyen/event')->getOriginalPspReference($order->getIncrementId());
        $order->getPayment()->getMethodInstance()->sendCaptureRequest($payment, $amount, $pspReference);

		// processing payment
        try {
            $pspReference = Mage::getModel('adyen/event')->getOriginalPspReference($payment->getOrder()->getIncrementId());
            //fix for unreceived notifications from adyen_hpp payment methods
            if(!$pspReference){
                $pspReference = $payment->getAdyenPspReference();
            }
            $this->writeLog("sendCaptureRequest pspReference : $pspReference amount: $amount");
            $merchantAccount = trim($this->_getConfigData('merchantAccount'));

            if (!$this->_getHelper()->isAutoCapture($payment) && ($this->getCode() == 'adyen_cc' || $this->getCode() == 'adyen_oneclick' || $this->getCode() == 'adyen_hpp' || $this->getCode() == 'adyen_boleto' || $this->getCode() == 'adyen_elv' || $this->getCode() == 'adyen_sepa')) {
                $_captureResponse = $this->_processRequest($payment, $amount, "capture", $pspReference);

                $payment->setTransactionId((string) $merchantAccount . '-C-' . $_captureResponse->captureResult->pspReference)
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $this->_getHelper()->getResponseArray(json_decode(json_encode($_captureResponse), true)));
            }

            $payment->setAmount($amount)
                    ->setShouldCloseParentTransaction($payment->getShouldCloseParentTransaction());
        } catch (Exception $e) {
            $this->_getHelperLog()->log($e->getMessage(), "capture");
            Mage::throwException('capture ' . $incrmentOrderId . ': ' . $e->getMessage());
        }

        return $this;
    }


    public function authorise3d(Varien_Object $payment, $amount) {
        $authorizeResponse = $this->_processRequest($payment, $amount, "authorise3d");
        $responseCode = $authorizeResponse->paymentResult->resultCode;
        return $responseCode;
    }

    public function sendCaptureRequest(Varien_Object $payment, $amount, $pspReference) {
        if (empty($pspReference)) {
            $this->writeLog('oops empty pspReference');
            return $this;
        }
        $this->writeLog("sendCaptureRequest pspReference : $pspReference amount: $amount");
        return $this->_processRequest($payment, $amount, "capture", $pspReference);
    }

    public function sendRefundRequest(Varien_Object $payment, $amount, $pspReference) {
        if (empty($pspReference)) {
            $this->writeLog('oops empty pspReference');
            return $this;
        }
        
		$this->writeLog("sendRefundRequest pspReference : $pspReference amount: $amount");
        try {
            
            /* $canRefundMore to 1 if a partial refound is requested */
            $canRefundMore = $payment->getCreditmemo()->getInvoice()->canRefund();
            $_refundResponse = $this->_processRequest($payment, $amount, "refund", $pspReference);
            $merchantAccount = trim($this->_getConfigData('merchantAccount'));
            
            $this->_getHelperLog()->log($_refundResponse, "refund");
            $payment->setTransactionId((string) $merchantAccount . '-R-' . $_refundResponse->refundResult->pspReference)
                    ->setAmount($amount)
                    ->setIsTransactionClosed(1)
                    ->setShouldCloseParentTransaction(!$canRefundMore)
                    ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $this->_getHelper()->getResponseArray(json_decode(json_encode($_refundResponse), true)));
      
        } catch (Exception $e) {
            $this->_getHelperLog()->log($e->getMessage(), "refund");
            Mage::throwException('refund ' . $payment->getOrder()->getIncrementId() . ': ' . $e->getMessage());
        }

        return $_refundResponse;
    }

    public function SendCancelOrRefund(Varien_Object $payment, $pspReference) {
        if (empty($pspReference)) {
            $this->writeLog('oops empty pspReference');
            return $this;
        }

		$this->writeLog("sendCancelOrRefundRequest pspReference : $pspReference");
        try {
            $_refundResponse = $this->_processRequest($payment, null, "cancel_or_refund", $pspReference);


            $merchantAccount = trim($this->_getConfigData('merchantAccount'));

            $this->_getHelperLog()->log($_refundResponse, "cancel");
            $payment->setTransactionId((string) $merchantAccount . '-R-' . $_refundResponse->cancelOrRefundResult->pspReference)
                    ->setAmount($payment->getOrder()->getBaseGrandTotal())
                    ->setIsTransactionClosed(1)
                    ->setShouldCloseParentTransaction(1)
                    ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $this->_getHelper()->getResponseArray(json_decode(json_encode($_refundResponse), true)));
        } catch (Exception $e) {
            $this->_getHelperLog()->log($e->getMessage(), "cancel");
            Mage::throwException('refund ' . $payment->getOrder()->getIncrementId() . ': ' . $e->getMessage());
        }

        return $_refundResponse;
    }

    /**
     * Process the request here
     * @param Varien_Object $payment
     * @param unknown_type $amount
     * @param unknown_type $request
     * @param unknown_type $responseData
     */
    protected function _processRequest(Varien_Object $payment, $amount, $request, $pspReference = null) {

        if (Mage::app()->getStore()->isAdmin()) {
            $storeId = $payment->getOrder()->getStoreId();
        } else {
            $storeId = null;
        }

        $this->_initService($storeId);
        $merchantAccount = trim($this->_getConfigData('merchantAccount', 'adyen_abstract', $storeId));
        $recurringType = $this->_getConfigData('recurringtypes', 'adyen_abstract', $storeId);
        $recurringPaymentType = $this->_getConfigData('recurring_payment_type', 'adyen_oneclick', $storeId);
        $enableMoto = (int) $this->_getConfigData('enable_moto', 'adyen_cc', $storeId);
        $modificationResult = Mage::getModel('adyen/adyen_data_modificationResult');
        $requestData = Mage::getModel('adyen/adyen_data_modificationRequest')
            ->create($payment, $amount, $merchantAccount, $pspReference);

        switch ($request) {
            case "authorise":
                $requestData = Mage::getModel('adyen/adyen_data_paymentRequest')
                    ->create($payment, $amount, $this->_paymentMethod, $merchantAccount,$recurringType, $recurringPaymentType, $enableMoto);

                $response = $this->_service->authorise(array('paymentRequest' => $requestData));
                break;
            case "authorise3d":
                $requestData = Mage::getModel('adyen/adyen_data_paymentRequest3d')
                    ->create($payment, $merchantAccount);

                $response = $this->_service->authorise3d(array('paymentRequest3d' => $requestData));
                break;
            case "capture":
                $response = $this->_service->capture(array(
                    'modificationRequest' => $requestData,
                    'modificationResult' => $modificationResult));
                break;
            case "refund":
                $response = $this->_service->refund(array(
                    'modificationRequest' => $requestData,
                    'modificationResult' => $modificationResult));
                break;
            case "cancel_or_refund":
                $response = $this->_service->cancelorrefund(array(
                    'modificationRequest' => $requestData,
                    'modificationResult' => $modificationResult));
                break;
        }


        // log the request
        Mage::getResourceModel('adyen/adyen_debug')->assignData($response);
        $this->_debugAdyen();
        $this->_getHelperLog()->log($this->_pci()->obscureSensitiveData($requestData),"$request");


        if (!empty($response)) {
            // log the result
            $this->_getHelperLog()->log("Response from Adyen:", "$request");
            $this->_getHelperLog()->log($this->_pci()->obscureSensitiveData($response), self::DEBUG_LEVEL, "$request.log", true);

            $this->_processResponse($payment, $response, $request);
        }

        /*
         * clear the cache for recurring payments so new card will be added
         */
        $cacheKey = $merchantAccount . "|" . $payment->getOrder()->getCustomerId() . "|" . $recurringType;
        Mage::app()->getCache()->remove($cacheKey);

        //return $this;
        return $response;
    }

    /**
     * @desc authorise response
     * Process the response of the soap
     *
     * @param Varien_Object $payment
     * @param stdClass      $response
     * @param null          $request
     *
     * @return $this
     * @todo Add comment with checkout Authorised
     */
    protected function _processResponse(Varien_Object $payment, $response, $request = null) {
        if (!($response instanceof stdClass)) {
            return false;
        }
        switch ($request) {
            case "authorise":
            case "authorise3d":
                if($response->paymentResult->fraudResult) {
                    $fraudResult = $response->paymentResult->fraudResult->accountScore;
                    $payment->setAdyenTotalFraudScore($fraudResult);
                }
                $responseCode = $response->paymentResult->resultCode;
                $pspReference = $response->paymentResult->pspReference;

                // save pspreference to match with notification
                $payment->setAdyenPspReference($pspReference);

                break;
            case "refund":
                $responseCode = $response->refundResult->response;
                $pspReference = $response->refundResult->pspReference;
                break;
            case "cancel_or_refund":
                $responseCode = $response->cancelOrRefundResult->response;
                $pspReference = $response->cancelOrRefundResult->pspReference;
                break;
            case "capture":
                $responseCode = $response->captureResult->response;
                $pspReference = $response->captureResult->pspReference;
                break;
            default:
                $responseCode = null;
                $this->writeLog("Unknown data type by Adyen");
                break;
        }
        switch ($responseCode) {
            case "RedirectShopper":
                $payment->setAdditionalInformation('paRequest', $response->paymentResult->paRequest);
                $payment->setAdditionalInformation('md', $response->paymentResult->md);
                $payment->setAdditionalInformation('issuerUrl', $response->paymentResult->issuerUrl);
                Mage::getSingleton('customer/session')->setRedirectUrl("adyen/process/validate3d");
                $this->_addStatusHistory($payment, $responseCode, $pspReference, $this->_getConfigData('order_status'));
                break;
			case "Cancelled": //added for FRAUD-CANCELLED refusal reasons
            case "Refused":

                if($response->paymentResult->refusalReason) {

                    $refusalReason = $response->paymentResult->refusalReason;
                    switch($refusalReason) {
                        case "Transaction Not Permitted":
                            $errorMsg = $this->_getHelper()->__('The transaction is not permitted.');
                            break;
                        case "CVC Declined":
                            $errorMsg = $this->_getHelper()->__('Declined due to the Card Security Code(CVC) being incorrect. Please check your CVC code!');
                            break;
                        case "Restricted Card":
                            $errorMsg = $this->_getHelper()->__('The card is restricted.');
                            break;
                        case "803 PaymentDetail not found":
                            $errorMsg = $this->_getHelper()->__('The payment is REFUSED because the saved card is removed. Please try an other payment method.');
                            break;
                        default:
                            $errorMsg = $this->_getHelper()->__('The payment is REFUSED by Adyen.');
                            break;
                    }
                } else {
                    $errorMsg = $this->_getHelper()->__('The payment is REFUSED by Adyen.');
                }

                Adyen_Payment_Exception::throwException($errorMsg);
                break;
            case "Authorised":
                $this->_addStatusHistory($payment, $responseCode, $pspReference, $this->_getConfigData('order_status'));
                break;
            case "Received": // boleto payment
                $pdfUrl = null;
                $additionalDataResults = $response->paymentResult->additionalData->entry;
                foreach($additionalDataResults as $additionalDataResult) {
                    if($additionalDataResult->key == "boletobancario.url") {
                        $pdfUrl = $additionalDataResult->value;
                    }
                }
                $this->_addStatusHistory($payment, $responseCode, $pspReference, false, $pdfUrl);
                break;
            case '[capture-received]':
            case '[refund-received]':
            case '[cancelOrRefund-received]':
                $this->_addStatusHistory($payment, $responseCode, $pspReference);
                break;
            case "Error":
                $errorMsg = $this->_getHelper()->__('System error, please try again later');
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
            default:
                $this->writeLog("Unknown data type by Adyen");
                break;
        }

        //save all response data for a pure duplicate detection
        Mage::getModel('adyen/event')
            ->setPspReference($pspReference)
            ->setAdyenEventCode($responseCode)
            ->setAdyenEventResult($responseCode)
            ->setIncrementId($payment->getOrder()->getIncrementId())
            ->setPaymentMethod($this->getInfoInstance()->getCcType())
            ->setCreatedAt(now())
            ->saveData()
        ;
        return $this;
    }

    /**
     * @since 0.0.3
     * @param Varien_Object $payment
     * @param unknown_type $request
     * @param unknown_type $pspReference
     */
    protected function _addStatusHistory(Varien_Object $payment, $responseCode, $pspReference, $status = false, $boletoPDF = null) {
        
    	if($boletoPDF){
    		$payment->getOrder()->setAdyenBoletoPdf($boletoPDF);
        }

        $type = 'Adyen Result URL Notification(s):';
        $comment = Mage::helper('adyen')->__('%s <br /> authResult: %s <br /> pspReference: %s <br /> paymentMethod: %s', $type, $responseCode, $pspReference, "");
        $payment->getOrder()->setAdyenEventCode($responseCode);
        $payment->getOrder()->addStatusHistoryComment($comment, $status);
        $payment->setAdyenEventCode($responseCode);
        return $this;
    }

    /**
     * Format price
     * @param unknown_type $amount
     * @param unknown_type $format
     */
    protected function _numberFormat($amount, $format = 2) {
        return (int) number_format($amount, $format, '', '');
    }

    /**
     * @desc Get SOAP client
     * @return Adyen_Payment_Model_Adyen_Abstract
     */
    protected function _initService($storeId = null) {
        $accountData = $this->getAccountData($storeId);
        $wsdl = $accountData['url']['wsdl'];
        $location = $accountData['url']['location'];
        $login = $accountData['login'];
        $password = $accountData['password'];
        $classmap = new Adyen_Payment_Model_Adyen_Data_Classmap();
        try {
            $this->_service = new SoapClient($wsdl, array(
                'login' => $login,
                'password' => $password,
                'soap_version' => SOAP_1_1,
                'style' => SOAP_DOCUMENT,
                'use' => SOAP_LITERAL,
                'location' => $location,
                'trace' => 1,
                'classmap' => $classmap));
        } catch (SoapFault $fault) {
            $this->writeLog("Adyen SOAP Fault: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})");
            Mage::throwException($this->_getHelper()->__('Can not connect payment service. Please try again later.'));
        }
        return $this;
    }

    /**
     * @desc soap urls
     * @return string
     */
    protected function _getAdyenUrls($storeId = null) {
        $test = array(
            'location' => "https://pal-test.adyen.com/pal/servlet/soap/Payment",
            'wsdl' => Mage::getModuleDir('etc', 'Adyen_Payment') . DS . 'Payment.wsdl'
        );
        $live = array(
            'location' => "https://pal-live.adyen.com/pal/servlet/soap/Payment",
            'wsdl' => Mage::getModuleDir('etc', 'Adyen_Payment') . DS . 'Payment.wsdl'
        );
        if ($this->getConfigDataDemoMode($storeId)) {
            return $test;
        } else {
            return $live;
        }
    }

    /**
     * @desc Testing purposes only
     */
    protected function _debugAdyen() {
        $this->writeLog("Request Headers: ");
        $this->writeLog($this->_pci()->obscureSensitiveData($this->_service->__getLastRequestHeaders()));
        $this->writeLog("Request:");
        $this->writeLog($this->_pci()->obscureSensitiveData(($this->_service->__getLastRequest())));
        $this->writeLog("Response Headers");
        $this->writeLog($this->_pci()->obscureSensitiveData(($this->_service->__getLastResponseHeaders())));
        $this->writeLog("Response");
        $this->writeLog($this->_pci()->obscureSensitiveData(($this->_service->__getLastResponse())));
    }

    /**
     * @return Adyen_Payment_Helper_Pci
     */
    protected function _pci()
    {
        if (!isset($this->_pciHelper)) {
            $this->_pciHelper = Mage::helper('adyen/pci');
        }
        return $this->_pciHelper;
    }

    /**
     * Adyen User Account Data
     */
    public function getAccountData($storeId = null) {
        $url = $this->_getAdyenUrls($storeId);
        $wsUsername = $this->getConfigDataWsUserName($storeId);
        $wsPassword = $this->getConfigDataWsPassword($storeId);
        $account = array(
            'url' => $url,
            'login' => $wsUsername,
            'password' => $wsPassword
        );
        return $account;
    }

    /**
     * @desc Adyen log fx
     * @param type $str
     * @return type
     */
    public function writeLog($str) {
        $this->_getHelperLog()->log($this->_pci()->obscureSensitiveData($str), "notification");
        return false;
    }

    /**
     * @status poor programming practises modification_result model not exist!
     * @param unknown_type $responseBody
     */
    public function getModificationResult($responseBody) {
        $result = new Varien_Object();
        $valArray = explode('&', $responseBody);
        foreach ($valArray as $val) {
            $valArray2 = explode('=', $val);
            $result->setData($valArray2[0], urldecode($valArray2[1]));
        }
        return $result;
    }

    public function getModificationUrl() {
        if ($this->getConfigDataDemoMode()) {
            return $this->_testModificationUrl;
        }
        return $this->_liveModificationUrl;
    }

    public function getConfigDataAutoCapture() {
        if (!$this->_getConfigData('auto_capture') || $this->_getConfigData('auto_capture') == 0) {
            return false;
        }
        return true;
    }

    public function getConfigDataAutoInvoice() {
        if (!$this->_getConfigData('auto_invoice') || $this->_getConfigData('auto_invoice') == 0) {
            return false;
        }
        return true;
    }

    public function getConfigDataAdyenCapture() {
        if ($this->_getConfigData('adyen_capture') && $this->_getConfigData('adyen_capture') == 1) {
            return true;
        }
        return false;
    }

    public function getConfigDataAdyenRefund() {
        if ($this->_getConfigData('adyen_refund') == 1) {
            return true;
        }
        return false;
    }

    /**
     * Return true if the method can be used at this time
     * @since 0.1.0.3r1
     * @return bool
     */
    public function isAvailable($quote=null) {
        if (!parent::isAvailable($quote)) {
            return false;
        }
        if (!is_null($quote)) {
            if ($this->_getConfigData('allowspecific', $this->_code)) {
                $country = $quote->getShippingAddress()->getCountry();
                $availableCountries = explode(',', $this->_getConfigData('specificcountry', $this->_code));
                if (!in_array($country, $availableCountries)) {
                    return false;
                }
            }

            if ($quote->getGrandTotal() < 0.0001) {
                return false;
            }
        }
        return true;
    }

    /**
     * @desc Give Default settings
     * @example $this->_getConfigData('demoMode','adyen_abstract')
     * @since 0.0.2
     * @param string $code
     */
    protected function _getConfigData($code, $paymentMethodCode = null, $storeId = null) {
        return $this->_getHelper()->_getConfigData($code, $paymentMethodCode, $storeId);
    }

    /**
     * Used via Payment method.Notice via configuration ofcourse Y or N
     * @return boolean true on demo, else false
     */
    public function getConfigDataDemoMode($storeId = null) {
        return $this->_getHelper()->getConfigDataDemoMode($storeId);
    }

    public function getConfigDataWsUserName($storeId = null) {
        return $this->_getHelper()->getConfigDataWsUserName($storeId);
    }

    public function getConfigDataWsPassword($storeId = null) {
        return $this->_getHelper()->getConfigDataWsPassword($storeId);
    }
    
    public function getConfigDataReportWsUserName($storeId = null) {
        return $this->_getHelper()->getConfigDataReportWsUserName($storeId);
    }

    public function getConfigDataReportWsPassword($storeId = null) {
        return $this->_getHelper()->getConfigDataReportWsPassword($storeId);
    }

    public function getAvailableBoletoTypes() {
        $types = Mage::helper('adyen')->getBoletoTypes();
        $availableTypes = $this->_getConfigData('boletotypes', 'adyen_boleto');
        if ($availableTypes) {
            $availableTypes = explode(',', $availableTypes);
            foreach ($types as $code => $name) {
                if (!in_array($code, $availableTypes)) {
                    unset($types[$code]);
                }
            }
        }
        return $types;
    }

    public function getConfigPaymentAction() {
        return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE;
    }

    protected function _initOrder() {
        if (!$this->_order) {
            $paymentInfo = $this->getInfoInstance();
            $this->_order = $paymentInfo->getOrder();
        }
        return $this;
    }

    public function canCreateBillingAgreement()
    {
        if (! $this->_canCreateBillingAgreement) {
            return false;
        }
        $recurringType = $this->_getConfigData('recurringtypes');
        if($recurringType == "ONECLICK" || $recurringType == "ONECLICK,RECURRING") {
            return true;
        }
        return false;
    }


	protected function _getHelperLog() {
        if (!$this->_helperLog) {
            $this->_helperLog = Mage::helper('adyen/log');
        }
        return $this->_helperLog;
    }

    protected function _getHelper() {
        if (!$this->_helper) {
            $this->_helper = Mage::helper('adyen');
        }
        return $this->_helper;
    }


    public function getBillingAgreementCollection()
    {
        $customerId = $this->getInfoInstance()->getQuote()->getCustomerId();
        return Mage::getModel('adyen/billing_agreement')
            ->getAvailableCustomerBillingAgreements($customerId)
            ->addFieldToFilter('method_code', $this->getCode());
    }


    /**
     * @return Adyen_Payment_Model_Api
     */
    protected function _api()
    {
        return Mage::getSingleton('adyen/api');
    }

    /**
     * Create billing agreement by token specified in request
     *
     * @param Mage_Payment_Model_Billing_AgreementAbstract $agreement
     * @return Exception
     */
    public function placeBillingAgreement(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        Mage::throwException('Not yet implemented.');
        return $this;
    }


    /**
     * Init billing agreement
     *
     * @param Mage_Payment_Model_Billing_AgreementAbstract $agreement
     * @return Exception
     */
    public function initBillingAgreementToken(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        Mage::throwException('Not yet implemented.');
        return $this;
    }

    /**
     * Update billing agreement status
     *
     * @param Adyen_Payment_Model_Billing_Agreement|Mage_Payment_Model_Billing_AgreementAbstract $agreement
     *
     * @return $this
     * @throws Exception
     * @throws Mage_Core_Exception
     */
    public function updateBillingAgreementStatus(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        Mage::dispatchEvent('adyen_payment_update_billing_agreement_status', array('agreement' => $agreement));

        $targetStatus = $agreement->getStatus();
        $adyenHelper = Mage::helper('adyen');

        if ($targetStatus == Mage_Sales_Model_Billing_Agreement::STATUS_CANCELED) {
            try {
                $this->_api()->disableRecurringContract(
                    $agreement->getReferenceId(),
                    $agreement->getCustomerReference(),
                    $agreement->getStoreId()
                );
            } catch (Adyen_Payment_Exception $e) {
                Mage::throwException($adyenHelper->__(
                    "Error while disabling Billing Agreement #%s: %s", $agreement->getReferenceId(), $e->getMessage()
                ));
            }
        } else {
            throw new Exception(Mage::helper('adyen')->__(
                'Changing billing agreement status to "%s" not yet implemented.', $targetStatus
            ));
        }
        return $this;
    }


    /**
     * Retrieve billing agreement customer details by token
     *
     * @param Adyen_Payment_Model_Billing_Agreement|Mage_Payment_Model_Billing_AgreementAbstract $agreement
     * @return array
     */
    public function getBillingAgreementTokenInfo(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        $recurringContractDetail = $this->_api()->getRecurringContractDetail(
            $agreement->getCustomerReference(),
            $agreement->getReferenceId()
        );

        if (! $recurringContractDetail) {
            Adyen_Payment_Exception::throwException(Mage::helper('adyen')->__(
                'The recurring contract (%s) could not be retrieved', $agreement->getReferenceId()
            ));
        }

        $agreement->parseRecurringContractData($recurringContractDetail);

        return $recurringContractDetail;
    }
}
