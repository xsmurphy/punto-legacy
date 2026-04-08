<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
$tasks = validateHttp("tasks", "post");

if (empty($tasks)) {
    apiOk(['error' => 'No hay tareas'], 422);
}

$records = array();
$resourcesId = array();
foreach ($tasks as $task) {
    extract($task);

    if (is_string($data)) {
        $data = stripslashes($data);
    }

    if (empty($data)) {
        apiOk(['error' => 'El campo data es obligatorio'], 422);
    }

    if (empty($date)) {
        apiOk(['error' => 'El date es obligatorio'], 422);
    }

    if ($type === null || $type === '') {
        apiOk(['error' => 'El type es obligatorio'], 422);
    }

    if (empty($resourceId)) {
        apiOk(['error' => 'El resourceId es obligatorio'], 422);
    } else {
        $resourceId = dec($resourceId);
    }
    $resourcesId[] = $resourceId;
}

ncmExecute("DELETE FROM tasks WHERE sourceId in (" . implode(",", $resourcesId) . ") and companyId = ? and type = ? and status = ?", [COMPANY_ID, $type, 'pending']);

foreach ($tasks as $task) {
    extract($task);
    if (is_string($data)) {
        $data = stripslashes($data);
    }
    if (!empty($resourceId)) {
        $resourceId = dec($resourceId);
    }
    $record = array(
        'type' => $type,
        'date' => $date,
        'data' => $data,
        'sourceId' => $resourceId,
        'status' => 'pending',
        'companyId' => COMPANY_ID,
        'outletId' => OUTLET_ID,
    );
    $insert = $db->AutoExecute('tasks', $record, 'INSERT');
    $taskId = $db->Insert_ID();
    $record['ID'] = enc($taskId);

    // if (!empty($insert)) {
    //     $item = ncmExecute("SELECT * FROM item WHERE itemId = ? and companyId = ? limit 1", [$resourceId, COMPANY_ID]);
    //     insertNotifications(array(
    //         'message' => "El articulo " . $item['itemName'] . " ha vencido",
    //         'title' => 'Vencimiento del Lote',
    //         'type' => $type,
    //         'company' => COMPANY_ID,
    //         'date' => $date,
    //         "push" => array(
    //             "where" => "caja"
    //         )
    //     ));
    // }

    $records[] = $record;
}

if ($insert === false) {
    apiOk([
        'error' => 'No se ha podido registrar',
        'success' => false
    ]);
} else {
    apiOk(
        [
            'sucess' => 'Se ha registrado la tarea correctamente',
            'error' => false,
            "data" => $records
        ]
    );
}
