<?php

require 'vendor/autoload.php';

use \Solinor\PaymentHighway\FormBuilder;
use Solinor\PaymentHighway\Model\Sca\ReturnUrls;
use \Solinor\PaymentHighway\PaymentApi;
use Solinor\PaymentHighway\Security\SecureSigner;
use \Solinor\PaymentHighway\Model\Token;
use \Solinor\PaymentHighway\Model\Request\Transaction;
use \Solinor\PaymentHighway\Model\Request\CustomerInitiatedTransaction;
use \Solinor\PaymentHighway\Model\Sca\StrongCustomerAuthentication;

class WC_Payment_Highway_Forms {
    /**
     * @var WC_Order
     */
    private $order;
    private $options;
    private $signatureKeyId;
    private $signatureSecret;
    private $account;
    private $merchant;
    private $currency;
    private $serviceUrl;
    private $language;
    private $secureSigner;
    private $paymentApi;
    private $logger;
    private $acceptCvcRequired;

    /**
     * @param WC_Logger $logger
     * @param array $options
     */
    public function __construct(WC_Logger $logger, $options = array() ) {
        $this->logger = $logger;
        $this->options         = get_option( 'woocommerce_payment_highway_settings', $options );
        $this->signatureKeyId  = $this->options['api_key_id'];
        $this->signatureSecret = $this->options['api_key_secret'];
        $this->account         = $this->options['sph_account'];
        $this->merchant        = $this->options['sph_merchant'];
        $this->currency        = get_woocommerce_currency();
        $this->serviceUrl      = $this->options['sph_url'];
        $this->language        = $this->options['sph_locale'];
        $this->acceptCvcRequired = $this->options['accept_cvc_required'] === 'yes' ? 'true' : 'false';
        $this->secureSigner    = new SecureSigner( $this->signatureKeyId, $this->signatureSecret );
        $this->paymentApi      = new PaymentApi( $this->serviceUrl, $this->signatureKeyId, $this->signatureSecret, $this->account, $this->merchant );
    }


    /**
     * @param $returnUrls array Array of return urls [successUrl, failureUrl, cancelUrl]
     *
     * @return FormBuilder $form
     */
    private function formBuilder( $returnUrls ) {
        return new FormBuilder( "GET", $this->signatureKeyId, $this->signatureSecret, $this->account,
            $this->merchant, $this->serviceUrl, $returnUrls['successUrl'], $returnUrls['failureUrl'],
            $returnUrls['cancelUrl'], $this->language );
    }

    /**
     * @param string $successSuffix
     *
     * @return array
     */
    private function createCheckoutReturnUrls( $successSuffix = '' ) {
        $checkout_url = wc_get_checkout_url();

        $successUrl = $this->order->get_checkout_order_received_url();
        if ( $successSuffix !== '' ) {
            $successUrl = $this->addQueryParameter($successUrl, $successSuffix);
        }
        $failureUrl = $checkout_url;
        $cancelUrl  = $checkout_url;

        return array(
            'successUrl' => $successUrl,
            'failureUrl' => $failureUrl,
            'cancelUrl'  => $cancelUrl,
        );
    }

    /**
     * @param string $successSuffix
     *
     * @param string $failureSuffix
     *
     * @return array
     */
    private function createAddCardUrls( $successSuffix = '', $failureSuffix = '' ) {
        $successUrl = get_permalink();
        if ( $successSuffix !== '' ) {
            $successUrl = $this->addQueryParameter($successUrl, $successSuffix);
        }
        $failureUrl = get_permalink();
        if( $failureSuffix !== '' ) {
            $failureUrl = $this->addQueryParameter($failureUrl, $failureSuffix);
        }
        $cancelUrl  = $failureUrl;

        return array(
            'successUrl' => $successUrl,
            'failureUrl' => $failureUrl,
            'cancelUrl'  => $cancelUrl,
        );
    }

    private function addQueryParameter($baseUrl, $parameter) {
        return $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . $parameter;
    }

    /**
     * @param $orderId string Order id
     *
     * @return string Redirect location
     */
    public function addCardAndPaymentForm( $orderId ) {
        $this->order     = new WC_Order( $orderId );

        $amount      = WC_Gateway_Payment_Highway::get_ph_amount($this->order->get_total());
        $description = $orderId . ': ' . $this->getOrderItemsAsString();
        $form        = $this->formBuilder( $this->createCheckoutReturnUrls( "paymenthighway_payment_success" ) )
            ->generateAddCardAndPaymentParameters( $amount, $this->currency, $orderId, $description );

        return $form->getAction() . '?' . http_build_query( $form->getParameters() );
    }

    /**
     * @param $orderId string Order ID
     *
     * @return string Redirect location
     */
    public function paymentForm($orderId) {
        $this->order     = new WC_Order( $orderId );

        $amount      = WC_Gateway_Payment_Highway::get_ph_amount($this->order->get_total());
        $description = $orderId . ': ' . $this->getOrderItemsAsString();
        $form        = $this->formBuilder( $this->createCheckoutReturnUrls( "paymenthighway_payment_success" ) )
            ->generatePaymentParameters( $amount, $this->currency, $orderId, $description );

        return $form->getAction() . '?' . http_build_query( $form->getParameters() );
    }

    private function getOrderItemsAsString() {
        $arr = array();
        foreach ($this->order->get_items() as $item) {
            $arr[] = $item['qty'] . "x " . $item['name'];
        }
        return implode(", ", $arr);
    }

    /**
     * @param boolean $fromCheckoutForm
     *
     * @param null $orderId
     *
     * @return string Redirect location
     */
    public function addCardForm($fromCheckoutForm = false, $orderId = null) {
        if($fromCheckoutForm) {
            $this->order = new WC_Order($orderId);

            $returnUrls = $this->createCheckoutReturnUrls( "paymenthighway_payment_success&add-card-order-id=" . $orderId );
        }
        else {
            $returnUrls = $this->createAddCardUrls( 'paymenthighway_add_card_success', 'paymenthighway_add_card_failure' );
        }
        $form = $this->formBuilder( $returnUrls )
            ->generateAddCardParameters($this->acceptCvcRequired);

        return $form->getAction() . '?' . http_build_query( $form->getParameters() );
    }

    /**
     * @param $array
     *
     * @return bool
     */
    public function verifySignature( $array ) {
        try {
            $this->secureSigner->validateFormRedirect( $array );
        } catch ( Exception $e ) {
            return false;
        }

        return true;
    }

    /**
     * @param $transactionId
     * @param $amount
     * @param $currency
     *
     * @return \Httpful\Response
     */
    public function commitPayment( $transactionId, $amount, $currency ) {
        return $this->paymentApi->commitFormTransaction( $transactionId, $amount, $currency );
    }

    /**
     * @param $tokenizeId
     *
     * @return \Httpful\Response
     */
    public function tokenizeCard( $tokenizeId ) {
        return $this->paymentApi->tokenize( $tokenizeId );
    }

    /**
     * @param $transactionId
     * @param $amount
     *
     * @return \Httpful\Response
     */
    public function revertPayment($transactionId, $amount) {
        return $this->paymentApi->revertTransaction($transactionId, $amount);
    }

    /**
     * Customer initiated transaction
     *
     * @param $token
     * @param WC_Order $order
     * @param $amount
     * @param $currency
     *
     * @return \Httpful\Response
     */
    public function payCitWithToken($token, $order, $amount, $currency) {
        $cardToken = new Token($token);
        $this->setOrderIfNull($order);
        $returnUrlsArray = $this->createCheckoutReturnUrls( "paymenthighway_payment_success" );
        $returnUrls = new ReturnUrls($returnUrlsArray['successUrl'], $returnUrlsArray['failureUrl'], $returnUrlsArray['cancelUrl']);
        $strongCustomerAuthentication = new StrongCustomerAuthentication($returnUrls);
        $transaction = new CustomerInitiatedTransaction($cardToken, $amount, $currency, $strongCustomerAuthentication, true, $order->get_order_number());
        $initResponse = $this->paymentApi->initTransaction();
        $initResponseObject = json_decode( $initResponse );
        if ( $initResponseObject->result->code !== WC_Gateway_Payment_Highway::PH_REQUEST_SUCCESSFUL ) {
            $this->logger->alert("Error while initializing transaction");
            return $initResponse;
        }
        $order->set_transaction_id($initResponseObject->id);
        return $this->paymentApi->chargeCustomerInitiatedTransaction($initResponseObject->id, $transaction);
    }

    /**
     * Set order if not already set
     *
     * @param $order
     */
    private function setOrderIfNull($order) {
        if(is_null($this->order)){
            $this->order = $order;
        }
    }

    /**
     * Merchant initiated transaction
     *
     * @param $token
     * @param WC_Order $order
     * @param $amount
     * @param $currency
     *
     * @return \Httpful\Response
     */
    public function payMitWithToken($token, $order, $amount, $currency) {
        $cardToken = new Token($token);
        $transaction = new Transaction($cardToken, $amount, $currency, true, $order->get_order_number());
        $initResponse = $this->paymentApi->initTransaction();
        $initResponseObject = json_decode( $initResponse );
        if ( $initResponseObject->result->code !== WC_Gateway_Payment_Highway::PH_REQUEST_SUCCESSFUL ) {
            $this->logger->alert("Error while initializing transaction");
            return $initResponse;
        }
        $order->set_transaction_id($initResponseObject->id);
        return $this->paymentApi->chargeMerchantInitiatedTransaction($initResponseObject->id, $transaction);
    }
}
