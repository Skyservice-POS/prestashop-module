<?php
/**
 * SkyService — передача замовлень PrestaShop у SkyService POS.
 *
 * «Тонкий» модуль: у магазині живе рівно одна відповідальність — зловити хук і віддати
 * замовлення нашому беку. Каталог, залишки, мапінг товарів синхронізує бек через
 * вбудований Webservice API магазину, тож сюди ця логіка НЕ додається: інакше кожна
 * правка вимагала б від клієнта оновлення модуля на своєму хостингу.
 *
 * Чому взагалі потрібен модуль: PrestaShop, на відміну від WooCommerce, не вміє вихідних
 * вебхуків у принципі. Без модуля лишається лише polling замовлень, тобто лаг на касі.
 *
 * Доставка: одна спроба синхронно в хуку (щоб не тримати checkout), при невдачі —
 * рядок в outbox і повтор на наступному хуку. Ретраї ми контролюємо самі, тому бек може
 * відповідати чесним 5xx, а не завжди 200, як доводиться в WooCommerce-приймачі.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SkyService extends Module
{
    /** Скільки відкладених доставок пробуємо доштовхнути на кожному хуку */
    const OUTBOX_FLUSH_LIMIT = 10;

    /** Скільки разів пробуємо доставити подію, перш ніж лишити її як провалену */
    const MAX_ATTEMPTS = 5;

    /** Таймаут HTTP до беку, сек. Свідомо короткий: checkout важливіший за інтеграцію */
    const HTTP_TIMEOUT = 5;

    /** @var string накопичувач HTML для сторінки налаштувань */
    private $html = '';

    public function __construct()
    {
        $this->name = 'skyservice';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'SkyService';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SkyService — синхронізація замовлень');
        $this->description = $this->l('Передає замовлення магазину в SkyService POS у момент підтвердження.');
        $this->confirmUninstall = $this->l('Видалити модуль? Незавершені доставки замовлень буде втрачено.');

        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && Configuration::updateValue('SKYSERVICE_ENABLED', 0)
            && Configuration::updateValue('SKYSERVICE_API_URL', '')
            && Configuration::updateValue('SKYSERVICE_COMPANY_ID', '')
            && Configuration::updateValue('SKYSERVICE_SECRET', '');
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'skyservice_outbox`');

        return parent::uninstall()
            && Configuration::deleteByName('SKYSERVICE_ENABLED')
            && Configuration::deleteByName('SKYSERVICE_API_URL')
            && Configuration::deleteByName('SKYSERVICE_COMPANY_ID')
            && Configuration::deleteByName('SKYSERVICE_SECRET');
    }

    /**
     * Outbox недоставлених подій. Потрібен, бо єдина синхронна спроба в хуку не має права
     * ані блокувати checkout ретраями, ані втрачати замовлення при недоступному беку.
     */
    private function installDb()
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'skyservice_outbox` (
                `id_outbox` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event` VARCHAR(64) NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `last_error` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_next` DATETIME NOT NULL,
                PRIMARY KEY (`id_outbox`),
                KEY `idx_next` (`date_next`, `attempts`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    // ------------------------------------------------------------------ хуки

    /**
     * Замовлення підтверджене (створене й провалідоване платіжним модулем).
     * Це і є момент, коли воно має зʼявитись на касі.
     */
    public function hookActionValidateOrder(array $params)
    {
        if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }

        try {
            $this->flushOutbox();
            $this->dispatch('order.created', $this->buildOrderPayload($params['order']));
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('SkyService: validateOrder hook failed — ' . $e->getMessage(), 3, null, 'Order', (int) $params['order']->id, true);
        }
    }

    /**
     * Зміна статусу — насамперед скасування/повернення, щоб бек міг повернути залишок.
     */
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        try {
            $this->flushOutbox();
            $this->dispatchStatusUpdate($order, $params['newOrderStatus']);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('SkyService: statusUpdate hook failed — ' . $e->getMessage(), 3, null, 'Order', (int) $order->id, true);
        }
    }

    private function dispatchStatusUpdate(Order $order, $newStatus)
    {
        $this->dispatch('order.status_updated', [
            'orderId' => (int) $order->id,
            'reference' => $order->reference,
            'status' => [
                'id' => (int) $newStatus->id,
                'name' => (string) $newStatus->name[(int) Configuration::get('PS_LANG_DEFAULT')],
                'paid' => (bool) $newStatus->paid,
                'shipped' => (bool) $newStatus->shipped,
                // Скасування/повернення бек трактує як привід повернути залишок
                'canceled' => (bool) ($newStatus->id == Configuration::get('PS_OS_CANCELED')),
                'refunded' => (bool) ($newStatus->id == Configuration::get('PS_OS_REFUND')),
            ],
        ]);
    }

    // -------------------------------------------------------------- payload

    /**
     * Зводить замовлення до плоского вигляду, на який спирається приймач на беку.
     *
     * Ідентифікація позиції — пара product_id:product_attribute_id (у товару без варіацій
     * атрибут = 0). Саме цей ключ бек тримає у своїй таблиці звʼязків, як
     * "{product_id}:{variation_id}" у WooCommerce-конекторі.
     *
     * Дата віддається в UTC: магазин пише date_add у таймзоні магазину, і вгадувати зсув
     * на боці беку — гарантована розбіжність у звітах.
     */
    private function buildOrderPayload(Order $order)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency((int) $order->id_currency);

        $address = null;
        if ($order->id_address_delivery) {
            $candidate = new Address((int) $order->id_address_delivery);
            if (Validate::isLoadedObject($candidate)) {
                $address = $candidate;
            }
        }

        $lines = [];
        foreach ($order->getProducts() as $product) {
            $lines[] = [
                'key' => (int) $product['product_id'] . ':' . (int) $product['product_attribute_id'],
                'productId' => (int) $product['product_id'],
                'attributeId' => (int) $product['product_attribute_id'],
                'name' => (string) $product['product_name'],
                // reference = артикул, ean13/upc = штрихкод. У варіації свої власні,
                // тому підхоплюємо їх з рядка замовлення, а не з батьківського товару.
                'sku' => (string) $product['product_reference'],
                'barcode' => (string) ($product['product_ean13'] ?: $product['product_upc']),
                'quantity' => (float) $product['product_quantity'],
                'price' => (float) $product['unit_price_tax_incl'],
                'total' => (float) $product['total_price_tax_incl'],
            ];
        }

        $phone = '';
        if ($address) {
            $phone = trim($address->phone_mobile) !== '' ? $address->phone_mobile : $address->phone;
        }

        return [
            'orderId' => (int) $order->id,
            'reference' => (string) $order->reference,
            'createdAtUtc' => gmdate('Y-m-d H:i:s', strtotime($order->date_add)),
            'currency' => (string) $currency->iso_code,
            'totalPaid' => (float) $order->total_paid,
            'totalShipping' => (float) $order->total_shipping_tax_incl,
            'totalDiscount' => (float) $order->total_discounts_tax_incl,
            'paymentMethod' => (string) $order->payment,
            'comment' => (string) $this->getOrderComment($order),
            'client' => [
                'name' => trim($customer->firstname . ' ' . $customer->lastname),
                'email' => (string) $customer->email,
                'phone' => (string) $phone,
            ],
            'delivery' => $address ? [
                'city' => (string) $address->city,
                'address' => trim($address->address1 . ' ' . $address->address2),
                'postcode' => (string) $address->postcode,
                'country' => (string) Country::getIsoById((int) $address->id_country),
            ] : null,
            'lines' => $lines,
            'shopUrl' => $this->getShopBaseUrl(),
            'langIso' => (string) Language::getIsoById($idLang),
        ];
    }

    /**
     * Коментар покупця з чекауту — касиру він потрібен так само, як склад позицій.
     */
    private function getOrderComment(Order $order)
    {
        $messages = Db::getInstance()->getValue(
            'SELECT `message` FROM `' . _DB_PREFIX_ . 'customer_thread` ct
             JOIN `' . _DB_PREFIX_ . 'customer_message` cm ON cm.`id_customer_thread` = ct.`id_customer_thread`
             WHERE ct.`id_order` = ' . (int) $order->id . '
             ORDER BY cm.`id_customer_message` ASC'
        );

        return $messages !== false ? (string) $messages : '';
    }

    // ------------------------------------------------------------- доставка

    /**
     * Одна синхронна спроба, при невдачі — в outbox.
     *
     * Виняток назовні не летить НІКОЛИ: впасти тут означає зламати оформлення замовлення
     * покупцеві. Гірший сценарій — замовлення доїде із затримкою, а не втрачений checkout.
     */
    private function dispatch($event, array $payload)
    {
        try {
            if (!Configuration::get('SKYSERVICE_ENABLED')) {
                return;
            }

            $result = $this->send($event, $payload);
            if ($result['ok']) {
                return;
            }

            $this->enqueue($event, (int) (isset($payload['orderId']) ? $payload['orderId'] : 0), $payload, $result['error']);
        } catch (Throwable $e) {
            // Саме Throwable, а не Exception: TypeError/Error з ядра магазину — теж наш ризик,
            // і пропущений назовні він зламає покупцеві оформлення замовлення.
            PrestaShopLogger::addLog('SkyService: dispatch failed — ' . $e->getMessage(), 3, null, 'Order', 0, true);
        }
    }

    /**
     * POST події на бек з HMAC-підписом сирого тіла.
     *
     * Параметр в URL саме `company`, а НЕ `companyId`: роутер беку вважає запит із companyId
     * автентифікованим і вимагає токен, якого в магазина немає.
     */
    private function send($event, array $payload)
    {
        $apiUrl = trim((string) Configuration::get('SKYSERVICE_API_URL'));
        $companyId = trim((string) Configuration::get('SKYSERVICE_COMPANY_ID'));
        $secret = (string) Configuration::get('SKYSERVICE_SECRET');

        if ($apiUrl === '' || $companyId === '' || $secret === '') {
            return ['ok' => false, 'error' => 'module is not configured'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $url = rtrim($apiUrl, '/') . '/?section=prestashop&action=webhook&company=' . rawurlencode($companyId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sky-Event: ' . $event,
                'X-Sky-Signature: ' . base64_encode(hash_hmac('sha256', $body, $secret, true)),
                'X-Sky-Source: ' . $this->getShopBaseUrl(),
                'X-Sky-Module-Version: ' . $this->version,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => 'curl: ' . $curlError];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'error' => 'http ' . $httpCode . ': ' . Tools::substr((string) $response, 0, 500)];
        }

        // HTTP-коду НЕ досить. Бек SkyService віддає помилки конвертом усередині HTTP 200
        // ({"status":"error","error":[...]}), тож перевірка лише коду вважала б збій успіхом,
        // outbox лишався б порожнім і замовлення тихо зникало. Перевірено на стенді.
        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) {
            if (isset($decoded['status']) && $decoded['status'] === 'error') {
                $reason = isset($decoded['error']) ? json_encode($decoded['error'], JSON_UNESCAPED_UNICODE) : '';

                return ['ok' => false, 'error' => 'api error: ' . Tools::substr((string) $reason, 0, 500)];
            }
            // Приймач відповідає {"ok":false}, коли обробку не завершено
            if (array_key_exists('ok', $decoded) && !$decoded['ok']) {
                return ['ok' => false, 'error' => 'receiver reported failure'];
            }
        }

        return ['ok' => true, 'error' => null, 'response' => $response];
    }

    private function enqueue($event, $idOrder, array $payload, $error)
    {
        Db::getInstance()->insert('skyservice_outbox', [
            'event' => pSQL($event),
            'id_order' => (int) $idOrder,
            'payload' => pSQL(json_encode($payload, JSON_UNESCAPED_UNICODE), true),
            'attempts' => 1,
            'last_error' => pSQL((string) $error, true),
            'date_add' => date('Y-m-d H:i:s'),
            'date_next' => date('Y-m-d H:i:s', time() + 60),
        ]);

        PrestaShopLogger::addLog('SkyService: delivery queued — ' . $error, 2, null, 'Order', (int) $idOrder, true);
    }

    /**
     * Доштовхує відкладені доставки, чіпляючись до наступного хука.
     *
     * Свідомо без крона: крон на чужому хостингу — це те, що клієнт забуде налаштувати.
     * Мінус підходу чесний — у магазині без замовлень outbox стоїть; для тестового модуля
     * прийнятно, у бойовому варіанті сюди піде ще й крон-контролер.
     */
    private function flushOutbox()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'skyservice_outbox`
             WHERE `date_next` <= NOW() AND `attempts` < ' . (int) self::MAX_ATTEMPTS . '
             ORDER BY `id_outbox` ASC
             LIMIT ' . (int) self::OUTBOX_FLUSH_LIMIT
        );

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            if (!is_array($payload)) {
                Db::getInstance()->delete('skyservice_outbox', '`id_outbox` = ' . (int) $row['id_outbox']);
                continue;
            }

            $result = $this->send($row['event'], $payload);
            if ($result['ok']) {
                Db::getInstance()->delete('skyservice_outbox', '`id_outbox` = ' . (int) $row['id_outbox']);
                continue;
            }

            // Експоненційний бекоф: 1, 2, 4, 8 хвилин
            $attempts = (int) $row['attempts'] + 1;
            Db::getInstance()->update('skyservice_outbox', [
                'attempts' => $attempts,
                'last_error' => pSQL((string) $result['error'], true),
                'date_next' => date('Y-m-d H:i:s', time() + (60 * pow(2, min($attempts - 1, 4)))),
            ], '`id_outbox` = ' . (int) $row['id_outbox']);
        }
    }

    private function getShopBaseUrl()
    {
        return (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://')
            . Configuration::get('PS_SHOP_DOMAIN')
            . __PS_BASE_URI__;
    }

    // ---------------------------------------------------------- налаштування

    public function getContent()
    {
        if (Tools::isSubmit('submitSkyServiceConfig')) {
            $this->postProcess();
        }
        if (Tools::isSubmit('submitSkyServiceTest')) {
            $this->runConnectionTest();
        }

        return $this->html . $this->renderOutbox() . $this->renderForm();
    }

    private function postProcess()
    {
        $apiUrl = trim((string) Tools::getValue('SKYSERVICE_API_URL'));
        $companyId = trim((string) Tools::getValue('SKYSERVICE_COMPANY_ID'));
        $secret = trim((string) Tools::getValue('SKYSERVICE_SECRET'));

        if ($apiUrl !== '' && !Validate::isUrl($apiUrl)) {
            $this->html .= $this->displayError($this->l('Некоректний URL API'));
            return;
        }

        Configuration::updateValue('SKYSERVICE_API_URL', $apiUrl);
        Configuration::updateValue('SKYSERVICE_COMPANY_ID', $companyId);
        Configuration::updateValue('SKYSERVICE_ENABLED', (int) Tools::getValue('SKYSERVICE_ENABLED'));
        // Порожнє поле секрету означає «лишити як є» — щоб збереження форми не стирало ключ
        if ($secret !== '') {
            Configuration::updateValue('SKYSERVICE_SECRET', $secret);
        }

        $this->html .= $this->displayConfirmation($this->l('Налаштування збережено'));
    }

    /**
     * Кнопка «Перевірити зʼєднання»: шле подію ping тим самим шляхом, що й замовлення,
     * тобто перевіряє рівно те, що працюватиме в бою — URL, companyId і підпис.
     */
    private function runConnectionTest()
    {
        $result = $this->send('ping', [
            'shopUrl' => $this->getShopBaseUrl(),
            'psVersion' => _PS_VERSION_,
            'moduleVersion' => $this->version,
            'sentAtUtc' => gmdate('Y-m-d H:i:s'),
        ]);

        if ($result['ok']) {
            $this->html .= $this->displayConfirmation(
                $this->l('Бек відповів:') . ' ' . Tools::htmlentitiesUTF8(Tools::substr((string) $result['response'], 0, 300))
            );
        } else {
            $this->html .= $this->displayError($this->l('Помилка:') . ' ' . Tools::htmlentitiesUTF8((string) $result['error']));
        }
    }

    private function renderOutbox()
    {
        $pending = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'skyservice_outbox` WHERE `attempts` < ' . (int) self::MAX_ATTEMPTS
        );
        $dead = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'skyservice_outbox` WHERE `attempts` >= ' . (int) self::MAX_ATTEMPTS
        );

        if (!$pending && !$dead) {
            return '';
        }

        $text = sprintf($this->l('Недоставлених подій: %d, вичерпали спроби: %d'), $pending, $dead);

        return $dead ? $this->displayError($text) : $this->displayWarning($text);
    }

    private function renderForm()
    {
        $fields = [
            'form' => [
                'legend' => ['title' => $this->l('Підключення до SkyService'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Увімкнено'),
                        'name' => 'SKYSERVICE_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Поки вимкнено, замовлення в SkyService не передаються.'),
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Так')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('Ні')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('URL API'),
                        'name' => 'SKYSERVICE_API_URL',
                        'desc' => $this->l('Наприклад https://api.skyservice.pro'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('ID компанії'),
                        'name' => 'SKYSERVICE_COMPANY_ID',
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Секрет підпису'),
                        'name' => 'SKYSERVICE_SECRET',
                        'desc' => $this->l('Видається в налаштуваннях інтеграції SkyService. Порожнє поле — лишити поточний.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Зберегти'), 'name' => 'submitSkyServiceConfig'],
                'buttons' => [
                    [
                        'title' => $this->l('Перевірити зʼєднання'),
                        'name' => 'submitSkyServiceTest',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-left',
                        'icon' => 'process-icon-refresh',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSkyServiceConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'SKYSERVICE_ENABLED' => (int) Configuration::get('SKYSERVICE_ENABLED'),
                'SKYSERVICE_API_URL' => Configuration::get('SKYSERVICE_API_URL'),
                'SKYSERVICE_COMPANY_ID' => Configuration::get('SKYSERVICE_COMPANY_ID'),
                // Секрет назад у форму не віддаємо
                'SKYSERVICE_SECRET' => '',
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fields]);
    }
}
