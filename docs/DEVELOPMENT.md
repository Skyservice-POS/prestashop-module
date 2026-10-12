# Технічні нотатки модуля skyservice

Тонкий модуль: у магазині живе одна відповідальність — зловити хук і віддати замовлення
беку. Каталог, ціни, залишки та мапінг товарів синхронізує бек через вбудований
Webservice API магазину, тому сюди ця логіка не додається — інакше кожна правка вимагала б
від клієнта оновлення модуля на своєму хостингу.

Модуль потрібен тому, що PrestaShop не вміє вихідних вебхуків у принципі. Без нього
лишається лише polling замовлень, тобто лаг на касі.

## Хуки

| Хук | Подія | Навіщо |
|---|---|---|
| `actionValidateOrder` | `order.created` | Замовлення підтверджене — має зʼявитись на касі |
| `actionOrderStatusPostUpdate` | `order.status_updated` | Скасування/повернення — бек повертає залишок |

## Формат доставки

`POST {API_URL}/?section=prestashop&action=webhook&company={companyId}`

Параметр саме `company`, а НЕ `companyId`: роутер беку вважає запит із `companyId`
автентифікованим і вимагає токен, якого в магазина немає.

Заголовки:

- `X-Sky-Event` — `order.created` | `order.status_updated` | `ping`
- `X-Sky-Signature` — `base64(hmac_sha256(rawBody, secret))`
- `X-Sky-Source` — базовий URL магазину
- `X-Sky-Module-Version`

Позиція замовлення ідентифікується парою `productId:attributeId` (у товару без варіацій
атрибут = `0`) — той самий ключ, що `productId:variationId` у WooCommerce-конекторі.
Артикул береться з `product_reference`, штрихкод з `product_ean13` (фолбек `product_upc`);
у варіації вони власні, тому читаються з рядка замовлення, а не з батьківського товару.
Дата віддається в UTC — магазин пише `date_add` у своїй таймзоні.

## Доставка й ретраї

Одна синхронна спроба в хуку з таймаутом 5 с — checkout важливіший за інтеграцію.
Невдача осідає в `{prefix}skyservice_outbox`, повтори доштовхуються на наступному хуку
з бекофом 1/2/4/8/16 хв, максимум 5 спроб. Лічильники видно на сторінці налаштувань.

Оскільки ретраї контролюємо ми, бек може відповідати чесним 5xx — на відміну від
WooCommerce-приймача, який змушений завжди віддавати 200, бо магазин гасить підписку
після 5 невдалих доставок.

Тіла хуків цілком у `try/catch (Throwable)`: `Error`/`TypeError` з ядра магазину — теж
наш ризик, і пропущений назовні він зламає покупцеві оформлення замовлення.

Обмеження поточної версії: outbox рухається лише коли в магазині є активність. Для бою
сюди треба додати крон-контролер.

## Налаштування

Модулі → skyservice → Налаштувати: URL API, ID компанії, секрет підпису, вмикач.
Кнопка «Перевірити зʼєднання» шле подію `ping` тим самим шляхом, що й замовлення.
Порожнє поле секрету при збереженні означає «лишити поточний».

## Локальний стенд

Заглушка приймача: `_skyfake/index.php` у корені магазину — перевіряє HMAC і пише
доставки в `_skyfake/log.jsonl`. Секрет стенда `test-secret-123`.

```bash
docker compose exec -T -u www-data app php bin/console prestashop:module install skyservice

# прогнати справжнє замовлення через хук (у CLI контекст магазину порожній —
# валюту/мову/країну треба ініціалізувати вручну, у HTTP-запиті це робить ядро)
docker compose exec -T -u www-data app php -r '
require "config/config.inc.php";
require_once "modules/skyservice/skyservice.php";
$ctx = Context::getContext();
$ctx->currency = new Currency((int) Configuration::get("PS_CURRENCY_DEFAULT"));
$ctx->language = new Language((int) Configuration::get("PS_LANG_DEFAULT"));
$ctx->country  = new Country((int) Configuration::get("PS_COUNTRY_DEFAULT"));
(new SkyService())->hookActionValidateOrder(["order" => new Order(2)]);'
```

## Чого ще немає

- крон-контролера для outbox
- підпису відповіді беку (зараз довіряємо будь-якому 2xx)
