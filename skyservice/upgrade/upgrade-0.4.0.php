<?php
/**
 * 0.4.0: замовлення шлють перевізника й ознаку самовивозу (тікет #8283).
 *
 * Перелік перевізників-самовивозів заповнюємо одразу, щоб після оновлення «Click and collect»
 * запрацював без ручного налаштування. Вже збережений перелік не чіпаємо.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_0($module)
{
    if (Configuration::get('SKYSERVICE_PICKUP_CARRIERS') === false) {
        Configuration::updateValue('SKYSERVICE_PICKUP_CARRIERS', json_encode($module->detectPickupCarrierReferences()));
    }

    return true;
}
