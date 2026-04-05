<?php
include_once 'cronHead.php';

$fecha = date("Y-m-d") . " 00:00:00";
$tasks = ncmExecute("SELECT ID,date,type,status,data,sourceId, companyId FROM tasks where status = 'pending' AND date <= '" . $fecha . "' LIMIT 500", [], false, false, true);

if ($tasks) {
    foreach ($tasks as $task) {

        $data = json_decode($task['data']);
        $name = $data->descripcion;

        insertNotifications(array(
            'message' => "El articulo " . $name . " ha vencido",
            'title' => 'Vencimiento del Lote',
            'type' => $task['type'],
            'company' => $task['companyId'],
            'date' => $task['date'],
            'push' => array(
                'where' => 'panel'
            )
        ));
        $record['status'] = 'finished';

        $update = $db->AutoExecute('tasks', $record, 'UPDATE', 'ID = ' . $task['ID'] . ' AND companyId = ' . $task['companyId']);
    }
} else {
    dai();
}
