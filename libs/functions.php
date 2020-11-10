<?php

function getOAuthTokenAmo($gnzsWidgetId, $amoAccountId)
{
    if (empty($gnzsWidgetId) || empty($amoAccountId))
        throw new Exception('Не заданы параметры');
    $curl = new \Curl\Curl();
    $curl->setHeader('X-Client-Id', $amoAccountId);
    $curl->get('https://core.gnzs.ru/amocrm/oauth2/get-token.php', ['gnzs_widget_id' => $gnzsWidgetId]);
    $result = [];
    if ($curl->error) {
        throw new Exception($curl->errorMessage);
    } else {
        $result = $curl->response;
    }
    return $result; 
}

/**
 *  Из данных полученных по виджету по группам и работникам формируем отдельные ассоциативные массивы один по группам, второй по работникам
 *  
 * - При формировании массивов каждая группа и работник это отдельный элемент массива, ключом которого является идентификатор а значением состояние чикета
 *  @param array  $arrayWijet - массив (из виджита) групп в каждой группе есть массив работников относящимся к данной группе
 *  @param array  &$arrayGroupWiget - передаётся (по ссылке) пустым но заполняется группами
 *  @param array  &$arrayUserWiget - передаётся (по ссылке) пустым но заполняется работниками 
 *  @return bool если хотя бы один массив не пустой то true иначе false
 */
function formationArraysGruopsAndUsersOfWiget ($arrayWijet, &$arrayGroupWiget, &$arrayUserWiget)
{
    $flagIsChecked = false;
    foreach ($arrayWijet as $key => $group) {
        $arrayGroupWiget[$group->id] = $group->isChecked;
        // если группа зачикена значит ставим флаг в истину
        if (!empty($group->isChecked)) {
            $flagIsChecked = true;
        }

        foreach ($group->managers as $key => $user) {
            $arrayUserWiget[$user->id] = $user->isChecked;
            // если работник зачикенин значит ставим флаг в истину
            if (!empty($user->isChecked)) {
                $flagIsChecked = true;
            }
        }
    }
    return $flagIsChecked;
}

/**
*  Описание:
*  - 
*  @param 
*  @return
*/
function setNewIdExecutorLead($arrayUsersAccount, $arrayGroupWiget, $arrayUserWiget, $idLastExecutorLead){

    $arraySelectUsersAccount = [];

    // Формируем из работников аккаунта список учавствующих в распределении сделки
    foreach ($arrayUsersAccount as $key => $user) {
        // Если чикед группы в которой работник в труе то заносим его в список для распределения
        if (!empty($arrayGroupWiget['group_' . $user['group_id']])) {
            $arraySelectUsersAccount[] = $user['id'];
        }
        // Если работник есть в массиве работников из виджета с чикетом то добавляем
        else if (!empty($arrayUserWiget[$user['id']])) {
            $arraySelectUsersAccount[] = $user['id'];
        }
    }

    // Устанавливаем нового отвественного
    // Если таблица пустая то берём первого
    if (empty($idLastExecutorLead)) {
        return $arraySelectUsersAccount[0];
    } else {
        // Если таблица не пуста 

        //находим пользователя с айди из базы и берём его порядковый номер
        for ($i = 0; $i < count($arraySelectUsersAccount); $i++) {
            $id = $arraySelectUsersAccount[$i];
            if ($id == $idLastExecutorLead) {
                $key_user = $i;
                break;
            }
        }

        // Если порядковый номер юзера не последний в списке то берём следующий порядковый номер 
        if ($key_user < count($arraySelectUsersAccount) - 1) {
            $key = $key_user + 1;
        } else {
            // Если не не последний значит поледний соотвественно берём 0 т.е. первого юзера
            $key = 0;
        }
        // По установленному ключу берём айди юзера
        return $arraySelectUsersAccount[$key];
    }
}