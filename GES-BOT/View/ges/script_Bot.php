<?php
session_start();
require '../../vendor/autoload.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverWait;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['login']);
    $password = trim($_POST['clave']);

    if (!empty($username) && !empty($password)) {
        $host = 'http://localhost:9515';

        $options = new ChromeOptions();
        $options->addArguments([
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1200,720'
        ]);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        $driver = RemoteWebDriver::create($host, $capabilities);

        try {
            $driver->get('http://appintprd01.etb.com.co/2015/portal/Gestor/index.php#no-back-button');
            $driver->executeScript('window.localStorage.clear();');
            $driver->executeScript('window.sessionStorage.clear();');

            $driver->wait(10)->until(
                WebDriverExpectedCondition::elementToBeClickable(
                    WebDriverBy::xpath("//a[@href='AseguramientoFijos_v2/index.php']")
                )
            )->click();

            $driver->wait(10)->until(
                WebDriverExpectedCondition::urlContains('AseguramientoFijos_v2/index.php')
            );

            $driver->findElement(WebDriverBy::name('login'))->sendKeys($username);
            $driver->findElement(WebDriverBy::name('clave'))->sendKeys($password);
            $driver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

            $_SESSION['loggedin'] = true;
            ini_set('memory_limit', '512M');

            $archivo = './process.csv';
            $gestor = fopen($archivo, 'r');
            if (!$gestor) die("No se pudo abrir el archivo 'process.csv'.\n");

            $filePath = './Gestiones_Realizadas.csv';
            $fileExists2 = file_exists($filePath);
            $file = fopen($filePath, 'a');
            if (!$file) die("No se pudo abrir o crear el archivo CSV.\n");

            if (!$fileExists2) {
                fputcsv($file, [
                    'Canal','Sistema','UEN','Tecnologia','Tipo_de_Documento','Numero_de_Documento','Cliente','Contacto','Linea_del_Servicio','Pedido_Orden','Cuenta_de_Facturacion','Ciudad'
                ]);
            }

            $lineasProcesadas = [];
            $esProximoRegistro = false; // Variable para controlar el primer registro

            while (($datos = fgetcsv($gestor)) !== false) {
                if (empty($datos) || strcasecmp(trim($datos[0]), 'Canal') === 0) continue;

                $reintentos = 0;
                $maxReintentos = 2; // Reducido de 3 a 2
                $procesamientoExitoso = false;

                while (!$procesamientoExitoso && $reintentos < $maxReintentos) {
                    try {
                        $reintentos++;
                        error_log("Procesando registro (intento $reintentos/$maxReintentos): " . implode(',', $datos) . "\n", 3, 'debug_bot.log');

                        // SOLUCIÓN PRINCIPAL: Esperar a que la página esté completamente cargada
                        if (!$esProximoRegistro) {
                            sleep(2); // Reducido de 3 a 2
                            $esProximoRegistro = true;
                        }

                        // Verificar que estemos en la página correcta
                        $driver->wait(12)->until( // Reducido de 20 a 12
                            WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//button[text()='Siguiente']"))
                        );

                        // Hacer scroll hacia arriba
                        $driver->executeScript("window.scrollTo(0, 0);");
                        sleep(1); // Reducido de 2 a 1

                        // Hacer clic en "Siguiente" 
                        $botonSiguiente = $driver->wait(10)->until( // Reducido de 15 a 10
                            WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//button[text()='Siguiente']"))
                        );
                        
                        try {
                            $botonSiguiente->click();
                        } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                            $driver->executeScript("arguments[0].click();", [$botonSiguiente]);
                        }

                        // Esperar a que los dropdowns estén cargados
                        sleep(2); // Reducido de 3 a 2

                        // Definir dropdowns
                        $dropdowns = [
                            ['data_id' => 'var_canal_front', 'index' => 0, 'aria_owns' => 'bs-select-1', 'name' => 'Canal'],
                            ['data_id' => 'inputState', 'index' => 1, 'aria_owns' => 'bs-select-2', 'name' => 'Sistema'],
                            ['data_id' => 'inputState', 'index' => 2, 'aria_owns' => 'bs-select-3', 'name' => 'UEN'],
                            ['data_id' => 'inputState', 'index' => 3, 'aria_owns' => 'bs-select-4', 'name' => 'Tecnologia'],
                            ['data_id' => 'inputState', 'index' => 4, 'aria_owns' => 'bs-select-5', 'name' => 'Tipo_Documento_Campo4'],
                            ['data_id' => 'TipoDocumento2', 'index' => 11, 'aria_owns' => 'bs-select-6', 'name' => 'Ciudad'],
                        ];

                        foreach ($dropdowns as $dropdown) {
                            if (!isset($datos[$dropdown['index']]) || empty(trim($datos[$dropdown['index']]))) {
                                error_log("Valor vacío para {$dropdown['name']} en índice {$dropdown['index']}\n", 3, 'debug_bot.log');
                                continue;
                            }
                            
                            $valor = trim($datos[$dropdown['index']]);
                            $encontrado = false;
                            
                            error_log("Procesando {$dropdown['name']}: '$valor'\n", 3, 'debug_bot.log');

                            try {
                                // Esperar a que el dropdown esté listo
                                $dropdownBoton = (new WebDriverWait($driver, 12))->until( // Reducido de 20 a 12
                                    WebDriverExpectedCondition::elementToBeClickable(
                                        WebDriverBy::cssSelector("button" . (isset($dropdown['data_id']) ? "[data-id='{$dropdown['data_id']}']" : "") . "[aria-owns='{$dropdown['aria_owns']}']")
                                    )
                                );

                                // Hacer scroll al elemento
                                $driver->executeScript("arguments[0].scrollIntoView({behavior: 'smooth', block: 'center'});", [$dropdownBoton]);
                                sleep(1); // Reducido de 2 a 1

                                // Verificar si está abierto
                                $isOpen = $dropdownBoton->getAttribute('aria-expanded') === 'true';
                                if (!$isOpen) {
                                    try {
                                        $dropdownBoton->click();
                                    } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                                        $driver->executeScript("arguments[0].click();", [$dropdownBoton]);
                                    }
                                    sleep(1); // Reducido de 2 a 1
                                }

                                // Esperar las opciones
                                $opciones = (new WebDriverWait($driver, 12))->until( // Reducido de 20 a 12
                                    WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                                        WebDriverBy::cssSelector("#{$dropdown['aria_owns']} ul li")
                                    )
                                );

                                usleep(300000); // Reducido de 1 segundo a 0.3

                                foreach ($opciones as $opcion) {
                                    try {
                                        $span = $opcion->findElement(WebDriverBy::cssSelector('span.text'));
                                        $textoOpcion = trim($span->getText());
                                        
                                        if (strcasecmp($textoOpcion, $valor) === 0) {
                                            $driver->executeScript("arguments[0].scrollIntoView({behavior: 'smooth', block: 'center'});", [$opcion]);
                                            usleep(200000); // Reducido
                                            
                                            try {
                                                $opcion->click();
                                            } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                                                $driver->executeScript("arguments[0].click();", [$opcion]);
                                            }
                                            
                                            $encontrado = true;
                                            error_log("✓ Seleccionado {$dropdown['name']}: '$valor'\n", 3, 'debug_bot.log');
                                            break;
                                        }
                                    } catch (Exception $e) {
                                        continue;
                                    }
                                }
                                
                                if (!$encontrado) {
                                    error_log("✗ Valor NO encontrado en {$dropdown['name']}: '$valor'\n", 3, 'errores_bot.log');
                                    // Intentar cerrar el dropdown
                                    try {
                                        $driver->executeScript("arguments[0].click();", [$dropdownBoton]);
                                    } catch (Exception $e) {
                                        // Ignorar errores al cerrar
                                    }
                                }

                            } catch (Exception $e) {
                                error_log("Error en dropdown {$dropdown['name']}: " . $e->getMessage() . "\n", 3, 'debug_bot.log');
                            }

                            usleep(200000); // Pausa reducida entre dropdowns
                        }

                        // Campos de texto
                        $camposTexto = [
                            ['id' => 'NumeroDocumento', 'index' => 5],
                            ['id' => 'Cliente', 'index' => 6],
                            ['id' => 'Contacto', 'index' => 7],
                            ['id' => 'LineaServicio', 'index' => 8],
                            ['id' => 'PedidoOrden', 'index' => 9],
                            ['id' => 'CuentaFacturacion', 'index' => 10],
                        ];

                        foreach ($camposTexto as $campo) {
                            if (!isset($datos[$campo['index']])) continue;
                            
                            try {
                                $input = (new WebDriverWait($driver, 10))->until( // Reducido de 15 a 10
                                    WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id($campo['id']))
                                );
                                $input->clear();
                                $input->sendKeys(trim($datos[$campo['index']]));
                            } catch (Exception $e) {
                                error_log("Error llenando campo {$campo['id']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                            }
                        }

                        sleep(3); // Reducido de 5 a 3

                        // Guardar
                        $botonGuardar = $driver->wait(12)->until( // Reducido de 20 a 12
                            WebDriverExpectedCondition::elementToBeClickable(
                                WebDriverBy::xpath("//button[normalize-space()='Guardar']")
                            )
                        );
                        
                        try {
                            $botonGuardar->click();
                        } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                            $driver->executeScript("arguments[0].click();", [$botonGuardar]);
                        }

                        sleep(2); // Reducido de 3 a 2

                        // Navegar a DATOS CLIENTE
                        $spanDatosCliente = (new WebDriverWait($driver, 12))->until(
                            WebDriverExpectedCondition::elementToBeClickable(
                                WebDriverBy::xpath("//span[contains(@class, 'nav-text') and normalize-space(text())='DATOS CLIENTE']")
                            )
                        );

                        // Scroll con ajuste para evitar superposición con el logo
                        $driver->executeScript("arguments[0].scrollIntoView(true); window.scrollBy(0, -100);", [$spanDatosCliente]);
                        usleep(300000);

                        try {
                            $spanDatosCliente->click();
                        } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                            $driver->executeScript("arguments[0].click();", [$spanDatosCliente]);
                        }

                        // Si llegamos aquí, fue exitoso
                        $procesamientoExitoso = true;
                        error_log("✓ Registro procesado exitosamente: " . implode(',', $datos) . "\n", 3, 'debug_bot.log');

                    } catch (TimeoutException $e) {
                        error_log("TimeoutException en registro (intento $reintentos): " . implode(',', $datos) . " - Error: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                        
                        if ($reintentos >= $maxReintentos) {
                            error_log("✗ Registro falló después de $maxReintentos intentos por timeout\n", 3, 'errores_bot.log');
                            break; // Salir del while de reintentos para continuar con siguiente registro
                        }
                        
                        sleep(2); // Pausa antes del siguiente intento
                        
                    } catch (Exception $e) {
                        error_log("Error general en registro (intento $reintentos): " . implode(',', $datos) . " - Error: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                        
                        if ($reintentos >= $maxReintentos) {
                            error_log("✗ Registro falló después de $maxReintentos intentos por error general\n", 3, 'errores_bot.log');
                            break; // Salir del while de reintentos para continuar con siguiente registro
                        }
                        
                        sleep(2); // Pausa antes del siguiente intento
                    }
                }

                // CORRECCIÓN CRÍTICA: Solo procesar archivos si fue exitoso, pero NO salir del script
                if ($procesamientoExitoso) {
                    try {
                        // Añadir a 'Gestiones_Realizadas.csv'
                        fputcsv($file, $datos);
                        $lineasProcesadas[] = $datos;
                        error_log("✓ Registro guardado en Gestiones_Realizadas.csv\n", 3, 'debug_bot.log');
                        
                    } catch (Exception $e) {
                        error_log("Error guardando en archivo: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                    }
                }
                
                // IMPORTANTE: NO hacer exit aquí, continuar con el siguiente registro
                // Solo hacer una pequeña pausa antes del siguiente registro
                sleep(1);
            }

            // DESPUÉS del while, cuando todos los registros estén procesados:
            try {
                // Actualizar el archivo process.csv eliminando las líneas procesadas
                if (!empty($lineasProcesadas)) {
                    fclose($gestor);
                    $gestor = fopen($archivo, 'r');
                    $todasLasLineas = [];
                    while (($linea = fgetcsv($gestor)) !== false) {
                        if (strcasecmp(trim($linea[0]), 'Canal') === 0 || !in_array($linea, $lineasProcesadas)) {
                            $todasLasLineas[] = $linea;
                        }
                    }
                    fclose($gestor);
                    
                    file_put_contents($archivo, '');
                    $gestor = fopen($archivo, 'w');
                    foreach ($todasLasLineas as $linea) {
                        fputcsv($gestor, $linea);
                    }
                    fclose($gestor);
                    
                    error_log("✓ Archivo process.csv actualizado, eliminadas " . count($lineasProcesadas) . " líneas procesadas\n", 3, 'debug_bot.log');
                }
                
                // Cerrar recursos
                if (isset($driver)) {
                    $driver->quit();
                }
                if (isset($file) && is_resource($file)) {
                    fclose($file);
                }
                
                header("Location: again.php");
                
            } 

            catch (Exception $e) {
                error_log("Error final procesando archivos: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
            }

            } catch (TimeoutException | NoSuchElementException $e) {
                    echo "<script>alert('Error: {$e->getMessage()}');</script>";
                    $driver->quit();
                    header("Location: View/ges/script_Bot.php");
                }
            }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="shortcut icon" href="../images/icons/window.png" />
    <title>Ges-Bot</title>
</head>
<body>

    <div class="container">
        
        <div class="inic" id="star3">

            <div class="help">
                <a href="../../Model/Documents/manReto-Suma.pdf" target="_blank"><span>?</span></a>
            </div>

            <div class="tittle">
                <img src="../images/icons/robot.png" alt="">
                <h1>GES-BOT</h1>
            </div>

            <?php

                // Generar un token CSRF si no existe
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                // Obtener el token para usar en el formulario
                $csrfToken = $_SESSION['csrf_token'];
            ?>

            <form action="" method="POST">
                <div class="data">
                    <!-- Campo oculto para el token CSRF -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <div>
                        <img src="../images/icons/email.png" alt="Icono de correo electrónico">
                        <input type="text" id="validationCustomUsername" name="login" placeholder="Usuario_Gestor" required>
                    </div>

                    <div>
                        <img src="../images/icons/pass.png" alt="Icono de contraseña">
                        <input type="password" id="dz-password" name="clave" placeholder="Contraseña_Gestor" required>
                    </div>

                    <div class="buttons-act">
                        <button class="act" type="submit">Iniciar</button>
                    </div>
                </div>
            </form>

        </div>

        <div class="backdrop"></div>

    </div>

<script src="../bootstrap/jquery.js"></script>
<script src="../bootstrap/bootstrap.bundle.min.js"></script>
<script src="../../Controller/buttons_Action.js"></script>
</body>
</html>