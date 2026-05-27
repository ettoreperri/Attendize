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
        $paymentIntentId = $response->getPaymentIntentReference();
        $additionalData['payment_intent'] = $paymentIntentId;

        // Get the Charge ID from the response data for refunds
        // Stripe no longer includes charges by default - retrieve with expand
        $chargeId = null;
        $responseData = $response->getData();

        // Try to get it from the response first (may be present in older API versions)
        if (is_object($responseData) && !empty($responseData->charges->data[0]->id)) {
            $chargeId = $responseData->charges->data[0]->id;
        } elseif (is_array($responseData) && !empty($responseData['charges']['data'][0]['id'])) {
            $chargeId = $responseData['charges']['data'][0]['id'];
        } elseif (is_object($responseData) && !empty($responseData->latest_charge)) {
            $chargeId = is_object($responseData->latest_charge)
                ? $responseData->latest_charge->id
                : $responseData->latest_charge;
        }

        // If not found in response, retrieve from Stripe API with expand
        if (!$chargeId && $paymentIntentId) {
            try {
                $stripe = new \Stripe\StripeClient($this->gateway->getApiKey());
                $pi = $stripe->paymentIntents->retrieve(
                    $paymentIntentId,
                    ['expand' => ['latest_charge']]
                );
                if (!empty($pi->latest_charge->id)) {
                    $chargeId = $pi->latest_charge->id;
                } elseif (!empty($pi->latest_charge) && is_string($pi->latest_charge)) {
                    $chargeId = $pi->latest_charge;
                }
            } catch (\Exception $e) {
                // Charge ID will remain null; refund will handle it via payment_intent
            }
        }

        if ($chargeId) {
            $additionalData['transaction_id'] = $chargeId;
        }

        return $additionalData;
    }

    public function storeAdditionalData()
    {
        return true;
    }

    public function refundTransaction($order, $refund_amount, $refund_application_fee)
    {
        if (!empty($order->transaction_id)) {
            $refundData = [
                'amount' => $refund_amount,
                'refundApplicationFee' => $refund_application_fee,
                'transactionReference' => $order->transaction_id,
            ];

            $request = $this->gateway->refund($refundData);
            $response = $request->send();

            if ($response->isSuccessful()) {
                $refundResponse['successful'] = true;
            } else {
                $refundResponse['successful'] = false;
                $refundResponse['error_message'] = $response->getMessage();
            }

            return $refundResponse;
        }

        if (!empty($order->payment_intent)) {
            // Retrieve Charge ID from PaymentIntent, store it, and refund via Stripe SDK
            try {
                $stripe = new \Stripe\StripeClient($this->gateway->getApiKey());
                $paymentIntent = $stripe->paymentIntents->retrieve(
                    $order->payment_intent,
                    ['expand' => ['latest_charge', 'charges']]
                );

                // Try latest_charge first (newer API), then fall back to charges array
                $chargeId = null;
                if (!empty($paymentIntent->latest_charge->id)) {
                    $chargeId = $paymentIntent->latest_charge->id;
                } elseif (!empty($paymentIntent->charges->data[0]->id)) {
                    $chargeId = $paymentIntent->charges->data[0]->id;
                }

                if ($chargeId) {
                    $order->transaction_id = $chargeId;
                    $order->save();
                }

                // Stripe API requires amounts in cents (smallest currency unit)
                $refund = $stripe->refunds->create([
                    'payment_intent' => $order->payment_intent,
                    'amount' => intval($refund_amount * 100),
                ]);

                $refundResponse['successful'] = true;
                return $refundResponse;
            } catch (\Exception $e) {
                $refundResponse['successful'] = false;
                $refundResponse['error_message'] = $e->getMessage();
                return $refundResponse;
            }
        }

        $refundResponse['successful'] = false;
        $refundResponse['error_message'] = 'No transaction ID or payment intent available for refund.';
        return $refundResponse;
    }

}
