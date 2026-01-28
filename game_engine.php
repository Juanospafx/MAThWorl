<?php
session_start();

// Configuración
header('Content-Type: application/json');
$jsonFile = 'data.json';

// Verificar si existe el archivo de datos
if (!file_exists($jsonFile)) {
    echo json_encode(['status' => 'error', 'message' => 'Error crítico: No se encuentra data.json']);
    exit;
}

$jsonData = file_get_contents($jsonFile);
$gameData = json_decode($jsonData, true);

if (!$gameData) {
    echo json_encode(['status' => 'error', 'message' => 'Error crítico: JSON corrupto']);
    exit;
}

$response = ['status' => 'error', 'message' => 'Comando no reconocido'];

// --- ACCIÓN: INICIAR RUTA ---
if (isset($_POST['action']) && $_POST['action'] === 'start_path') {
    $path = $_POST['path']; // 'python' o 'r_lang'
    
    // Verificar Cooldown (Enfriamiento)
    if (isset($_COOKIE['synapse_cooldown'])) {
        $remaining = $_COOKIE['synapse_cooldown'] - time();
        if ($remaining > 0) {
            echo json_encode(['status' => 'error', 'message' => ">> SISTEMA SOBRECALENTADO.\n>> REINICIO DISPONIBLE EN: " . $remaining . " SEGUNDOS."]);
            exit;
        }
    }
    
    if (isset($gameData[$path])) {
        $_SESSION['current_path'] = $path;
        $_SESSION['current_level_key'] = 'beginner'; // Siempre inicia en beginner
        $_SESSION['current_q_index'] = 0;
        $_SESSION['score'] = 0;
        $_SESSION['lives'] = 3;
        $_SESSION['streak'] = 0;
        $_SESSION['next_super_hint_at'] = 3; // Umbral inicial para super pista
        
        $levelKey = $_SESSION['current_level_key'];
        $levelData = $gameData[$path]['levels'][$levelKey];
        
        // Verificar si hay desafíos
        if (empty($levelData['challenges'])) {
             $response = [
                'status' => 'success',
                'message' => ">> ALERTA: No hay datos en este sector aún.\n",
                'next_question' => null
            ];
        } else {
            $response = [
                'status' => 'success',
                'type' => 'story',
                'message' => ">> CONEXIÓN ESTABLECIDA: PROTOCOLO " . strtoupper($path) . ".\n>> " . $levelData['story'] . "\n",
                'next_question' => $levelData['challenges'][0]['question'],
                'lesson' => $levelData['challenges'][0]['lesson'] ?? null,
                'lives' => $_SESSION['lives'],
                'streak' => $_SESSION['streak'],
                'super_hint_available' => false
            ];
        }
    } else {
        $response = ['status' => 'error', 'message' => 'Ruta no válida.'];
    }
}

// --- ACCIÓN: VERIFICAR RESPUESTA ---
if (isset($_POST['action']) && $_POST['action'] === 'check_answer') {
    $userAnswer = $_POST['answer'];
    
    if (!isset($_SESSION['current_path'])) {
        echo json_encode(['status' => 'error', 'message' => 'Sesión expirada. Reinicia el sistema.']);
        exit;
    }
    
    if ($_SESSION['lives'] <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'SISTEMA CRÍTICO: Has muerto. Reinicia el nivel.']);
        exit;
    }

    $path = $_SESSION['current_path'];
    $levelKey = $_SESSION['current_level_key'];
    $qIndex = $_SESSION['current_q_index'];
    
    $challenges = $gameData[$path]['levels'][$levelKey]['challenges'];
    
    if (!isset($challenges[$qIndex])) {
        echo json_encode(['status' => 'success', 'message' => 'Nivel completado.']);
        exit;
    }

    $currentChallenge = $challenges[$qIndex];
    
    // Lógica de comparación flexible
    // 1. Convertir a minúsculas
    // 2. Eliminar TODOS los espacios (para evitar errores por espacios extra)
    // 3. Eliminar comillas simples y dobles para normalizar strings
    
    $cleanUser = str_replace([' ', '"', "'"], '', strtolower($userAnswer));
    $cleanCorrect = str_replace([' ', '"', "'"], '', strtolower($currentChallenge['answer']));

    if ($cleanUser === $cleanCorrect) {
        $_SESSION['score'] += 10;
        $_SESSION['current_q_index']++;
        $_SESSION['streak']++;
        $qIndex++; // Avanzar índice local para verificar si quedan más
        
        $msg = ">> CÓDIGO ACEPTADO. Procesando...\n";
        
        if ($qIndex < count($challenges)) {
            $nextQ = $challenges[$qIndex];
            $response = [
                'status' => 'success',
                'correct' => true,
                'message' => $msg,
                'next_question' => $nextQ['question'],
                'lesson' => $nextQ['lesson'] ?? null,
                'score' => $_SESSION['score'],
                'lives' => $_SESSION['lives'],
                'streak' => $_SESSION['streak'],
                'super_hint_available' => ($_SESSION['streak'] >= $_SESSION['next_super_hint_at'])
            ];
        } else {
            $response = [
                'status' => 'success',
                'correct' => true,
                'message' => $msg . ">> NIVEL " . strtoupper($levelKey) . " COMPLETADO.\n>> Accediendo a siguiente sector de memoria... (Fin de la demo)\n",
                'finished_level' => true,
                'score' => $_SESSION['score']
            ];
        }
    } else {
        $response = [
            'status' => 'success',
            'correct' => false,
            'message' => ">> ERROR DE SINTAXIS.\n>> Pista: " . $currentChallenge['hint'] . "\n"
        ];
    }
}

// --- ACCIÓN: GUARDAR PARTIDA ---
if (isset($_POST['action']) && $_POST['action'] === 'save_game') {
    if (!isset($_SESSION['current_path'])) {
        echo json_encode(['status' => 'error', 'message' => 'No hay datos para guardar.']);
        exit;
    }
    
    $saveData = [
        'path' => $_SESSION['current_path'],
        'level' => $_SESSION['current_level_key'],
        'q_index' => $_SESSION['current_q_index'],
        'score' => $_SESSION['score'],
        'lives' => $_SESSION['lives'],
        'streak' => $_SESSION['streak'],
        'super_hint_at' => $_SESSION['next_super_hint_at']
    ];
    
    // Guardar en cookie por 30 días (base64 para evitar problemas de caracteres)
    setcookie('synapse_save', base64_encode(json_encode($saveData)), time() + (86400 * 30), "/");
    
    echo json_encode(['status' => 'success', 'message' => ">> PROGRESO GUARDADO EN MEMORIA NO VOLÁTIL."]);
    exit;
}

// --- ACCIÓN: CARGAR PARTIDA ---
if (isset($_POST['action']) && $_POST['action'] === 'load_game') {
    if (!isset($_COOKIE['synapse_save'])) {
        echo json_encode(['status' => 'error', 'message' => ">> NO SE ENCONTRARON ARCHIVOS DE GUARDADO."]);
        exit;
    }
    
    // Verificar Cooldown también al cargar (para evitar trampas inmediatas)
    if (isset($_COOKIE['synapse_cooldown'])) {
        $remaining = $_COOKIE['synapse_cooldown'] - time();
        if ($remaining > 0) {
            echo json_encode(['status' => 'error', 'message' => ">> SISTEMA SOBRECALENTADO.\n>> ESPERE " . $remaining . " SEGUNDOS."]);
            exit;
        }
    }

    $data = json_decode(base64_decode($_COOKIE['synapse_save']), true);
    
    $_SESSION['current_path'] = $data['path'];
    $_SESSION['current_level_key'] = $data['level'];
    $_SESSION['current_q_index'] = $data['q_index'];
    $_SESSION['score'] = $data['score'];
    $_SESSION['lives'] = $data['lives'];
    $_SESSION['streak'] = $data['streak'];
    $_SESSION['next_super_hint_at'] = $data['super_hint_at'];
    
    // Recuperar pregunta actual
    $qData = $gameData[$data['path']]['levels'][$data['level']]['challenges'][$data['q_index']];
    
    echo json_encode([
        'status' => 'success',
        'message' => ">> MEMORIA RESTAURADA. VOLVIENDO AL PUNTO DE CONTROL.",
        'next_question' => $qData['question'],
        'lesson' => $qData['lesson'] ?? null,
        'lives' => $_SESSION['lives'],
        'streak' => $_SESSION['streak'],
        'score' => $_SESSION['score'],
        'super_hint_available' => ($_SESSION['streak'] >= $_SESSION['next_super_hint_at'])
    ]);
    exit;
}

// --- ACCIÓN: SALIR DEL JUEGO ---
if (isset($_POST['action']) && $_POST['action'] === 'exit_game') {
    unset($_SESSION['current_path']);
    unset($_SESSION['current_level_key']);
    unset($_SESSION['current_q_index']);
    unset($_SESSION['score']);
    unset($_SESSION['lives']);
    unset($_SESSION['streak']);
    echo json_encode(['status' => 'success', 'message' => 'Sesión cerrada.']);
    exit;
}

echo json_encode($response);
?>
