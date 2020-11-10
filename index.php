<?php
// Подключаемся к базе данных

define('HOST', 'localhost');
define('USER_NAME', 'ahel73');
define('PASSWORD', '');
define('DATA_BASE', 'task');

$dbc = mysqli_connect(HOST, USER_NAME, PASSWORD, DATA_BASE) or die('Ошибка подключения к MySQL серверу.');
mysqli_set_charset($dbc, 'utf8');

// действия по задачам
$query_leads = "select * from leads_counter WHERE date = CURDATE();"; // запрашиваем активность за сегодня, т.е. список менеджеров которым были распределены задачи
$obj_query =  mysqli_query($dbc, $query_leads);
$array_leads = mysqli_fetch_all($obj_query, MYSQLI_ASSOC); // Массив состоит из элементов менеджеров кому распределены сделки, у каждого менеджера в совойстве counter отражается количество задач распределённых ему за сегодня 

$count_array_leads = 0;
$array_managers_leads = []; //ассоциатвный массив ключ id пользователя значение колиство задач

if(count($array_leads) > 0){
    foreach ($array_leads as $key => $value) {
        $array_managers_leads[$value['id_manager']] = $value['counter'];
        $count_array_leads += $value['counter']; // в коунтер по менеджеру указывается количество задач по каждому менеджеру
    }
}

$percent_one_leads = (!empty($count_array_leads)) ? 100 / $count_array_leads : 0; // Процентная доля одной задачи в числе всех задач

    

// Действия по пользователям
$name_column_manegers = "
    groups.id as id_group, 
    groups.name as group_name,	
    groups.load_percent as group_max_percent, 
    managers.id as id_manager, 
    managers.name as manager_name, 
    load_percent_by_group,	
    managers.load_percent as percent_in_global,	
    is_active
";
$query_managers = "SELECT {$name_column_manegers}, managers.id as id_manager, groups.id as id_group FROM groups, managers WHERE managers.group_id = groups.id AND managers.is_active = true;";
$obj_query =  mysqli_query($dbc, $query_managers);
$array_managers_dump = mysqli_fetch_all($obj_query, MYSQLI_ASSOC);

$array_managers_lucky = []; // массив куда будем добавлять менеджеров с отсутствием задач
$array_managers_all = []; // все менеджеры
$min__workload = 0; // контрольная сумма процента загруженности менеджерв
foreach ($array_managers_dump as $key => $value) {
    // Если у менеджера есть задача
    if (isset($array_managers_leads[$value['id_manager']])) {
        $value['count_leads'] =  $array_managers_leads[$value['id_manager']]; //количество задач
        $value['percent_workload'] = $value['count_leads'] * $percent_one_leads; // Процент загруженности относительно всех задач
        $value['delta_adding'] = $value['percent_in_global'] - $value['percent_workload']; // процент сколько можно добавть процентов относительно разрешённого процента от  всех задач
    }
    // Если задач у менеджера нет
    else {
        $value['count_leads'] =  0; //количество задач
        $value['percent_workload'] = 0; // Процент загруженности относительно всех задач
        $value['delta_adding'] = $value['percent_in_global'];  // процент сколько можно добавть процентов относительно  разрешённого процента от всех задач
    }

    $array_managers_all[$value['id_manager']] = $value; // Формируем общий массив менеджеров со всеми данными 
}



// Поступила новая задача 
if (!empty($_POST)) {
    // Если это первая задача
    if($count_array_leads == 0){
        $max_workload_percent = 0;
        foreach ($array_managers_all as $key => $value) {
            
            if ($value['percent_in_global']  > $max_workload_percent) {
                $max_workload_percent = $value['percent_in_global'];
                $lucky_manager = $value['id_manager'];               
            }
        }    
    }else{
        $min_percent_workload = 100;
        $max_workload_percent = 0;
        foreach ($array_managers_all as $key => $value) {
            if(($value['percent_workload '] < $min_percent_workload) && ($value['percent_in_global']  > $max_workload_percent) && $value['delta_adding'] > 0){
                $min_percent_workload = $value['percent_workload'];
                $max_workload_percent = $value['percent_in_global'];
                $lucky_manager = $value['id_manager'];   
            }
        }
        
    }

    // Если есть новая задача и определён исполнитель
    if (isset($lucky_manager)) {
        $array_managers_all[$lucky_manager]['count_leads'] += 1;
        if ($array_managers_all[$lucky_manager]['count_leads'] == 1) {
            $string_query = "INSERT INTO `leads_counter`(`id_manager`, `counter`, `date`) VALUES ({$lucky_manager}, {$array_managers_all[$lucky_manager]['count_leads']}, CURDATE())";
        } else {
            $string_query = "UPDATE `leads_counter` SET counter = {$array_managers_all[$lucky_manager]['count_leads']} WHERE id_manager = {$lucky_manager}";
        }

        mysqli_query($dbc, $string_query);
    }
}
?>


<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Задачи отделов</title>
</head>

<body>
    <h1>Задачи отделов!!!!!</h1>
    <form action="" method="POST">
        <input type="number" name="number" value="1">
        <button type="submit">Добавить</button>
    </form>
    
    <ul>
        <?php foreach ($array_managers_all as $key => $value) : ?>
            <li><?= $value['manager_name'] ?> (id: <?= $value['id_manager'] ?>) - количество задач <?= $value['count_leads'] ?>.  процент выполняемых задач - <?= $value['percent_workload'] ?> / максимальный процент - <?= $value['percent_in_global'] ?>.</li>
        <?php endforeach; ?>
    </ul>


    <?php
    $objiy_pocent = 0;
    $objiy_percent_workload = 0;
    foreach ($array_managers_all as $key => $value) :
        $objiy_percent_workload += $value['percent_workload'];
        $objiy_pocent += $value['percent_in_global'];
    endforeach; ?>
    <h2>Общее количество задач: <?= $count_array_leads ?> процентная доля одной задачи <?= $percent_one_leads ?> / <?= $percent_one_leads * $count_array_leads ?> </h2>
    <h2>Расчётный общий размер допустимого процента нагрузки: <?= $objiy_pocent ?></h2>
    <h2>Фактический общий размер процента нагрузки: <?= $objiy_percent_workload ?></h2>
</body>

</html>