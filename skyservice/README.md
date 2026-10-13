# skyservice — модуль PrestaShop для передачі замовлень у SkyService

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
| `actionOrderStatusPostUpdate` | `order.status_updated` | Оплата (головне), плюс скасування/повернення |

### Оплата їде другою подією — і це не можна спростити

`PaymentModule::validateOrder()` виконує `actionValidateOrder` **раніше**, ніж `OrderHistory`
проставить замовленню статус. У момент першого хука історії станів ще не існує, тож
`hasBeenPaid()` дає `false` навіть у шлюзів, які створюють замовлення вже оплаченим. Тому
`order.created` майже завжди їде неоплаченим, а факт оплати доносить
`order.status_updated` — бек за ним переводить замовлення в `paid`.

Поля платіжного контексту в обох подіях: `paymentModule`, `paymentMethod`, `paymentState`,
`totalPaid`, `totalPaidReal` (в `order.created` ще й `paid`).

### `paymentState`: чому недосить прапора магазину

`paymentState` зводить оплату до `paid` | `paid_unverified` | `preauth` | `pending`.

Прапор `paid` самого PrestaShop для цього не годиться: шлюзи заводять власні статуси
замовлення й ставлять прапор як їм зручно. Офіційний модуль **Portmone** (`portmone`)
створює `PORTMONE_PAID`, `PORTMONE_PAID_BUT_NOT_VERIFIED`, `PORTMONE_PREAUTH` і (у 2.x)
`PORTMONE_ERROR` — і всім чотирьом ставить `paid = 1`. Тобто і блокування коштів, і
**відмова за платежем** виглядають для магазину оплатою.

Розрізнити їх можна лише за id станів, які модуль поклав у `Configuration`, і доступні вони
тільки тут, у магазині, — тому нормалізація живе в модулі (`resolvePaymentState()`), а бек
працює з готовими чотирма значеннями.

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

### Самовивіз (0.4.0, тікет #8283)

У `delivery` крім адреси їдуть `carrierId`, `carrierReference`, `carrierName`,
`carrierDelay` і `isPickup`. За `isPickup` бек ставить замовленню `orderType: "self-delivery"`.
Адреса ознакою самовивозу не є: PrestaShop вимагає її на чекауті навіть для «Click and collect».

Ознаки «самовивіз» у перевізника ядро не має, тож перелік живе в налаштуваннях модуля
(`SKYSERVICE_PICKUP_CARRIERS`, JSON-масив `id_reference` — reference не змінюється при
редагуванні перевізника, на відміну від `id_carrier`). При встановленні/оновленні він
заповнюється сам: коробковий «Click and collect» (`id_reference = 1`) плюс перевізники, у
назві чи строку доставки яких є «самовивіз», «pickup», «click and collect» тощо.

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

Модулі → skyservice → Налаштувати: URL API, ID компанії, секрет підпису, вмикач,
перевізники-самовивози (чекбокси).
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
- підпису відповіді беку (зараз довіряємо тілу відповіді, а не лише коду 2xx)
- реакції беку на скасування/повернення: подія доїжджає, але залишок назад не повертається
