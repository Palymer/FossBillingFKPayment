<?php

class Payment_Adapter_Freekassa implements FOSSBilling\InjectionAwareInterface
{
    private $config = array(); // Конфигурация модуля
    protected $di; // Контейнер зависимостей

    // Установка контейнера зависимостей
    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    // Получение контейнера зависимостей
    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    // Конструктор класса, принимает конфигурацию и проверяет её на полноту
    public function __construct($config)
    {
        $this->config = $config;
        foreach (['merchant_id', 'secret_word', 'api_key'] as $key) {
            if (!isset($this->config[$key])) {
                throw new \Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'FreeKassa', ':missing' => $key]);
            }
        }
    }

    // Возвращает конфигурацию модуля
    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true, // Поддержка одноразовых платежей
            'supports_subscriptions' => true, // Поддержка подписок
            'description' => 'FreeKassa is a payment gateway that allows you to accept payments online. For more information, please refer to <https://freekassa.com>', // Описание модуля
            'logo' => array(
                'logo' => 'FreeKassa.png', // Логотип модуля
                'height' => '50px', // Высота логотипа
                'width' => '150px', // Ширина логотипа
            ),
            'form' => array(
                'merchant_id' => array('text', array(
                    'label' => 'Merchant ID', // Поле для ввода Merchant ID
                )),
                'secret_word' => array('text', array(
                    'label' => 'Secret word', // Поле для ввода секретного слова
                )),
                'secret_word2' => array('text', array(
                    'label' => 'Secret word 2', // Поле для ввода второго секретного слова
                )),
                'api_key' => array('text', array(
                    'label' => 'API Key', // Поле для ввода API ключа
                )),
                'currency' => array('text', array(
                    'label' => 'Currency', // Поле для ввода валюты
                )),
                'recurrent' => array('text', array(
                    'label' => 'Enter your text for subscription information', // Поле для ввода информации о подписке
                )),
            ),
        );
    }

    // Генерация URL для оплаты
    protected function _generatePaymentUrl($invoice_id, $amount)
    {
        $data = array(
            'm' => $this->config['merchant_id'], // Merchant ID
            'oa' => $amount, // Сумма платежа
            'o' => $invoice_id, // ID счета
            'currency' => $this->config['currency'], // Валюта
        );
        $dataString = $this->config['merchant_id'] . ':' . $amount . ':' . $this->config['secret_word'] . ':' . $this->config['currency'] . ':' . $invoice_id;
        $signature = md5($dataString); // Генерация подписи
        $paymentUrl = 'https://pay.freekassa.com/?' . http_build_query($data) . '&s=' . $signature; // Формирование URL для оплаты
        return $paymentUrl;
    }

    // Генерация HTML для кнопки оплаты
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id); // Загрузка счета
        $invoiceHash = $invoice->hash;
        $invoiceService = $this->di['mod_service']('Invoice'); // Сервис для работы со счетами
        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway'); // Сервис для работы с платежными шлюзами
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "FreeKassa"'); // Поиск платежного шлюза
        $order_id = $invoice->id;
        $payer_account = $invoice->seller_email;
        $amount = $invoiceService->getTotalWithTax($invoice); // Получение суммы счета с учетом налогов
        $paymentUrl = $this->_generatePaymentUrl($order_id, $amount); // Генерация URL для оплаты
        return '<a href="' . $paymentUrl . '" target="_blank">Оплатить FreeKassa</a>'; // Возвращение HTML для кнопки оплаты
    }

    // Обработка транзакции
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $ipn = $data['post']; // Данные IPN запроса

        $dataString = $this->config['merchant_id'] . ':' . $ipn['AMOUNT'] . ':' . $this->config['secret_word2'] . ':' . $ipn['MERCHANT_ORDER_ID'];
        $signature = md5($dataString); // Генерация подписи
        if ($signature != $ipn['SIGN']) {
            throw new Exception('Invalid signature. The request may not be from FreeKassa.'); // Проверка подписи
        }

        $tx = $this->di['db']->getExistingModelById('Transaction', $id); // Получение транзакции

        // Использование ID счета, связанного с транзакцией, или ID, переданного через GET
        if ($tx->invoice_id) {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
        } else {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $ipn['MERCHANT_ORDER_ID']);
            $tx->invoice_id = $invoice->id;
        }

        $tx->status = 'processed'; // Установка статуса транзакции
        $tx->txn_status = 'processed';
        $tx->txn_id = $charge->id;
        $tx->amount = (float)$ipn['AMOUNT'];
        $tx->updated_at = date('Y-m-d H:i:s');

        $bd = [
            'amount' => $tx->amount,
            'description' => 'Freekassa transaction '.$tx->txn_id,
            'type' => 'transaction',
            'rel_id' => $tx->id,
        ];

        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id); // Получение клиента
        $clientService = $this->di['mod_service']('client'); // Сервис для работы с клиентами
        $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd); // Добавление средств клиенту

        $invoiceService = $this->di['mod_service']('Invoice'); // Сервис для работы со счетами
        if ($tx->invoice_id) {
            $invoiceService->payInvoiceWithCredits($invoice); // Оплата счета кредитами
        }

        $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]); // Пакетная оплата счетов кредитами
        $this->di['db']->store($tx); // Сохранение транзакции
    }

    // Создание заказа и получение URL для оплаты
    public function createOrderAndGetPaymentUrl($orderId, $amount, $currency, $paymentId, $email, $ip, $tel = null, $successUrl = null, $failureUrl = null, $notificationUrl = null)
    {
        $data = array(
            'm' => $this->config['merchant_id'], // Merchant ID
            'oa' => $amount, // Сумма платежа
            'o' => $orderId, // ID заказа
            'currency' => $currency, // Валюта
            's' => md5($this->config['merchant_id'] . ':' . $amount . ':' . $this->config['secret_word'] . ':' . $currency . ':' . $orderId), // Генерация подписи
            'email' => $email, // Email плательщика
            'ip' => $ip, // IP плательщика
            'tel' => $tel, // Телефон плательщика
            'success_url' => $successUrl, // URL успешной оплаты
            'failure_url' => $failureUrl, // URL неуспешной оплаты
            'notification_url' => $notificationUrl, // URL для уведомлений
        );

        $paymentUrl = 'https://pay.freekassa.com/?' . http_build_query($data); // Формирование URL для оплаты

        return $paymentUrl;
    }

}
