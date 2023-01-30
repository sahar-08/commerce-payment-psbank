<?php

namespace Commerce\Payments;

class PsbankPayment extends Payment implements \Commerce\Interfaces\Payment
{
    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('Psbank');
        $this->params = $params;
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('token')) && (empty($this->getSetting('login')) || empty($this->getSetting('password')))) {
            return '<span class="error" style="color: red;">' . $this->lang['psbank.error_empty_token_and_login_password'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $order_id = $order['id'];
        $currency = ci()->currency->getCurrency($order['currency']);

        $amount = ci()->currency->convert($order['amount'], $currency['code'], 'RUB');

        try {
            $payment = $this->createPayment($order['id'], $amount);
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Failed to create payment: ' . $e->getMessage() . '<br>Data: <pre>' . htmlentities(print_r($order, true)) . '</pre>', 'Commerce psbank Payment');
            return false;
        }

        $customer = [];

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = $order['email'];
        }

        if (!empty($order['phone'])) {
            $phone = preg_replace('/[^0-9]+/', '', $order['phone']);
            $phone = preg_replace('/^8/', '7', $phone);

            if (preg_match('/^7\d{10}$/', $phone)) {
                $customer['phone'] = $phone;
            }
        }

        $params = [
            'CMS' => 'Evolution CMS ' . $this->modx->getConfig('settings_version'),
        ];

        foreach (['email', 'phone'] as $field) {
            if (isset($customer[$field])) {
                $params[$field] = $customer[$field];
            }
        }
        $key = strtoupper(implode(unpack("H32", pack("H32", $this->getSetting('key')) ^ pack("H32", $this->getSetting('key_2')))));;
        $data = [
            'amount' => number_format((int)round($payment['amount']), 2, '.', ''),
            'trtype' => '1',
            'currency' => 'RUB',
            'cardholder_notify' => 'EMAIL',
            'email' => $params['email'],
            'terminal' => (string)$this->getSetting('terminal'),
            'merchant' => (string)$this->getSetting('merchant'),
            'merch_name' => (string)$this->getSetting('merch_name'),
            'backref' => $this->modx->getConfig('site_url') . 'commerce/psbank/payment-success/?' . http_build_query([
                    'paymentHash' => $payment['hash'],
                ]),
            'notify_url' => $this->modx->getConfig('site_url') . 'commerce/psbank/payment-process/?' . http_build_query([
                    'paymentId' => $payment['id'],
                    'orderId' => $order_id . '-' . time(),
                    'paymentHash' => $payment['hash'],
                ]),
            'desc' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                'order_id' => $order_id,
                'site_name' => $this->modx->getConfig('site_name'),
            ]),
        ];
        $vars = ["amount", "currency", "terminal", "trtype", "backref", "order"];
        $string = '';
        foreach ($vars as $param) {
            if (isset($data[$param]) && strlen($data[$param]) != 0) {
                $string .= strlen($data[$param]) . $data[$param];
            } else {
                $string .= "-";
            }
        }

        $data['p_sign'] = strtoupper(hash_hmac('sha256', $string, pack('H*', $key)));
        if (!empty($customer)) {
            $cart = $processor->getCart();
            $items = $this->prepareItems($cart, $currency['code'], 'RUB');

            $isPartialPayment = abs($amount - $payment['amount']) > 0.01;

            if ($isPartialPayment) {
                $items = $this->decreaseItemsAmount($items, $amount, $payment['amount']);
            }

            $products = [];

            foreach ($items as $i => $item) {
                $products[] = [
                    'positionId' => $i + 1,
                    'name' => $item['name'],
                    'quantity' => [
                        'value' => $item['count'],
                        'measure' => $item['product'] ? isset($meta['measurements']) ? $meta['measurements'] : $this->lang['measures.units'] : '-',
                    ],
                    'itemAmount' => (int)round($item['total'] * 100),
                    'itemPrice' => (int)round($item['price'] * 100),
                    'itemCode' => $item['id'],
                ];
            }

            $data['orderBundle'] = json_encode([
                'orderCreationDate' => date('c'),
                'customerDetails' => $customer,
                'cartItems' => [
                    'items' => $products,
                ],
            ]);
        } else if (!empty($this->getSetting('debug'))) {
            $this->modx->logEvent(0, 2, 'User credentials not found in order: <pre>' . htmlentities(print_r($order, true)) . '</pre>', 'Commerce Psbank Payment Debug');
        }

        try {
            foreach ($data as $k => $v) {

                $data[strtoupper($k)] = $v;
                unset($data[$k]);
            }
            $result = $this->request('payment_ref/generate_payment_ref', $data);

            if (empty($result['REF'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Link is not received: ' . $e->getMessage(), 'Commerce Psbank Payment');
            return false;
        }

        return $result['REF'];
    }

    public function handleCallback()
    {
        $paymentHash = $_REQUEST['paymentHash'];
        if (isset($_REQUEST['ORDER'])) {

            $p_id = $_REQUEST['paymentId'];
            $data = [
                'amount' => $_REQUEST['AMOUNT'],
                'currency' => $_REQUEST['CURRENCY'],
                'order' => $_REQUEST['ORDER'],
                'desc'=>$_REQUEST['DESC'],
                'terminal' => $this->getSetting('terminal'),
                'trtype'=> $_REQUEST['TRTYPE'],
                'merchant' => $this->getSetting('merchant'),
                'merch_name' => $this->getSetting('merch_name'),
                'email' => $_REQUEST['EMAIL'],
                'backref'=>  $this->modx->getConfig('base_url').'commerce/Psbank/payment-success?paymentHash='.$paymentHash ,
                'timestamp'=>gmdate("YmdHis"),
                'nonce' => $_REQUEST['NONCE'],
            ];

            $key = strtoupper(implode(unpack("H32", pack("H32", $this->getSetting('key')) ^ pack("H32", $this->getSetting('key_2')))));
            $vars =["amount","currency","order","merch_name","merchant","terminal","email","trtype","timestamp","nonce","backref"
            ];
            $string = '';
            foreach ($vars as $param) {
                if (isset($data[$param]) && strlen($data[$param]) != 0) {
                    $string .= strlen($data[$param]) . $data[$param];
                } else {
                    $string .= "-";
                }
            }

            $data['p_sign'] = strtoupper(hash_hmac('sha256', $string, pack('H*', $key)));
            $data['paymentId'] = $p_id;
            $data['paymentHash'] = $paymentHash;
            $data = array_change_key_case($data, CASE_UPPER);

            try {

                $status = $this->request('check_operation/ecomm_check', $data);
            } catch (\Exception $e) {
                $this->modx->logEvent(0, 3, 'Order status request failed: ' . $e->getMessage(), 'Commerce Psbank Payment');

                return false;
            }

            if ($status['RESULT'] == 0  && !isset($status['ERROR']) && !empty($_REQUEST['paymentId']) && !empty($paymentHash)) {
                try {
                    $processor = $this->modx->commerce->loadProcessor();
                    $payment = $processor->loadPayment($p_id);
                    $order = $processor->loadOrder($payment['order_id']);

                    $processor->processPayment($payment, ci()->currency->convert(floatval($status['AMOUNT']) * 0.01, 'RUB', $order['currency']));
                } catch (\Exception $e) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Psbank Payment');
                    return false;
                }

                $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/Psbank/payment-success?paymentHash=' .$paymentHash);
            }
        }

        return false;
    }

    protected function getUrl($method)
    {
        $url = $this->getSetting('test') ? 'https://test.3ds.payment.ru/cgi-bin/' . $method : 'https://3ds.payment.ru/cgi-bin/' . $method;
        return $url;
    }

    protected function request($method, $data)
    {

        $url = $this->getUrl($method);
        $curl = curl_init();
        //$host = "test.3ds.payment.ru";
        $host = $this->getSetting('test') ? 'test.3ds.payment.ru' : '3ds.payment.ru';
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                "User-Agent: " . $_SERVER['HTTP_USER_AGENT'],
                "Accept: */*",
                "Content-Type: application/x-www-form-urlencoded; charset=utf-8"]
        ]);


        $result = curl_exec($curl);
        if(!$result){
            $this->modx->logEvent(0, 3, curl_error($curl), 'Commerce Psbank CURL ERROR');
            return false;
        }
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $result = json_decode($result, true);
        if (!empty($this->getSetting('debug'))) {
            $this->modx->logEvent(0, 1, 'URL: <pre>' . $url . '</pre><br>Data: <pre>' . htmlentities(print_r($data, true)) . '</pre><br>Response: <pre>' . $code . "\n" . htmlentities(print_r($result, true)) . '</pre><br>', 'Commerce Psbank Payment Debug');
        }

        if ($code != 200) {
            $this->modx->logEvent(0, 3, 'Server is not responding', 'Commerce Psbank Payment');
            return false;
        }



        if (!empty($result['errorCode']) && isset($result['ERROR'])) {
            $this->modx->logEvent(0, 3, 'Server return error: ' . $result['ERROR'], 'Commerce Psbank Payment');
            return false;
        }

        return $result;
    }

    public function getRequestPaymentHash()
    {
        if (isset($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }
}
