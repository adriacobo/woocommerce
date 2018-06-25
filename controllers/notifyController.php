<?php

if (!defined('ABSPATH')) {
    exit;
}

class WcPaylaterNotify extends WcPaylaterGateway
{
    /**
     * @var $string
     */
    public $origin;

    /**
     * @return mixed
     */
    public function getOrigin()
    {
        return $this->origin;
    }

    /**
     * @param mixed $origin
     */
    public function setOrigin($origin)
    {
        $this->origin = $origin;
    }

    /**
     * @var $string
     */
    public $order;

    /**
     * @return mixed
     */
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * @param $order
     *
     * @throws Exception
     */
    public function setOrder($order)
    {
        if (get_class($order)=='WC_Order') {
            $this->order = $order;
        } else {
            throw new Exception('Invalid order');
        }
    }

    /**
     * Validation vs PmtClient
     *
     * @return array
     */
    public function processInformation()
    {
        require_once(__ROOT__.'/vendor/autoload.php');
        global $woocommerce;
        $result = array('notification_error'=>true,'notification_message'=>'ERROR - Fallo en el proceso de pago');
        if (!$this->getOrder()->get_id()) {
            $result['notification_message'] = 'ERROR - La orden no existe en esta tienda';
            $result['notification_error'] = true;
        } else {
            $order         = new WC_Order($this->getOrder()->get_id());
            $validStatus   = array('on-hold', 'pending', 'failed');
            $isValidStatus = apply_filters(
                'woocommerce_valid_order_statuses_for_payment_complete',
                $validStatus,
                $this
            );

            if (!$order->has_status($isValidStatus)) {
                $result['notification_message'] = 'ERROR - El pago ya ha sido procesado en PagaMasTarde ';
                $result['notification_error']   = false;
            } else {
                $cfg       = get_option('woocommerce_paylater_settings');
                $pmtClient = new \PagaMasTarde\PmtApiClient($cfg['secret_key']);
                $payed     = $pmtClient->charge()->validatePaymentForOrderId($this->getOrder()->get_id());
                if (!$payed) {
                    $result['notification_message'] = 'ERROR - El pago no existe en PagaMasTarde ';
                    $result['notification_error']   = true;
                } else {
                    $payments     = $pmtClient->charge()->getChargesByOrderId($this->getOrder()->get_id());
                    $latestCharge = array_shift($payments);
                    $pmtAmount    = $latestCharge->getAmount();
                    file_put_contents('/tmp/woocommerce.txt', "\n".$pmtAmount, 8);
                    file_put_contents('/tmp/woocommerce.txt', "\n".(100 * intval($order->get_total())), 8);
                    if ($pmtAmount == (100 * intval($order->get_total()))) {
                        $paymentResult = $order->payment_complete();
                        if ($paymentResult) {
                            $order->add_order_note($this->origin);
                            $order->reduce_order_stock();
                            $woocommerce->cart->empty_cart();
                            $result['notification_error'] = false;
                        } else {
                            $this->setToFailed();
                            $result['notification_message'] = 'ERROR - Pago incompleto ';
                        }
                    } else {
                        $result['notification_message'] = 'ERROR - La cantidad del pedido es incorrecta ';
                        $this->setToFailed();
                    }
                }
            }
        }
        return $result;
    }

    /**
     * @param $parsed_url
     *
     * @return string
     */
    private function unparseUrl($parsed_url)
    {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        $path     = $parsed_url['path'];
        return $scheme . $host . $port . $path . $query . $fragment;
    }

    /**
     * Set order to failed
     * @return bool
     */
    public function setToFailed()
    {
        $order = $this->getOrder();
        if (get_class($order)=='WC_Order') {
            $order->update_status('failed', __('Error en el pago con Paga+Tarde', 'woocommerce'));
        }
        return true;
    }

    /**
     * Set order to pending
     * @return bool
     */
    public function setToPending()
    {
        $order = $this->getOrder();
        if (get_class($order)=='WC_Order') {
            $order->update_status('pending', __('Pending payment', 'woocommerce'));
        }
        return true;
    }
}