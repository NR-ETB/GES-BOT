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

                try {
                    // SOLUCIÓN PRINCIPAL: Esperar a que la página esté completamente cargada
                    if (!$esProximoRegistro) {
                        // Para el primer registro, esperar más tiempo
                        sleep(2);
                        $esProximoRegistro = true;
                    }

                    // Verificar que estemos en la página correcta antes de continuar
                    $driver->wait(10)->until(
                        WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//button[text()='Siguiente']"))
                    );

                    // Hacer scroll hacia arriba para asegurar que todos los elementos sean visibles
                    $driver->executeScript("window.scrollTo(0, 0);");
                    sleep(1);

                    // Hacer clic en "Siguiente"
                    $botonSiguiente = $driver->wait(10)->until(
                        WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//button[text()='Siguiente']"))
                    );
                    
                    try {
                        $botonSiguiente->click();
                    } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                        $driver->executeScript("arguments[0].click();", [$botonSiguiente]);
                    }

                    // MEJORA: Esperar a que los dropdowns estén completamente cargados
                    sleep(1.5);

                    // Definir dropdowns con mejor mapeo
                    $dropdowns = [
                        ['data_id' => 'var_canal_front', 'index' => 0, 'aria_owns' => 'bs-select-1', 'name' => 'Canal'],
                        ['data_id' => 'inputState', 'index' => 1, 'aria_owns' => 'bs-select-2', 'name' => 'Sistema'],
                        ['data_id' => 'inputState', 'index' => 2, 'aria_owns' => 'bs-select-3', 'name' => 'UEN'],
                        ['data_id' => 'inputState', 'index' => 3, 'aria_owns' => 'bs-select-4', 'name' => 'Tecnologia'],
                        ['data_id' => 'inputState', 'index' => 4, 'aria_owns' => 'bs-select-5', 'name' => 'Tipo_Documento_Campo4'], // Este parece ser diferente
                        ['data_id' => 'TipoDocumento2', 'index' => 11, 'aria_owns' => 'bs-select-6', 'name' => 'Ciudad'], // Ciudad está en el índice 11
                    ];

                    foreach ($dropdowns as $dropdown) {
                        if (!isset($datos[$dropdown['index']]) || empty(trim($datos[$dropdown['index']]))) {
                            error_log("Valor vacío para {$dropdown['name']} en índice {$dropdown['index']}\n", 3, 'debug_bot.log');
                            continue;
                        }
                        
                        $valor = trim($datos[$dropdown['index']]);
                        $encontrado = false;
                        
                        error_log("Procesando {$dropdown['name']}: '$valor'\n", 3, 'debug_bot.log');

                        // MEJORA: Esperar específicamente a que el dropdown esté listo
                        $dropdownBoton = (new WebDriverWait($driver, 12))->until(
                            WebDriverExpectedCondition::elementToBeClickable(
                                WebDriverBy::cssSelector("button" . (isset($dropdown['data_id']) ? "[data-id='{$dropdown['data_id']}']" : "") . "[aria-owns='{$dropdown['aria_owns']}']")
                            )
                        );

                        // Hacer scroll al elemento y esperar
                        $driver->executeScript("arguments[0].scrollIntoView({behavior: 'smooth', block: 'center'});", [$dropdownBoton]);
                        sleep(1);

                        // Verificar que el dropdown no esté ya abierto
                        $isOpen = $dropdownBoton->getAttribute('aria-expanded') === 'true';
                        if (!$isOpen) {
                            try {
                                $dropdownBoton->click();
                            } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                                $driver->executeScript("arguments[0].click();", [$dropdownBoton]);
                            }
                            
                            // Esperar a que se abra el dropdown
                            sleep(1);
                        }

                        // MEJORA: Esperar más tiempo para que las opciones se carguen
                        $opciones = (new WebDriverWait($driver, 12))->until(
                            WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                                WebDriverBy::cssSelector("#{$dropdown['aria_owns']} ul li")
                            )
                        );

                        // Esperar un poco más para asegurar que todas las opciones estén cargadas
                        usleep(500000);

                        foreach ($opciones as $opcion) {
                            try {
                                $span = $opcion->findElement(WebDriverBy::cssSelector('span.text'));
                                $textoOpcion = trim($span->getText());
                                
                                if (strcasecmp($textoOpcion, $valor) === 0) {
                                    $driver->executeScript("arguments[0].scrollIntoView({behavior: 'smooth', block: 'center'});", [$opcion]);
                                    usleep(300000);
                                    
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
                                // Si no se puede leer el texto de esta opción, continuar con la siguiente
                                continue;
                            }
                        }
                        
                        if (!$encontrado) {
                            error_log("✗ Valor NO encontrado en {$dropdown['name']}: '$valor'\n", 3, 'errores_bot.log');
                            
                            // Intentar cerrar el dropdown si está abierto
                            try {
                                $driver->executeScript("arguments[0].click();", [$dropdownBoton]);
                            } catch (Exception $e) {
                                // Ignorar errores al cerrar
                            }
                        }

                        // Pequeña pausa entre dropdowns
                        usleep(300000);
                    }

                    // Continuar con campos de texto
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
                        $input = (new WebDriverWait($driver, 10))->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id($campo['id']))
                        );
                        $input->clear();
                        $input->sendKeys(trim($datos[$campo['index']]));
                    }

                    sleep(4);

                    // Guardar
                    $botonGuardar = $driver->wait(10)->until(
                        WebDriverExpectedCondition::elementToBeClickable(
                            WebDriverBy::xpath("//button[normalize-space()='Guardar']")
                        )
                    );
                    
                    try {
                        $botonGuardar->click();
                    } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                        $driver->executeScript("arguments[0].click();", [$botonGuardar]);
                    }

                    // Navegar a DATOS CLIENTE
                    $spanDatosCliente = (new WebDriverWait($driver, 10))->until(
                        WebDriverExpectedCondition::elementToBeClickable(
                            WebDriverBy::xpath("//span[contains(@class, 'nav-text') and normalize-space(text())='DATOS CLIENTE']")
                        )
                    );
                    $driver->executeScript("arguments[0].scrollIntoView(true);", [$spanDatosCliente]);
                    usleep(300000);
                    
                    try {
                        $spanDatosCliente->click();
                    } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                        $driver->executeScript("arguments[0].click();", [$spanDatosCliente]);
                    }

                    // Añadir a 'Gestiones_Realizadas.csv'
                    fputcsv($file, $datos);
                    $lineasProcesadas[] = $datos;

                } catch (TimeoutException $e) {
                    error_log("TimeoutException en registro: " . implode(',', $datos) . " - Error: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                    echo "<script>alert('Se ha superado el tiempo de espera');</script>";
                    header("Location: script_Bot.php");
                    exit;
                } catch (Exception $e) {
                    error_log("Error general en registro: " . implode(',', $datos) . " - Error: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                    continue;
                }
            }

            // Eliminar líneas procesadas de process.csv
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
            foreach ($todasLasLineas as $linea) fputcsv($gestor, $linea);
            fclose($gestor);

            $driver->quit();
            fclose($file);
            header("Location: again.php");

        } catch (TimeoutException | NoSuchElementException $e) {
            echo "<script>alert('Error: {$e->getMessage()}');</script>";
            $driver->quit();
            header("Location: script_Bot.php");
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