<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Synapse: Data Science Training</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="crt-overlay"></div>
    
    <div class="container">
        <header>
            <h1>SYNAPSE v1.0</h1>
            <div class="stats">
                <div id="avatar-display" class="avatar">[ O_O ]</div>
                <span id="lives-display" style="color: #f00; margin-right: 15px;">♥♥♥</span>
                <span id="score-display">SCORE: 0000</span> | 
                <span id="streak-display" style="color: #ff0; margin-left: 10px;">COMBO: 0</span>
                <button id="skip-btn" onclick="skipChallenge()" style="display:none; margin-left: 15px; background: #f0f; color: #fff; border: none; cursor: pointer; font-weight: bold;">SKIP (0)</button>
                <button id="super-hint-btn" onclick="useSuperHint()" style="display:none; margin-left: 15px; background: #ff0; color: #000; border: none; cursor: pointer; font-weight: bold;">★ SUPER PISTA ★</button>
                <button id="save-btn" onclick="saveGame()" style="display:none; margin-left: 15px; background: transparent; border: 1px solid #0ff; color: #0ff; font-family: inherit; cursor: pointer;">[S] GUARDAR</button>
                <button id="exit-btn" onclick="exitGame()" style="display:none; margin-left: 15px; background: transparent; border: 1px solid #f00; color: #f00; font-family: inherit; cursor: pointer;">[X] MENU PRINCIPAL</button>
            </div>
        </header>

        <div id="game-output" class="terminal-window">
            <p class="system-msg">>> INICIALIZANDO SISTEMA...</p>
            <p class="system-msg">>> CARGANDO MÓDULOS DE APRENDIZAJE...</p>
            <p class="system-msg">>> SELECCIONE RUTA:</p>
            
            <div class="menu-options" id="main-menu">
                <button onclick="startGame('python')">[1] PYTHON (DATA SCIENCE)</button>
                <button onclick="startGame('r_lang')">[2] R (ESTADÍSTICA)</button>
                <button onclick="loadGame()" style="border-color: #0ff; color: #0ff;">[3] CARGAR PARTIDA</button>
            </div>
        </div>

        <div class="input-area">
            <span class="prompt">user@synapse:~$</span>
            <input type="text" id="user-input" placeholder="Esperando comando..." autocomplete="off" disabled>
        </div>
    </div>

    <script>
        const outputDiv = document.getElementById('game-output');
        const inputField = document.getElementById('user-input');
        const scoreDisplay = document.getElementById('score-display');
        const livesDisplay = document.getElementById('lives-display');
        const streakDisplay = document.getElementById('streak-display');
        const avatarDisplay = document.getElementById('avatar-display');
        const mainMenu = document.getElementById('main-menu');
        const exitBtn = document.getElementById('exit-btn');
        const superHintBtn = document.getElementById('super-hint-btn');
        const saveBtn = document.getElementById('save-btn');
        const skipBtn = document.getElementById('skip-btn');
        
        // Función para escribir en la terminal
        function appendLog(text, type = 'normal') {
            const p = document.createElement('p');
            p.innerText = text; // Usar innerText para seguridad básica
            
            if (type === 'system') p.className = 'system-msg';
            if (type === 'error') p.className = 'error-msg';
            if (type === 'success') p.className = 'success-msg';
            if (type === 'user') p.className = 'user-msg';
            if (type === 'lesson') p.className = 'lesson-msg';
            
            outputDiv.appendChild(p);
            // Auto-scroll al final
            outputDiv.scrollTop = outputDiv.scrollHeight;
        }

        function updateStats(lives, streak) {
            let hearts = "";
            let livesInt = parseInt(lives); // Asegurar que es número
            // Mostrar siempre 3 corazones, llenos o vacíos para feedback visual
            for(let i=0; i<3; i++) {
                if(i < livesInt) hearts += "♥ ";
                else hearts += "♡ ";
            }
            livesDisplay.innerText = hearts;
            streakDisplay.innerText = "COMBO: " + streak;
        }

        function setAvatar(state) {
            if(state === 'normal') avatarDisplay.innerText = "[ O_O ]";
            if(state === 'happy') avatarDisplay.innerText = "[ ★_★ ]";
            if(state === 'dead') avatarDisplay.innerText = "[ X_X ]";
            if(state === 'hurt') avatarDisplay.innerText = "[ >_< ]";
        }

        // Iniciar el juego
        function startGame(path) {
            mainMenu.style.display = 'none';
            inputField.disabled = false;
            inputField.focus();
            inputField.placeholder = "Escribe tu código aquí...";
            exitBtn.style.display = 'inline-block';
            saveBtn.style.display = 'inline-block';
            skipBtn.style.display = 'inline-block';
            setAvatar('normal');

            const formData = new FormData();
            formData.append('action', 'start_path');
            formData.append('path', path);

            fetch('game_engine.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'error') {
                    appendLog(data.message, 'error');
                    return;
                }
                appendLog(data.message, 'system');
                updateStats(data.lives, data.streak);
                updateSkipBtn(data.skips);
                if(data.next_question) {
                    if(data.lesson) {
                        setTimeout(() => appendLog(">> LECCIÓN: " + data.lesson, 'lesson'), 300);
                    }
                    setTimeout(() => {
                        appendLog(">> DESAFÍO: " + data.next_question, 'normal');
                    }, 800);
                }
            })
            .catch(err => appendLog(">> ERROR DE CONEXIÓN CON EL SERVIDOR", 'error'));
        }

        function useSuperHint() {
            const formData = new FormData();
            formData.append('action', 'get_super_hint');
            fetch('game_engine.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                appendLog(data.message, 'system');
                superHintBtn.style.display = 'none';
                inputField.focus();
            });
        }

        function skipChallenge() {
            const formData = new FormData();
            formData.append('action', 'skip_challenge');
            fetch('game_engine.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if(data.skipped) {
                    appendLog(data.message, 'success');
                    updateSkipBtn(data.skips);
                    // Recargar estado del juego (truco rápido: recargar partida actual)
                    // En realidad deberíamos pedir la siguiente pregunta, pero loadGame funciona bien aquí
                    loadGame(); 
                } else {
                    appendLog(data.message, 'error');
                }
            });
        }

        function updateSkipBtn(count) {
            skipBtn.innerText = "SKIP (" + (count || 0) + ")";
            skipBtn.style.display = 'inline-block';
        }

        function saveGame() {
            const formData = new FormData();
            formData.append('action', 'save_game');
            fetch('game_engine.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                appendLog(data.message, 'success');
            });
        }

        function loadGame() {
            const formData = new FormData();
            formData.append('action', 'load_game');
            fetch('game_engine.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if(data.status === 'error') {
                    appendLog(data.message, 'error');
                    return;
                }
                // Iniciar interfaz de juego
                mainMenu.style.display = 'none';
                inputField.disabled = false;
                inputField.focus();
                exitBtn.style.display = 'inline-block';
                saveBtn.style.display = 'inline-block';
                
                appendLog(data.message, 'system');
                updateStats(data.lives, data.streak);
                updateSkipBtn(data.skips);
                if(data.next_question) {
                    if(data.lesson) setTimeout(() => appendLog(">> LECCIÓN: " + data.lesson, 'lesson'), 300);
                    setTimeout(() => appendLog(">> DESAFÍO: " + data.next_question, 'normal'), 800);
                }
                if(data.super_hint_available) superHintBtn.style.display = 'inline-block';
            });
        }

        // Salir al menú
        function exitGame() {
            const formData = new FormData();
            formData.append('action', 'exit_game');
            
            fetch('game_engine.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                location.reload(); // Recargar para limpiar la terminal y volver al inicio
            });
        }

        // Manejar Enter en el input
        inputField.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                const answer = inputField.value;
                if(answer.trim() === "") return;

                // Mostrar lo que escribió el usuario
                appendLog("user@synapse:~$ " + answer, 'user');
                inputField.value = '';

                const formData = new FormData();
                formData.append('action', 'check_answer');
                formData.append('answer', answer);

                fetch('game_engine.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'error') {
                        appendLog(data.message, 'error');
                    } else {
                        if (data.correct) {
                            appendLog(data.message, 'success');
                            setAvatar('happy');
                            setTimeout(() => setAvatar('normal'), 2000);
                            
                            if(data.score) {
                                scoreDisplay.innerText = "SCORE: " + data.score.toString().padStart(4, '0');
                            }
                            updateStats(data.lives, data.streak);
                            updateSkipBtn(data.skips);
                            
                            if(data.super_hint_available) {
                                superHintBtn.style.display = 'inline-block';
                            } else {
                                superHintBtn.style.display = 'none';
                            }

                            if(data.next_question) {
                                if(data.lesson) {
                                    setTimeout(() => appendLog(">> LECCIÓN: " + data.lesson, 'lesson'), 300);
                                }
                                setTimeout(() => appendLog(">> DESAFÍO: " + data.next_question), 800);
                            }
                        } else if (data.game_over) {
                            appendLog(data.message, 'error');
                            setAvatar('dead');
                            updateStats(0, 0);
                            inputField.disabled = true;
                            saveBtn.style.display = 'none';
                            skipBtn.style.display = 'none';
                            // Cerrar juego automáticamente tras morir (4 segundos de espera para leer)
                            setTimeout(() => {
                                exitGame();
                            }, 4000);
                        } else {
                            appendLog(data.message, 'error');
                            setAvatar('hurt');
                            setTimeout(() => setAvatar('normal'), 2000);
                            updateStats(data.lives, 0);
                            superHintBtn.style.display = 'none';
                        }
                    }
                })
                .catch(err => appendLog(">> ERROR CRÍTICO DE RED", 'error'));
            }
        });
    </script>
</body>
</html>
