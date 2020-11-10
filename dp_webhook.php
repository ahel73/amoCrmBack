<?php
/**
*  Скрипт устанавливает нового отвественного по сделке на определённом этапе отправляет о нём данные в амо
* 
*  Данные о новом отвественном и иные настройки приходят из объекта виджите
*
*  АЛГОРИТМ ФОРМИРОВАНИЯ СПИСКА РАБОТНИКОВ МЕЖДУ КОТОРЫМИ РАСПРЕДЕЛЯЕТСЯ ЗАДАЧА
*    
*    1. Из виджита Формируем масив из групп и массив из работников, ключ элемента это идентификаторы
*    2. Создаём пустой массив куда будем добавлять пользователей из списка аккаунта 
*        - если он отмечен в виджите
*        - если его нет в списке виджета но  группа в которой он, отмечена в виджите
*    3. Если массив из обязательных не пустой то в распределении участвуют эти работники
*    4. Если массив из обязательных пустой то выходим из скрипта
*/
require_once './libs/functions.php';
require_once './libs/Log.php';
require_once './libs/ExtendsException.php';
require_once './vendor/autoload.php';



$gnzsWidgetId = 16; // id виджета
// Получаем из хука который в POST необходимые данные 
$clientId = $_POST['account_id']; // id аккаунта
$leadId = $_POST['event']['data']['id']; // id сделки
$pipelineId = $_POST['event']['data']['pipeline_id']; // айди воронки
$statusId = $_POST['event']['data']['status_id']; // айди статуса (ряда) по воронке
$objSettings = json_decode($_POST['action']['settings']['widget']['settings']['genezis_settings']); // объект настроек виджита по этапу
$settingsId  = $objSettings->settingsId; // id объекта настроек
$arrayGruopAndUsersWiget = $objSettings->groups; // Массив из виджита с группами аккаунта в каждой группе работники по данной группе
$arrayGroupWiget = []; // Массив в котором будут храниться группы из виджита значением ключа является значение isChecked группы. Нужен для того что могут быть новые работники которых нет в настройках виджета, но их надо добавить в очередь. Ещё надо помнить, что даже если группа выделена то в настройках виджета с любого работника группы можно снять выделение он будет исключением
$arrayUserWiget = []; // Массив работников из виджета. Ключ идентификатор работника а значенеим состояние isChecked данного работника. Надо помнить если выбрана группа то автоматически выбираются все работники из группы но при этом с каждого работника можно индивидуально снять группу.
$flagIsChecked = null; // если истина значит в виджите есть отмеченные группы или работники среди которых надо распределять сделку


try {

    // ------------------------- ПОДГОТОВКА К РАСПРЕДЕЛЕНИЮ -------------------------
    {
        // 1. Создаём файл для записи и записываем хук
        {
            $log = new Log\Log('./log/', 'webhook.');
            $log->add('-------');
            $log->add('START');
            // $log->add(json_encode($_POST)); // Записываем веб хук ----------------
            // $log->add('<br> линия ' . __LINE__ . '<br>');
            // $log->add('<pre>' . print_r($objSettings, true) . '</pre>');
        }

        // 2. Получаем и проверяем токен
        {
            // Получаем токен
            try {
                $token = getOAuthTokenAmo($gnzsWidgetId, $clientId);
            } catch (Exception $e) {
                $log->add('Ошибка получения токена авторизации amoCRM: ' . $e->getMessage());
            }

            // Проверяем токен 
            if (empty($token) || empty($token->base_domain) || empty($token->access_token)) {
                throw new WebHookException('Не смогли получить токен');
            }
        }

        // 3. Создаём сущность амо, а из неё объекты аккаунта и сделки и записываем в лог эти объекты 
        {
            $amo = new \AmoCRM\Client($token->base_domain, $token->access_token);

            // Получаем объект аккаунта
            $account = $amo->account->apiCurrent();
            // $log->add(json_encode($account)); // Записываем аккаунт ------------------------
            $arrayUsersAccount = $account['users']; // Массив из юзеров аккаунта каждый юзер это тоже массив

            // Получаем сделку
            $target_lead = $amo->lead->apiList(['id' => $leadId,]);
            if (empty($target_lead)) {
                throw new Exception(' Не найдена сделка по указанному id' . $leadId);
            }
            $target_lead = $target_lead[0];
            // $log->add('<br> линия ' . __LINE__ . '<br>');
            // $log->add('<pre>' . print_r($target_lead, true) . '</pre>');
            
            // $log->add(json_encode($target_lead)); // Записываем сделку ---------------------
        }
        
    }

    // ------------------------------ РАСПРЕДЕЛЕНИЕ ----------------------------------
    {
        // 1. если распределять в рабочее время то устанавливаем попадаем ли во временной деапазон
        if ($objSettings->otherOptions->isAssignOnlyWorkime->checked == 1) {
            $timezoneAccount = $account['timezoneoffset'];
            $shiftTimezone = implode('.', explode(':', $timezoneAccount)) * 60 * 60;
            date_default_timezone_set('UTC');
            $actualTime  = date('H:i:s/D', time() + $shiftTimezone);
            $arrayActualTime = explode('/', $actualTime);
            $actualWeekDay = strtolower($arrayActualTime[1]);
            $arrayWeekDays = $objSettings->workDays;

            if ($arrayWeekDays->{$actualWeekDay}->checked == 1) {
                $actualTime = explode(':', $arrayActualTime[0]);
                // Устанавливаем сколько сейчас секунд в часах
                if ($actualTime[0] == 00) {
                    $actualHour = 0;
                } else {
                    $actualHour = $actualTime[0] * 60 * 60;
                }
                // Устанавливаем сколько сейчас секунд в минутах
                if ($actualTime[1] == 00) {
                    $actualМinutes = 0;
                } else {
                    $actualМinutes = $actualTime[1] * 60;
                }
                // Устанавливаем сколько сейчас секунд в секундах
                if ($actualTime[2] == 00) {
                    $actualSeconds = 0;
                } else {
                    $actualSeconds = $actualTime[2];
                }
                $actualTime = $actualHour + $actualМinutes + $actualSeconds;

                $startTime = $objSettings->workTime->start;
                $startTime = explode(':', $startTime);
                $startTime = ($startTime[0] * 60 * 60) + ($startTime[1] * 60);

                $endTime = $objSettings->workTime->end;
                $endTime = explode(':', $endTime);
                $endTime = ($endTime[0] * 60 * 60) + ($endTime[1] * 60);

                if ($actualTime < $startTime || $actualTime > $endTime) {
                    throw new Exception('Извините вы не вовремя рабочий день ещё не начался');
                }
            } else {
                throw new Exception('Извините сегодня мы отдыхаем, возвращаем задачу');
            }
        }

        // 2. Повторные сделки
        if ($objSettings->repeatLeadsControl != 1){
            // Вопрос если не выполняется условие т.е. нет контакта или компании выходим или идим на распределение между менеджерами
            
            // 2.1. Повторные сделки по Контакту распределять на ответственного
            if ($objSettings->repeatLeadsControl == 2 && !empty($target_lead['main_contact_id'])
            ) {

                // Получаем ответственного по контакту 
                $contact = $amo->contact->apiList(['id' => $target_lead['main_contact_id'],])[0];
                                
                if (count($contact['linked_leads_id']) > 1) {
                    $newIdExecutorLead = $contact['responsible_user_id'];
                    $log->add('<br> линия ' . __LINE__ . '<br>');
                    $log->add('<br> Повторные сделки по Контакту распределять на ответственного -' . $newIdExecutorLead . '<br>');
                }
            } 
            // else {
            //     throw new Exception('Выходим у сделки нет контакта');
            // }

            // 2.2. Повторные сделки по Компании распределять на ответственного
            if ($objSettings->repeatLeadsControl == 3 && !empty($target_lead['linked_company_id'])) {

                // Получаем ответственного по контакту 
                $company = $amo->company->apiList(['id' => $target_lead['linked_company_id'],])[0];
                
                if (count($company['linked_leads_id']) > 1) {
                    $newIdExecutorLead = $company['responsible_user_id'];
                    $log->add('<br> линия ' . __LINE__ . '<br>');
                    $log->add('<br> Повторные сделки по Контакту распределять на ответственного -' . $newIdExecutorLead . '<br>');
                }
            } 
            // else {
            //     throw new Exception('Выходим у сделки нет компании');
            // }

            // 2.3. Повторные сделки по Контакту и Компании распределять на ответственного
            if ($objSettings->repeatLeadsControl == 4 && (!empty(!empty($target_lead['main_contact_id']) || $target_lead['linked_company_id']))) {

                // Получаем ответственного по контакту 
                if (!empty($target_lead['main_contact_id'])) {
                    $contact = $amo->contact->apiList(['id' => $target_lead['main_contact_id'],])[0];

                    if (count($contact['linked_leads_id']) > 1) {
                        $newIdExecutorLead = $contact['responsible_user_id'];
                        $log->add('<br> линия ' . __LINE__ . '<br>');
                        $log->add('<br> Повторные сделки по Контакту распределять на ответственного -' . $newIdExecutorLead . '<br>');
                    }
                }


                // Получаем ответственного по компании 
                if (!empty($target_lead['linked_company_id']) && empty($newIdExecutorLead)) {
                    $company = $amo->company->apiList(['id' => $target_lead['linked_company_id'],])[0];

                    if (count($company['linked_leads_id']) > 1) {
                        $newIdExecutorLead = $company['responsible_user_id'];
                        $log->add('<br> линия ' . __LINE__ . '<br>');
                        $log->add('<br> Повторные сделки по Компании распределять на ответственного -' . $newIdExecutorLead . '<br>');
                    }
                }
            } 
            // else {
            //     throw new Exception('Выходим у сделки нет контакта и компании');
            // }
        }

        // 3. Устанавливаем ответственного по этапу сделки и отправляем эти данные в амо 
        if (empty($newIdExecutorLead)) {

            // 3.1. Подключаемся к базе данных
            {
                define('HOST', 'localhost');
                define('USER_NAME', 'db_ngissa_dael');
                define('PASSWORD', 'IiuKhwN@jYTfhox#w6VR56n$D0a35Uj~*c~dYVry');
                define('DATA_BASE', 'lead_assignment');

                $dbc = mysqli_connect(HOST, USER_NAME, PASSWORD, DATA_BASE);
                mysqli_set_charset($dbc, 'utf8');                
            }

            // 3.2. Ответственный по очереди
            if (empty($newIdExecutorLead) && $objSettings->assignType == 1) {

                // 1. Если нет выбранных работников или групп среди которых надо распределять задачу то выходим
                if (!formationArraysGruopsAndUsersOfWiget($arrayGruopAndUsersWiget, $arrayGroupWiget, $arrayUserWiget)) {
                    throw new Exception('Нет отмеченных групп и менеджеров среди которых устанавливать ответственного');
                }

                // 2. Устанавливаем предыдущего менеджера которому была распределена задача
                {
                    if (!$objQuery = mysqli_query($dbc, "select * from last_user_assign where account_id = {$clientId} and settings_id = '{$settingsId}';")) {
                        throw new Exception('Ошибка подключения к базе данных');
                    }

                    if ($objQuery->num_rows > 0) {
                        $array_row_db = mysqli_fetch_array($objQuery, MYSQLI_ASSOC);
                        $idLastExecutorLead = $array_row_db['last_user_assign_id'];
                        $idUpdate = $array_row_db['id'];
                    } else {
                        $idLastExecutorLead = null;
                    }

                    $log->add(sprintf('amoCRM OAuth2 authorization. domain %s', $token->base_domain));
                }

                // 3. Устанавливаем ответственного
                $newIdExecutorLead = setNewIdExecutorLead($arrayUsersAccount, $arrayGroupWiget, $arrayUserWiget, $idLastExecutorLead);

                //4. Отправка нового отвественного по сделке в амо и добавление данных об отвественнов базу данных
                if (!empty($newIdExecutorLead)) {
                    $lead = $amo->lead;
                    $lead['responsible_user_id'] = $newIdExecutorLead;
                    $lead->apiUpdate(
                        $leadId,
                        'now'
                    );

                    // Вносим изменения в базу
                    if (isset($idUpdate)) {
                        $query = "UPDATE last_user_assign SET last_user_assign_id = $newIdExecutorLead WHERE id = $idUpdate";
                    } else {
                        $query = "INSERT INTO last_user_assign (account_id, settings_id, pipeline_id, status_id, last_user_assign_id) VALUES ({$clientId}, '{$settingsId}', {$pipelineId}, {$statusId}, {$newIdExecutorLead});";
                    }

                    mysqli_query($dbc, $query);
                } else {
                    throw new Exception('Не удалось установить нового ответственного');
                }
            }

            // 3.3. Ответственный по процентам
            if (empty($newIdExecutorLead) && $objSettings->assignType == 2) {
                
                $log->add('<br>распределяем по процентам <br>');
                $groups = $objSettings->groups;
                $managersFromPercents = [];
                // --- 1. Вытаскиваем из сетевого массива менеджеров которым распределять по процентам
                foreach ($groups as $group) {
                    if ($group->percent > 0){
                        
                        foreach ($group->managers as $manager) {
                            $shellArrayManager = [];
                            $shellArrayManager['id_group'] = $group->id;
                            $shellArrayManager['group_name'] = $group->name;
                            $shellArrayManager['group_max_percent'] = $group->percent;
                            $shellArrayManager['id_manager'] = $manager->id ;
                            $shellArrayManager['manager_name'] = $manager->name;
                            $shellArrayManager['load_percent_by_group'] = $manager->percent;
                            $shellArrayManager['percent_in_global'] = $group->percent / 100 * $manager->percent; // Это процент в группе в глобальном эквиваленте
                            $managersFromPercents[] = $shellArrayManager;
                        }
                        
                    }
                }

                // --- 2. Если в массиве нет работников значит распределять не кому выходим из скрипта
                if (empty($managersFromPercents)) {
                    throw new Exception('Нет групп и менеджеров среди которых устанавливать ответственного в соответствии с процентом');
                }

                // --- 3. Запрашиваем из базы записи по менеджерам на которых сегодня распределили задачи и формируем массив менеджеров где по каждому указываем сколько у него сегодня задач, а так же устанавливаем общее количество задач полученных сегодня
                $query_leads = "select * from leads_counter WHERE date = CURDATE();"; // запрашиваем активность за сегодня, т.е. список менеджеров которым были распределены задачи
                $obj_query =  mysqli_query($dbc, $query_leads);
                $array_leads = mysqli_fetch_all($obj_query, MYSQLI_ASSOC); // Массив состоит из элементов менеджеров кому распределены сделки, у каждого менеджера в совойстве counter отражается количество задач распределённых ему за сегодня 
                $log->add('<br> линия ' . __LINE__ . '<br>');
                $log->add('<pre>' . print_r($array_leads, true) . '</pre>');

                $count_array_leads = 0;
                $array_managers_leads = []; //ассоциатвный массив ключ id пользователя значение колиство задач

                if (count($array_leads) > 0) {
                    foreach ($array_leads as $key => $value) {
                        $array_managers_leads[$value['id_manager']] = $value['counter'];
                        $count_array_leads += $value['counter']; // в коунтер по менеджеру указывается количество задач по каждому менеджеру
                    }
                }

                // --- 4. Устанавливаем процентную доля одной сделок в числе всех сделок
                $percent_one_leads = (!empty($count_array_leads)) ? 100 / $count_array_leads : 0; 

                // --- 5. Добавляем новые данные по каждому менеджеру с учётом суточной загруженности и процентой доли одной задачи
                // $array_managers_lucky = []; // массив куда будем добавлять менеджеров с отсутствием задач
                $array_managers_all = []; // все менеджеры
                // $min__workload = 0; // контрольная сумма процента загруженности менеджерв
                foreach ($managersFromPercents as $key => $value) {
                    // Если у менеджера есть задача
                    if (isset($array_managers_leads[$value['id_manager']])) {
                        //количество задач
                        $value['count_leads'] =  $array_managers_leads[$value['id_manager']]; 
                        // Процентная доля всех задач менеджера относительно общей доли всех задач
                        $value['percent_workload'] = $value['count_leads'] * $percent_one_leads; 
                        // Если больше нуля то учавствует в распределении задач
                        $value['delta_adding'] = $value['percent_in_global'] - $value['percent_workload']; 
                    }
                    // Если задач у менеджера нет
                    else {
                        //количество задач
                        $value['count_leads'] =  0; 
                        // Процентная доля всех задач менеджера относительно общей доли всех задач
                        $value['percent_workload'] = 0; 
                        // Если больше нуля то учавствует в распределении задач
                        $value['delta_adding'] = $value['percent_in_global'];  
                    }

                    $array_managers_all[$value['id_manager']] = $value; // Формируем общий массив менеджеров со всеми данными 
                }

                $log->add('<br> линия ' . __LINE__ . '<br>');
                $log->add('<pre>' . print_r($array_managers_all, true) . '</pre>');

                // --- 6. Распределение
                $max_workload_percent = 0; // Будем записывать максимальный процент нагрузки менеджера в глобальном плане 
                $min_percent_workload = 100; // Сюда будет записываться установленная минимальная процентная доля задач менеджера

                // --- 6.1. Если это первая задача за 
                if ($count_array_leads == 0) {
                    foreach ($array_managers_all as $key => $value) {
                        // Если глабальный процент нагрузки больше максимально устанеовленного, то думаем что это тот самый менеджер
                        if ($value['percent_in_global']  > $max_workload_percent) {
                            $max_workload_percent = $value['percent_in_global'];
                            $newIdExecutorLead = $value['id_manager'];
                        }
                    }
                } 
                // --- 6.2. Если задачи уже есть
                else {
                    foreach ($array_managers_all as $key => $value) {
                        // Если у менеджера
                        // процентная доля задач меньше установленной минимальной процентной доли 
                        // и его глабальный процент нагрузки больше максимально устанеовленного
                        // и дельта распределения задач больше нуля 
                        if (
                                ($value['percent_workload '] < $min_percent_workload) && 
                                ($value['percent_in_global']  > $max_workload_percent) && 
                                ($value['delta_adding'] > 0)
                            ) {
                            $min_percent_workload = $value['percent_workload'];
                            $max_workload_percent = $value['percent_in_global'];
                            $newIdExecutorLead = $value['id_manager'];
                        }
                    }

                }

                // --- 7. Если определён исполнитель
                if (isset($newIdExecutorLead)) {
                    $log->add('<br>распределяем по процентам <br>');
                    $lead = $amo->lead;
                    $lead['responsible_user_id'] = $newIdExecutorLead;
                    $lead->apiUpdate(
                        $leadId,
                        'now'
                    );

                    $array_managers_all[$newIdExecutorLead]['count_leads'] += 1;
                    if ($array_managers_all[$newIdExecutorLead]['count_leads'] == 1) {
                        $string_query = "INSERT INTO `leads_counter`(`id_manager`, `counter`, `date`) VALUES ({$newIdExecutorLead}, {$array_managers_all[$newIdExecutorLead]['count_leads']}, CURDATE())";
                    } else {
                        $string_query = "UPDATE `leads_counter` SET counter = {$array_managers_all[$newIdExecutorLead]['count_leads']} WHERE id_manager = {$newIdExecutorLead}";
                    }

                    mysqli_query($dbc, $string_query);
                }
            }            
        }

        // 4. Меняем отвественного по контакту и компании
        if ($objSettings->otherOptions->isChangeContactsRespUser->checked == 1) {

            // Обновление контактов
            if (!empty($target_lead['main_contact_id'])) {
                $contact = $amo->contact;
                $contact['responsible_user_id'] = $newIdExecutorLead;
                $contact->apiUpdate((int)$target_lead['main_contact_id'], 'now');
            } else {
                throw new Exception('Контакт по сделке: ' . $leadId . ', отсуствует');
            }


            // Обновление компаний
            if (!empty($target_lead['linked_company_id'])) {
                $company = $amo->company;
                $company['responsible_user_id'] = $newIdExecutorLead;
                $company->apiUpdate((int)$target_lead['linked_company_id'], 'now');
            } else {
                throw new Exception('Компания по сделке: ' . $leadId . ', отсуствует');
            }
        }

        // 5. Меняем ответственного у открытых задач по сделке
        if ($objSettings->otherOptions->isChangeTasksRespUser->checked == 1) {
            $arrayTasks = $amo->task->apiList([
                'type' => 'lead',
                'element_id' => $leadId,
            ]);

            foreach ($arrayTasks as $item) {
                if (empty($item['status'])) {
                    $task = $amo->task;
                    $task['responsible_user_id'] = $newIdExecutorLead;
                    if ($task->apiUpdate((int)$item['id'], 'Смена ответственного!!', 'now')) {
                        $log->add('<br> линия ' . __LINE__ . '<br>');
                        $log->add('Ответственный по задаче изменен и данные отправлены в амо');
                    } else {
                        throw new Exception('Ошибка изменения ответственного по задаче');
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    $log->add('!!! Словили непредвиденное исключение ' . $e->getMessage());
}
$log->add('END' . PHP_EOL);
exit;









