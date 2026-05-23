<?php

namespace Services\PaymentGateway;

class StripeSCA
{

    CONST GATEWAY_NAME = 'Stripe\PaymentIntents';

    private $transaction_data;

    private $gateway;

    private $extra_params = ['paymentMethod', 'payment_intent'];

    private $options = [];

    private $attendee_data = [];

    public function __construct($gateway)
    {
        $this->gateway = $gateway;
        $this->options = [];
    }

    public function setAttendeeData($attendee_data)
    {
        $this->attendee_data = $attendee_data;
    }

    private function createTransactionData($order_total, $order_email, $event)
    {

        $returnUrl = route('showEventCheckoutPaymentReturn', [
            'event_id' => $event->id,
            'is_payment_successful' => 1,
        ]);

        $metadata = [
            'event_id' => (string) $event->id,
            'customer_email' => $order_email,
        ];

        if (!empty($this->attendee_data['order_first_name'])) {
            $metadata['customer_first_name'] = $this->attendee_data['order_first_name'];
        }
        if (!empty($this->attendee_data['order_last_name'])) {
            $metadata['customer_last_name'] = $this->attendee_data['order_last_name'];
        }

        if (!empty($this->attendee_data['attendees'])) {
            $metadata['attendees'] = json_encode($this->attendee_data['attendees'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->transaction_data = [
            'amount' => $order_total,
            'currency' => $event->currency->code,
            'description' => 'Event '. $event->id.' - Order for customer: ' . $order_email,
            'receipt_email' => $order_email,
            'returnUrl' => $returnUrl,
            'confirm' => true,
            'metadata' => $metadata
        ];

        if (!empty($this->options['paymentMethod'])) {
            $this->transaction_data['paymentMethod'] = $this->options['paymentMethod'];
        }

        return $this->transaction_data;
    }

    public function startTransaction($order_total, $order_email, $event)
    {
        $this->createTransactionData($order_total, $order_email, $event);
        $response = $this->gateway->authorize($this->transaction_data)->send();

        return $response;
    }

    public function getTransactionData()
    {
        return $this->transaction_data;
    }

    public function extractRequestParameters($request)
    {
        foreach ($this->extra_params as $param) {
            if (!empty($request->get($param))) {
                $this->options[$param] = $request->get($param);
            }
        }
    }

    public function completeTransaction($data)
    {
        if (array_key_exists('payment_intent', $data)) {
            $intentData = [
                'paymentIntentReference' => $data['payment_intent'],
            ];
        } else {
            $intentData = [
                'paymentIntentReference' => $this->options['payment_intent'],
            ];
        }

        $paymentIntent = $this->gateway->fetchPaymentIntent($intentData);
        $response = $paymentIntent->send();

        if ($response->requiresConfirmation()) {
            $confirmData = $intentData;
            if (!empty($data['returnUrl'])) {
                $confirmData['returnUrl'] = $data['returnUrl'];
            } elseif (!empty($this->transaction_data['returnUrl'])) {
                $confirmData['returnUrl'] = $this->transaction_data['returnUrl'];
            }
            $confirmResponse = $this->gateway->confirm($confirmData)->send();
            if ($confirmResponse->isSuccessful()) {
                $response = $this->gateway->capture($intentData)->send();
            } else {
                $response = $confirmResponse;
            }
        } else {
            $response = $this->gateway->capture($intentData)->send();
        }

        return $response;
    }

    public function getAdditionalData($response)
    {

        $additionalData['payment_intent'] = $response->getPaymentIntentReference();
        return $additionalData;
    }

    public function storeAdditionalData()
    {
        return true;
    }

    public function refundTransaction($order, $refund_amount, $refund_application_fee)
    {

        $request = $this->gateway->refund([
            'transactionReference' => $order->transaction_id,
            'amount' => $refund_amount,
            'refundApplicationFee' => $refund_application_fee
        ]);

        $response = $request->send();

        if ($response->isSuccessful()) {
            $refundResponse['successful'] = true;
        } else {
            $refundResponse['successful'] = false;
            $refundResponse['error_message'] = $response->getMessage();
        }

        return $refundResponse;
    }

}
