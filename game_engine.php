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
        $_SESSION['score'] = 0;
        $_SESSION['lives'] = 3;
        $_SESSION['streak'] = 0;
        $_SESSION['next_super_hint_at'] = 3; // Umbral inicial para super pista
        $_SESSION['inventory'] = ['skips' => 0]; // Inventario Roguelike
        
        // --- GENERACIÓN PROCEDIMENTAL DE LA MAZMORRA (NIVEL) ---
        generateLevelQueue($path, 'beginner', $gameData);
        $_SESSION['current_q_index'] = 0;
        
        $currentQ = $_SESSION['run_queue'][0];
        $levelTitle = $gameData[$path]['levels']['beginner']['title'];
        $story = $gameData[$path]['levels']['beginner']['story'];
        
        // Verificar si hay desafíos
        if (empty($_SESSION['run_queue'])) {
             $response = [
                'status' => 'success',
                'message' => ">> ALERTA: No hay datos en este sector aún.\n",
                'next_question' => null
            ];
        } else {
            $response = [
                'status' => 'success',
                'type' => 'story',
                'message' => ">> INICIANDO RUN ROGUELIKE: " . strtoupper($path) . ".\n>> SECTOR GENERADO ALEATORIAMENTE.\n>> " . $story . "\n",
                'next_question' => $currentQ['question'],
                'lesson' => $currentQ['lesson'] ?? null,
                'lives' => $_SESSION['lives'],
                'streak' => $_SESSION['streak'],
                'skips' => $_SESSION['inventory']['skips'],
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
    $queue = $_SESSION['run_queue'];
    
    if (!isset($queue[$qIndex])) {
        echo json_encode(['status' => 'success', 'message' => 'Nivel completado.']);
        exit;
    }

    $currentChallenge = $queue[$qIndex];
    
    // Lógica de comparación flexible
    // 1. Convertir a minúsculas
    // 2. Reemplazar comillas dobles por simples (para permitir ambas)
    // 3. Eliminar espacios (para permitir x = 1 vs x=1)
    // NOTA: Ya no eliminamos las comillas, solo las normalizamos para detectar errores de sintaxis (comillas sin cerrar).
    
    $cleanUser = str_replace([' ', '"'], ['', "'"], strtolower($userAnswer));
    $cleanCorrect = str_replace([' ', '"'], ['', "'"], strtolower($currentChallenge['answer']));

    if ($cleanUser === $cleanCorrect) {
        $_SESSION['score'] += 10;
        $_SESSION['current_q_index']++;
        $_SESSION['streak']++;
        
        // Loot de Boss
        $lootMsg = "";
        if (isset($currentChallenge['type']) && $currentChallenge['type'] === 'boss') {
            $_SESSION['inventory']['skips']++;
            $lootMsg = "\n>> ¡JEFE DERROTADO! RECOMPENSA: +1 RAM OVERRIDE (SKIP).";
        }
        
        $qIndex++; // Avanzar índice local para verificar si quedan más
        
        $msg = ">> CÓDIGO ACEPTADO." . $lootMsg . "\n";
        
        if ($qIndex < count($queue)) {
            $nextQ = $queue[$qIndex];
            $bossWarning = (isset($nextQ['type']) && $nextQ['type'] === 'boss') ? "\n>> ¡ADVERTENCIA! SEÑAL DE JEFE DETECTADA." : "";
            $response = [
                'status' => 'success',
                'correct' => true,
                'message' => $msg . $bossWarning,
                'next_question' => $nextQ['question'],
                'lesson' => $nextQ['lesson'] ?? null,
                'score' => $_SESSION['score'],
                'lives' => $_SESSION['lives'],
                'streak' => $_SESSION['streak'],
                'skips' => $_SESSION['inventory']['skips'],
                'super_hint_available' => ($_SESSION['streak'] >= $_SESSION['next_super_hint_at'])
            ];
        } else {
            // Lógica de transición de nivel automática
            $levelOrder = ['beginner', 'intermediate', 'master'];
            $currentPos = array_search($levelKey, $levelOrder);
            $nextLevelKey = ($currentPos !== false && isset($levelOrder[$currentPos + 1])) ? $levelOrder[$currentPos + 1] : null;

            if ($nextLevelKey && isset($gameData[$path]['levels'][$nextLevelKey])) {
                // Avanzar al siguiente nivel en la sesión
                $_SESSION['current_level_key'] = $nextLevelKey;
                $_SESSION['current_q_index'] = 0;
                
                // Generar nueva mazmorra para el siguiente nivel
                generateLevelQueue($path, $nextLevelKey, $gameData);
                
                // Verificar si el siguiente nivel tiene desafíos cargados
                if (!empty($_SESSION['run_queue'])) {
                    $nextQ = $_SESSION['run_queue'][0];
                    $nextLevelData = $gameData[$path]['levels'][$nextLevelKey];
                    
                    $response = [
                        'status' => 'success',
                        'correct' => true,
                        'message' => $msg . ">> NIVEL " . strtoupper($levelKey) . " COMPLETADO.\n>> ACCEDIENDO AL SIGUIENTE SECTOR: " . strtoupper($nextLevelKey) . "...\n\n>> " . $nextLevelData['story'] . "\n",
                        'next_question' => $nextQ['question'],
                        'lesson' => $nextQ['lesson'] ?? null,
                        'score' => $_SESSION['score'],
                        'lives' => $_SESSION['lives'],
                        'streak' => $_SESSION['streak'],
                        'skips' => $_SESSION['inventory']['skips'],
                        'super_hint_available' => ($_SESSION['streak'] >= $_SESSION['next_super_hint_at'])
                    ];
                } else {
                     $response = [
                        'status' => 'success',
                        'correct' => true,
                        'message' => $msg . ">> NIVEL " . strtoupper($levelKey) . " COMPLETADO.\n>> El sector " . strtoupper($nextLevelKey) . " está vacío por ahora.\n",
                        'finished_level' => true,
                        'score' => $_SESSION['score']
                    ];
                }
            } else {
                $response = [
                    'status' => 'success',
                    'correct' => true,
                    'message' => $msg . ">> NIVEL " . strtoupper($levelKey) . " COMPLETADO.\n>> ¡SISTEMA RESTAURADO AL 100%! Misión cumplida.\n",
                    'finished_level' => true,
                    'score' => $_SESSION['score']
                ];
            }
        }
    } else {
        $_SESSION['lives'] = (int)$_SESSION['lives'] - 1;
        $_SESSION['streak'] = 0;
        
        if ($_SESSION['lives'] <= 0) {
            // Lógica de Muerte y Cooldown Incremental
            $deathCount = isset($_COOKIE['synapse_death_count']) ? (int)$_COOKIE['synapse_death_count'] : 0;
            $deathCount++;
            $cooldownMinutes = $deathCount; // 1 min, 2 min, etc.
            $cooldownSeconds = $cooldownMinutes * 60;
            
            // Guardar contador de muertes y establecer bloqueo
            setcookie('synapse_death_count', $deathCount, time() + (86400 * 365), "/");
            setcookie('synapse_cooldown', time() + $cooldownSeconds, time() + $cooldownSeconds, "/");
            
            // ROGUELIKE: MUERTE PERMANENTE
            // Borramos el archivo de guardado para que empiece desde cero
            if (isset($_COOKIE['synapse_save'])) {
                setcookie('synapse_save', '', time() - 3600, "/");
            }

            $response = [
                'status' => 'success',
                'correct' => false,
                'game_over' => true,
                'message' => ">> ERROR FATAL: NÚCLEO INESTABLE.\n>> HAS MUERTO (Fallo #$deathCount).\n>> SISTEMA BLOQUEADO POR $cooldownMinutes MINUTO(S).\n>> PROTOCOLO ROGUELIKE: PROGRESO ELIMINADO. REINICIANDO SISTEMA...",
                'lives' => 0,
                'streak' => 0
            ];
        } else {
            $response = [
                'status' => 'success',
                'correct' => false,
                'message' => ">> ERROR DE SINTAXIS.\n>> Pista: " . $currentChallenge['hint'] . "\n",
                'lives' => $_SESSION['lives'],
                'streak' => 0
            ];
        }
    }
}

// --- ACCIÓN: OBTENER SUPER PISTA ---
if (isset($_POST['action']) && $_POST['action'] === 'get_super_hint') {
    if (!isset($_SESSION['current_path'])) exit;
    
    $streak = $_SESSION['streak'];
    $threshold = $_SESSION['next_super_hint_at'];
    
    if ($streak >= $threshold) {
        $path = $_SESSION['current_path'];
        $levelKey = $_SESSION['current_level_key'];
        $qIndex = $_SESSION['current_q_index'];
        $answer = $_SESSION['run_queue'][$qIndex]['answer'];
        
        // Incrementar el costo de la siguiente pista (+3)
        $_SESSION['next_super_hint_at'] = $streak + 3;
        
        $response = [
            'status' => 'success',
            'message' => ">> SUPER PISTA ACTIVADA: La respuesta es casi: " . $answer,
            'super_hint_available' => false // Ya se usó
        ];
    }
}

// --- ACCIÓN: SALTAR DESAFÍO (SKIP) ---
if (isset($_POST['action']) && $_POST['action'] === 'skip_challenge') {
    if (!isset($_SESSION['current_path'])) exit;
    
    $qIndex = $_SESSION['current_q_index'];
    $currentQ = $_SESSION['run_queue'][$qIndex];
    
    // Verificar si es Boss
    if (isset($currentQ['type']) && $currentQ['type'] === 'boss') {
        echo json_encode(['status' => 'success', 'message' => ">> ERROR: NO SE PUEDE SALTAR UN JEFE DE NIVEL."]);
        exit;
    }
    
    // Verificar inventario
    if ($_SESSION['inventory']['skips'] > 0) {
        $_SESSION['inventory']['skips']--;
        
        // Simular respuesta correcta
        // Llamamos recursivamente a la lógica de check_answer pasando la respuesta correcta
        // Pero como es una petición AJAX separada, simplemente devolvemos un flag especial
        // Para simplificar, avanzamos el índice manualmente aquí
        
        $_SESSION['current_q_index']++;
        // No damos puntos por saltar
        
        $nextQ = $_SESSION['run_queue'][$_SESSION['current_q_index']] ?? null;
        
        echo json_encode(['status' => 'success', 'skipped' => true, 'message' => ">> RAM OVERRIDE ACTIVADO. Desafío omitido.", 'skips' => $_SESSION['inventory']['skips']]);
    } else {
        echo json_encode(['status' => 'success', 'message' => ">> ERROR: NO TIENES MÓDULOS DE RAM OVERRIDE."]);
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
        'queue' => $_SESSION['run_queue'], // Guardar la mazmorra generada
        'score' => $_SESSION['score'],
        'lives' => $_SESSION['lives'],
        'streak' => $_SESSION['streak'],
        'inventory' => $_SESSION['inventory'],
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
    $_SESSION['run_queue'] = $data['queue']; // Restaurar mazmorra
    $_SESSION['score'] = $data['score'];
    $_SESSION['lives'] = $data['lives'];
    $_SESSION['streak'] = $data['streak'];
    $_SESSION['inventory'] = $data['inventory'];
    $_SESSION['next_super_hint_at'] = $data['super_hint_at'];
    
    // Recuperar pregunta actual
    $qData = $_SESSION['run_queue'][$data['q_index']];
    
    echo json_encode([
        'status' => 'success',
        'message' => ">> MEMORIA RESTAURADA. VOLVIENDO AL PUNTO DE CONTROL.",
        'next_question' => $qData['question'],
        'lesson' => $qData['lesson'] ?? null,
        'lives' => $_SESSION['lives'],
        'streak' => $_SESSION['streak'],
        'skips' => $_SESSION['inventory']['skips'],
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

// --- FUNCIÓN AUXILIAR: GENERADOR DE MAZMORRA ---
function generateLevelQueue($path, $levelKey, $gameData) {
    $levelData = $gameData[$path]['levels'][$levelKey];
    $pool = $levelData['challenges'];
    $bosses = $levelData['bosses'] ?? [];
    
    // 1. Barajar el pool de preguntas normales
    shuffle($pool);
    
    // 2. Seleccionar 5 preguntas (o menos si no hay suficientes)
    $selected = array_slice($pool, 0, 5);
    
    // 3. Seleccionar 1 Boss aleatorio
    if (!empty($bosses)) {
        $boss = $bosses[array_rand($bosses)];
        $boss['type'] = 'boss'; // Marcar como jefe
        $selected[] = $boss;
    }
    
    $_SESSION['run_queue'] = $selected;
}

echo json_encode($response);
?>
