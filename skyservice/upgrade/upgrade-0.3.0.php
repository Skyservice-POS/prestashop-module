<?php
/**
 * 0.3.0: курсор SKYSERVICE_LAST_ORDER_ID для догрузки замовлень, пропущених за час
 * вимкнення модуля (тікет #8287).
 *
 * Стартуємо з поточного максимуму, а не з нуля: інакше перше ж замовлення після апгрейду
 * потягло б на касу всю історію магазину.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0($module)
{
    if (Configuration::get('SKYSERVICE_LAST_ORDER_ID') === false) {
        Configuration::updateValue('SKYSERVICE_LAST_ORDER_ID', SkyService::getMaxOrderId());
    }

    return true;
}
