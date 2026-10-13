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
 *
 * Жодне замовлення не має пропасти через вимкнення (тікет #8287):
 * - перемикач «Увімкнено» = Ні → події йдуть в outbox, але не відправляються; після
 *   ввімкнення їх доштовхує збереження форми або наступний хук;
 * - модуль вимкнено в менеджері модулів → хуки не викликаються зовсім. Для цього є курсор
 *   SKYSERVICE_LAST_ORDER_ID (останнє врахованe замовлення): усе, що новіше за нього й не
 *   лежить в outbox, догружається при ввімкненні модуля та на кожному хуку.
 * Повторна доставка безпечна — бек дедуплікує замовлення за orderId.
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

    /** Скільки пропущених замовлень догружаємо в outbox за один виклик */
    const CATCH_UP_LIMIT = 50;

    /**
     * id_reference коробкового перевізника «Click and collect» (install/data/xml/carrier.xml).
     * Саме reference, а не id_carrier: кожне редагування перевізника в адмінці створює новий
     * id_carrier, а reference лишається тим самим.
     */
    const CORE_PICKUP_CARRIER_REFERENCE = 1;

    /** Слова в назві/строку доставки перевізника, за якими вгадуємо самовивіз при встановленні */
    const PICKUP_CARRIER_PATTERN = '/pick\\s*-?\\s*up|click\\s*(&|and|\\+)?\\s*collect|in-?store|самовив|самовыв|з магазину|из магазина/iu';

    /** @var string накопичувач HTML для сторінки налаштувань */
    private $html = '';

    /**
     * Замовлення, провалідовані в ЦЬОМУ запиті, order.created яких відкладено до кінця
     * запиту (id_order => true). Статичне, бо гарантії, що всі хуки запиту прийдуть в один
     * екземпляр модуля, ядро не дає.
     *
     * @var array<int, bool>
     */
    private static $pendingCreated = [];

    /** @var bool shutdown-обробник відкладених order.created уже зареєстровано */
    private static $shutdownRegistered = false;

    public function __construct()
    {
        $this->name = 'skyservice';
        $this->tab = 'administration';
        $this->version = '0.4.1';
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
            && Configuration::updateValue('SKYSERVICE_SECRET', '')
            && Configuration::updateValue('SKYSERVICE_PICKUP_CARRIERS', json_encode($this->detectPickupCarrierReferences()))
            // Історію магазину до встановлення модуля на касу не тягнемо
            && Configuration::updateValue('SKYSERVICE_LAST_ORDER_ID', self::getMaxOrderId());
    }

    /**
     * Ввімкнення в менеджері модулів. Поки модуль був вимкнений, ядро не викликало хуків,
     * тож замовлення того періоду ніде не зафіксовані — догружаємо їх за курсором і одразу
     * пробуємо доставити, не чекаючи наступного замовлення.
     */
    public function enable($force_all = false)
    {
        if (!parent::enable($force_all)) {
            return false;
        }

        try {
            $this->catchUpMissedOrders();
            $this->flushOutbox();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('SkyService: catch-up on enable failed — ' . $e->getMessage(), 3, null, 'Order', 0, true);
        }

        return true;
    }

    /** @return int */
    public static function getMaxOrderId()
    {
        return (int) Db::getInstance()->getValue('SELECT MAX(`id_order`) FROM `' . _DB_PREFIX_ . 'orders`');
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'skyservice_outbox`');

        return parent::uninstall()
            && Configuration::deleteByName('SKYSERVICE_ENABLED')
            && Configuration::deleteByName('SKYSERVICE_API_URL')
            && Configuration::deleteByName('SKYSERVICE_COMPANY_ID')
            && Configuration::deleteByName('SKYSERVICE_SECRET')
            && Configuration::deleteByName('SKYSERVICE_LAST_ORDER_ID')
            && Configuration::deleteByName('SKYSERVICE_PICKUP_CARRIERS');
    }

    // ------------------------------------------------------- перевізники

    /**
     * Перевізники магазину для форми налаштувань (і модульні теж — самовивіз буває й модулем).
     *
     * @return array<int, array{reference:int, name:string, delay:string}>
     */
    private function getShopCarriers()
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $carriers = [];
        foreach (Carrier::getCarriers($idLang, false, false, false, null, Carrier::ALL_CARRIERS) as $row) {
            $carriers[] = [
                'reference' => (int) $row['id_reference'],
                'name' => $this->getCarrierDisplayName((string) $row['name']),
                'delay' => (string) $row['delay'],
            ];
        }

        return $carriers;
    }

    /** Перевізник із назвою «0» — це «назва магазину» (так ядро позначає перевізника за замовчуванням) */
    private function getCarrierDisplayName($name)
    {
        return $name === '0' ? (string) Configuration::get('PS_SHOP_NAME') : $name;
    }

    /**
     * Початковий перелік самовивозів, щоб модуль працював «з коробки»: коробковий
     * «Click and collect» плюс перевізники, у назві чи строку доставки яких є «самовивіз»,
     * «pickup» тощо. Далі власник магазину править перелік у налаштуваннях модуля.
     *
     * @return int[] id_reference
     */
    public function detectPickupCarrierReferences()
    {
        $references = [];
        foreach ($this->getShopCarriers() as $carrier) {
            if ($carrier['reference'] === self::CORE_PICKUP_CARRIER_REFERENCE
                || preg_match(self::PICKUP_CARRIER_PATTERN, $carrier['name'] . ' ' . $carrier['delay'])
            ) {
                $references[] = $carrier['reference'];
            }
        }

        return array_values(array_unique($references));
    }

    /** @return int[] id_reference перевізників, які означають самовивіз */
    private function getPickupCarrierReferences()
    {
        $references = json_decode((string) Configuration::get('SKYSERVICE_PICKUP_CARRIERS'), true);

        return is_array($references) ? array_map('intval', $references) : [];
    }

    /**
     * Перевізник замовлення для беку. Самовивіз визначає саме він, а не адреса: PrestaShop
     * вимагає адресу на чекауті навіть для «Click and collect» (тікет #8283).
     *
     * @return array|null
     */
    private function buildCarrierPayload(Order $order, $idLang)
    {
        if (!$order->id_carrier) {
            return null;
        }

        $carrier = new Carrier((int) $order->id_carrier, (int) $idLang);
        if (!Validate::isLoadedObject($carrier)) {
            return null;
        }

        return [
            'carrierId' => (int) $carrier->id,
            'carrierReference' => (int) $carrier->id_reference,
            'carrierName' => $this->getCarrierDisplayName((string) $carrier->name),
            'carrierDelay' => (string) $carrier->delay,
            'isPickup' => in_array((int) $carrier->id_reference, $this->getPickupCarrierReferences(), true),
        ];
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
     *
     * order.created тут НЕ шлемо, а відкладаємо до кінця запиту. Ядро
     * (`PaymentModule::validateOrder()`) виконує цей хук РАНІШЕ, ніж проставить статус, тож
     * у цей момент оплати не видно навіть у шлюзів, що створюють замовлення вже оплаченим
     * (Portmone 2.x). Раніше це давало дві події на одну оплату: order.created «неоплачене»
     * і одразу order.status_updated з оплатою. Тепер в кінці запиту замовлення
     * перечитується з уже записаним статусом і їде однією подією.
     *
     * Курсор зсуваємо теж лише після відправки: якщо процес помре раніше, замовлення
     * догрузить catchUpMissedOrders на наступному хуку (бек дедуплікує повтори).
     */
    public function hookActionValidateOrder(array $params)
    {
        if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }

        $idOrder = (int) $params['order']->id;

        try {
            self::$pendingCreated[$idOrder] = true;
            if (!self::$shutdownRegistered) {
                register_shutdown_function([$this, 'dispatchPendingCreated']);
                self::$shutdownRegistered = true;
            }

            // Спершу — замовлення, пропущені до цього (відкладені в цьому запиті виключаємо:
            // їх відправить dispatchPendingCreated), потім outbox
            $this->catchUpMissedOrders(array_keys(self::$pendingCreated));
            $this->flushOutbox();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('SkyService: validateOrder hook failed — ' . $e->getMessage(), 3, null, 'Order', (int) $params['order']->id, true);
        }
    }

    /**
     * Shutdown-обробник: відправляє відкладені order.created із фінальним станом замовлення.
     *
     * Публічний лише тому, що його викликає register_shutdown_function. Виняток назовні не
     * летить: на shutdown його вже нікому зловити, а замовлення догрузить курсор.
     */
    public function dispatchPendingCreated()
    {
        $pending = array_keys(self::$pendingCreated);
        self::$pendingCreated = [];
        sort($pending);

        foreach ($pending as $idOrder) {
            try {
                // Свіжий обʼєкт, а не той, що прийшов у хук: у того current_state ще порожній
                $order = new Order((int) $idOrder);
                if (Validate::isLoadedObject($order)) {
                    $this->dispatch('order.created', $this->buildOrderPayload($order));
                }
                $this->advanceOrderCursor($idOrder);
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('SkyService: deferred order.created failed — ' . $e->getMessage(), 3, null, 'Order', (int) $idOrder, true);
            }
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

        // Перший статус замовлення, створеного в цьому ж запиті: окрему подію не шлемо,
        // стан поїде всередині відкладеного order.created (див. hookActionValidateOrder)
        if (isset(self::$pendingCreated[(int) $params['id_order']])) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        try {
            $this->catchUpMissedOrders(array_keys(self::$pendingCreated));
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
            // Платіжний контекст їде РАЗОМ із подією, а не добирається беком: саме ця
            // подія (а не створення замовлення) приносить на касу факт оплати карткою.
            'paymentModule' => (string) $order->module,
            'paymentMethod' => (string) $order->payment,
            'paymentState' => $this->resolvePaymentState($order, $newStatus->id),
            'totalPaid' => (float) $order->total_paid,
            'totalPaidReal' => (float) $order->total_paid_real,
            'status' => [
                'id' => (int) $newStatus->id,
                'name' => $this->resolveStatusName($newStatus),
                'paid' => (bool) $newStatus->paid,
                'shipped' => (bool) $newStatus->shipped,
                // Скасування/повернення бек трактує як привід повернути залишок
                'canceled' => (bool) ($newStatus->id == Configuration::get('PS_OS_CANCELED')),
                'refunded' => (bool) ($newStatus->id == Configuration::get('PS_OS_REFUND')),
            ],
        ]);
    }

    /**
     * Назва статусу для каси.
     *
     * Ядро в actionOrderStatusPostUpdate передає OrderState, завантажений З МОВОЮ
     * (`new OrderState($id, $order->id_lang)`), тож `name` там рядок, а не масив по мовах.
     * Індекс по рядку (`$name[1]`) повертав один БАЙТ: для кирилиці це обрізок
     * багатобайтного символу, json_encode на ньому падав, і подія йшла з порожнім тілом —
     * оплата Portmone так і не доїжджала на касу.
     *
     * @param OrderState $status
     * @return string
     */
    private function resolveStatusName($status)
    {
        $name = $status->name;
        if (is_array($name)) {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
            $name = isset($name[$idLang]) ? $name[$idLang] : reset($name);
        }

        return (string) $name;
    }

    /**
     * Зводить стан оплати замовлення до значень, які розуміє бек:
     * paid | paid_unverified | preauth | pending.
     *
     * Чому не досить прапора `paid` самого PrestaShop. Платіжні шлюзи не зобовʼязані
     * користуватися коробковими статусами й часто створюють власні. Portmone
     * (офіційний модуль `portmone`) додає три — PORTMONE_PAID,
     * PORTMONE_PAID_BUT_NOT_VERIFIED, PORTMONE_PREAUTH — і ВСІМ ставить `paid = 1`.
     * Через це `hasBeenPaid()` повертає true й на блокуванні коштів (PREAUTH), де гроші
     * ще не списані: каса вважала б таке замовлення сплаченим і віддала товар.
     * Розрізнити стани можна лише за id, які модуль поклав у Configuration, — і це
     * єдине місце, де вони доступні, тож нормалізація живе тут, а не на беку.
     *
     * @param Order    $order
     * @param int|null $stateId Статус, у який замовлення переходить (з хука
     *                          actionOrderStatusPostUpdate). Без нього беремо поточний.
     * @return string
     */
    private function resolvePaymentState(Order $order, $stateId = null)
    {
        $stateId = $stateId === null ? (int) $order->getCurrentState() : (int) $stateId;

        if ($order->module === 'portmone') {
            // PORTMONE_ERROR (відмова за платежем) у версії 2.1.1 створюється ТИМ САМИМ
            // addOrderState(), тобто теж із paid = 1 — відхилений платіж виглядає для
            // магазину оплаченим. Тому він тут явно зводиться до 'pending'.
            // У 1.6 цього стану немає, модуль користується PS_OS_ERROR;
            // Configuration::get() на відсутньому ключі дає false → 0 → просто не збігається.
            $portmoneStates = [
                'PORTMONE_PAID' => 'paid',
                'PORTMONE_PAID_BUT_NOT_VERIFIED' => 'paid_unverified',
                'PORTMONE_PREAUTH' => 'preauth',
                'PORTMONE_ERROR' => 'pending',
            ];

            foreach ($portmoneStates as $configKey => $state) {
                $configured = (int) Configuration::get($configKey);
                if ($configured !== 0 && $configured === $stateId) {
                    return $state;
                }
            }
        }

        // Решта шлюзів і коробкові статуси: віримо прапору магазину. Перевіряємо саме
        // СТАН, у який переходимо, а не історію: інакше замовлення, яке колись було
        // оплачене, а потім скасоване, назавжди лишалося б «оплаченим».
        $state = new OrderState($stateId);
        if (Validate::isLoadedObject($state)) {
            return $state->paid ? 'paid' : 'pending';
        }

        return $order->hasBeenPaid() ? 'paid' : 'pending';
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

        // Адреса + перевізник. isPickup = перевізник у переліку самовивозів із налаштувань;
        // адресу шлемо й для самовивозу — з неї бек заповнює картку нового клієнта
        $delivery = [];
        if ($address) {
            $delivery = [
                'city' => (string) $address->city,
                'address' => trim($address->address1 . ' ' . $address->address2),
                'postcode' => (string) $address->postcode,
                'country' => (string) Country::getIsoById((int) $address->id_country),
            ];
        }
        $carrier = $this->buildCarrierPayload($order, $idLang);
        if ($carrier) {
            $delivery = array_merge($delivery, $carrier);
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
            // Технічна назва платіжного модуля (ps_wirepayment, ps_cashondelivery, …).
            // Саме за нею бек визначає тип оплати: `payment` вище — це вітрина, вона
            // локалізована («Payment by check» / «Оплата при отриманні») і як ключ не годиться.
            'paymentModule' => (string) $order->module,
            // Оплата очима магазину. order.created відправляється в кінці запиту, коли ядро
            // вже проставило статус (hookActionValidateOrder), тож оплата шлюзу, що створює
            // замовлення оплаченим (Portmone 2.x), їде одразу тут. Пізніші зміни оплати
            // (PAID_BUT_NOT_VERIFIED → PAID колбеком) доносить order.status_updated.
            'paid' => (bool) $order->hasBeenPaid(),
            'paymentState' => $this->resolvePaymentState($order),
            'totalPaidReal' => (float) $order->total_paid_real,
            'comment' => (string) $this->getOrderComment($order),
            'client' => [
                'name' => trim($customer->firstname . ' ' . $customer->lastname),
                'email' => (string) $customer->email,
                'phone' => (string) $phone,
            ],
            'delivery' => $delivery ?: null,
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
        $idOrder = (int) (isset($payload['orderId']) ? $payload['orderId'] : 0);

        try {
            // Вимкнено перемикачем — подію НЕ викидаємо, а відкладаємо: вона поїде, щойно
            // модуль ввімкнуть (раніше тут був return, і замовлення зникали — тікет #8287)
            if (!Configuration::get('SKYSERVICE_ENABLED')) {
                $this->enqueue($event, $idOrder, $payload, 'module disabled', 0, 0);

                return;
            }

            $result = $this->send($event, $payload);
            if ($result['ok']) {
                return;
            }

            $this->enqueue($event, $idOrder, $payload, $result['error']);
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

        // Битий UTF-8 у будь-якому полі (назва статусу, адреса з чужого модуля) не має
        // перетворювати подію на порожнє тіло: json_encode тоді віддає false, curl шле "",
        // а підпис від порожнього рядка на беку сходиться — подія «доставлена» і зникає
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            return ['ok' => false, 'error' => 'json encode failed: ' . json_last_error_msg()];
        }
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

    /**
     * @param int $attempts  Скільки спроб уже витрачено: 1 після невдалої відправки,
     *                       0 — подію ще не пробували слати (модуль вимкнено, догрузка)
     * @param int $delaySec  Через скільки секунд пробувати: після невдачі — хвилина бекофу,
     *                       щоб не бити лежачий бек на кожному checkout; відкладені без
     *                       спроби — одразу, на першому ж хуку після ввімкнення
     */
    private function enqueue($event, $idOrder, array $payload, $error, $attempts = 1, $delaySec = 60)
    {
        Db::getInstance()->insert('skyservice_outbox', [
            'event' => pSQL($event),
            'id_order' => (int) $idOrder,
            'payload' => pSQL(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), true),
            'attempts' => (int) $attempts,
            'last_error' => pSQL((string) $error, true),
            'date_add' => date('Y-m-d H:i:s'),
            'date_next' => date('Y-m-d H:i:s', time() + (int) $delaySec),
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
        // Вимкнено — нічого не шлемо, черга чекає ввімкнення
        if (!Configuration::get('SKYSERVICE_ENABLED')) {
            return;
        }

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

    /**
     * Ставить в outbox замовлення, створені в обхід хука (модуль був вимкнений у менеджері
     * модулів), тобто новіші за курсор, яких ще немає в outbox.
     *
     * Скасовані й помилкові замовлення пропускаємо: на касі їм нема чого робити. Решта йде
     * з ПОТОЧНИМ станом замовлення — якщо його встигли оплатити, каса побачить оплату одразу.
     *
     * @param int[] $exceptOrderIds Замовлення, відкладені в цьому запиті (їх відправить
     *                              dispatchPendingCreated) — їх не дублюємо
     */
    private function catchUpMissedOrders(array $exceptOrderIds = [])
    {
        $cursor = Configuration::get('SKYSERVICE_LAST_ORDER_ID');
        if ($cursor === false || $cursor === null || $cursor === '') {
            // Встановлено до версії з курсором і без апгрейд-скрипта — стартуємо з поточного
            // стану, а не тягнемо на касу всю історію магазину
            Configuration::updateValue('SKYSERVICE_LAST_ORDER_ID', self::getMaxOrderId());

            return;
        }
        $cursor = (int) $cursor;

        $skipStates = array_filter([
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_ERROR'),
        ]);

        $rows = Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`current_state` FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE o.`id_order` > ' . $cursor . '
               ' . ($exceptOrderIds ? 'AND o.`id_order` NOT IN (' . implode(',', array_map('intval', $exceptOrderIds)) . ')' : '') . '
               AND NOT EXISTS (
                   SELECT 1 FROM `' . _DB_PREFIX_ . 'skyservice_outbox` ob
                   WHERE ob.`id_order` = o.`id_order` AND ob.`event` = \'order.created\'
               )
             ORDER BY o.`id_order` ASC
             LIMIT ' . (int) self::CATCH_UP_LIMIT
        );

        if (!$rows) {
            return;
        }

        $lastId = $cursor;
        foreach ($rows as $row) {
            $idOrder = (int) $row['id_order'];
            $lastId = max($lastId, $idOrder);

            if (in_array((int) $row['current_state'], $skipStates, true)) {
                continue;
            }

            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order)) {
                continue;
            }

            $this->enqueue('order.created', $idOrder, $this->buildOrderPayload($order), 'missed while module was disabled', 0, 0);
        }

        $this->advanceOrderCursor($lastId);
    }

    /**
     * Курсор лише зростає: паралельний checkout зі старшим id не має відкотити його назад
     * (інакше те саме замовлення догрузилось би вдруге — не страшно, бек дедуплікує, але зайве).
     */
    private function advanceOrderCursor($idOrder)
    {
        if ((int) $idOrder > (int) Configuration::get('SKYSERVICE_LAST_ORDER_ID')) {
            Configuration::updateValue('SKYSERVICE_LAST_ORDER_ID', (int) $idOrder);
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
        $wasEnabled = (bool) Configuration::get('SKYSERVICE_ENABLED');
        $isEnabled = (bool) Tools::getValue('SKYSERVICE_ENABLED');
        Configuration::updateValue('SKYSERVICE_ENABLED', (int) $isEnabled);
        $pickupReferences = [];
        foreach ($this->getShopCarriers() as $carrier) {
            if (Tools::getValue('SKYSERVICE_PICKUP_CARRIERS_' . $carrier['reference'])) {
                $pickupReferences[] = $carrier['reference'];
            }
        }
        Configuration::updateValue('SKYSERVICE_PICKUP_CARRIERS', json_encode(array_values(array_unique($pickupReferences))));
        // Порожнє поле секрету означає «лишити як є» — щоб збереження форми не стирало ключ
        if ($secret !== '') {
            Configuration::updateValue('SKYSERVICE_SECRET', $secret);
        }

        $this->html .= $this->displayConfirmation($this->l('Налаштування збережено'));

        // Щойно ввімкнули — одразу доставляємо накопичене за час вимкнення, а не чекаємо
        // наступного замовлення (у тихому магазині воно може бути й завтра)
        if ($isEnabled && !$wasEnabled) {
            $this->catchUpMissedOrders();
            $this->flushOutbox();
        }
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
        $pickupReferences = $this->getPickupCarrierReferences();
        $carrierOptions = [];
        $pickupValues = [];
        foreach ($this->getShopCarriers() as $carrier) {
            // Модульні перевізники можуть мати однаковий reference у кількох рядках — показуємо раз
            if (isset($pickupValues['SKYSERVICE_PICKUP_CARRIERS_' . $carrier['reference']])) {
                continue;
            }
            $carrierOptions[] = [
                'id' => $carrier['reference'],
                'name' => $carrier['name'] . ($carrier['delay'] !== '' ? ' — ' . $carrier['delay'] : ''),
            ];
            $pickupValues['SKYSERVICE_PICKUP_CARRIERS_' . $carrier['reference']] =
                in_array($carrier['reference'], $pickupReferences, true);
        }

        $fields = [
            'form' => [
                'legend' => ['title' => $this->l('Підключення до SkyService'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Увімкнено'),
                        'name' => 'SKYSERVICE_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Поки вимкнено, замовлення накопичуються й будуть передані в SkyService після ввімкнення.'),
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
                    [
                        'type' => 'checkbox',
                        'label' => $this->l('Самовивіз'),
                        'name' => 'SKYSERVICE_PICKUP_CARRIERS',
                        'desc' => $this->l('Перевізники, які означають самовивіз. Такі замовлення каса покаже як самовивіз, без адреси доставки.'),
                        'values' => [
                            'query' => $carrierOptions,
                            'id' => 'id',
                            'name' => 'name',
                        ],
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
            'fields_value' => $pickupValues + [
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
