//<?php
/**
 * Payment Psbank
 *
 * Psbank payments processing
 *
 * @category    plugin
 * @version     0.1.5
 * @author      sahar-08
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @properties &terminal=TERMINAL;text; &merchant=MERCHANT;text; &key=Ключ;text; &key_2=компонента 2;text; &merch_name=MERCH_NAME;text; &test=Тестовый режим;checkbox;Да==1; &debug=отладка;checkbox;Да==1;
 * @internal    @modx_category Commerce
 * @internal    @installset base
*/

if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isSelectedPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'psbank';

switch ($modx->event->name) {
    case 'OnRegisterPayments': {
        $class = new \Commerce\Payments\PsbankPayment($modx, $params);

        if (empty($params['title'])) {
            $lang = $modx->commerce->getUserLanguage('psbank');
            $params['title'] = $lang['psbank.caption'];
        }

        $modx->commerce->registerPayment('psbank', $params['title'], $class);
        break;
    }

    case 'OnBeforeOrderSending': {
        if ($isSelectedPayment) {
            $FL->setPlaceholder('extra', $FL->getPlaceholder('extra', '') . $modx->commerce->loadProcessor()->populateOrderPaymentLink());
        }

        break;
    }

    case 'OnManagerBeforeOrderRender': {
        if (isset($params['groups']['payment_delivery']) && $isSelectedPayment) {
            $lang = $modx->commerce->getUserLanguage('psbank');

            $params['groups']['payment_delivery']['fields']['payment_link'] = [
                'title'   => $lang['psbank.link_caption'],
                'content' => function($data) use ($modx) {
                    return $modx->commerce->loadProcessor()->populateOrderPaymentLink('@CODE:<a href="[+link+]" target="_blank">[+link+]</a>');
                },
                'sort' => 50,
            ];
        }

        break;
    }
}
