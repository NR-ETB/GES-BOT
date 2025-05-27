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

        // Configurar las opciones de Chrome
        $options = new ChromeOptions();
        $options->addArguments([
            //'--headless', // Descomenta esta línea para ejecutar en modo headless
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1200,720'
        ]);

        // Establecer las capacidades deseadas para Chrome
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        // Crear una instancia del WebDriver
        $driver = RemoteWebDriver::create($host, $capabilities);

        try {
            // Navegar a la página de inicio de sesión
            $driver->get('http://appintprd01.etb.com.co/2015/portal/Gestor/index.php#no-back-button');

            // Limpiar el almacenamiento local y de sesión
            $driver->executeScript('window.localStorage.clear();');
            $driver->executeScript('window.sessionStorage.clear();');

            // Esperar hasta que el enlace con el href específico sea clickeable
            $enlace = $driver->wait(10)->until(
                WebDriverExpectedCondition::elementToBeClickable(
                    WebDriverBy::xpath("//a[@href='AseguramientoFijos_v2/index.php']")
                )
            );
            $enlace->click();

            // Esperar a que la URL cambie correctamente
            $driver->wait(10)->until(
                WebDriverExpectedCondition::urlContains('AseguramientoFijos_v2/index.php')
            );

            // Ingresar el nombre de usuario y la contraseña
            $driver->findElement(WebDriverBy::name('login'))->sendKeys($username);
            $driver->findElement(WebDriverBy::name('clave'))->sendKeys($password);

            // Hacer clic en el botón de envío
            $driver->findElement(WebDriverBy::cssSelector('button[type="submit"]'))->click();

            echo "Página actual: " . $driver->getCurrentURL() . "\n";

            // Establecer sesión de usuario logueado
            $_SESSION['loggedin'] = true;

            ini_set('memory_limit', '512M');

            // Leer el archivo 'process.csv'
            $archivo = './process.csv';
            $gestor = fopen($archivo, 'r');
            if (!$gestor) {
                die("No se pudo abrir el archivo 'process.csv'.\n");
            }

            // Ruta del archivo CSV de salida
            $filePath = './Gestiones_Realizadas.csv';
            $fileExists2 = file_exists($filePath);
            $file = fopen($filePath, 'a');
            if (!$file) {
                die("No se pudo abrir o crear el archivo CSV.\n");
            }

            // Si el archivo CSV no existía, escribir los encabezados
            if (!$fileExists2) {
                fputcsv($file, [
                    'Canal',
                    'Sistema',
                    'UEN',
                    'Tecnologia',
                    'Tipo_de_Documento',
                    'Numero_de_Documento',
                    'Cliente',
                    'Contacto',
                    'Linea_del_Servicio',
                    'Pedido_Orden',
                    'Cuenta_de_Facturacion',
                    'Ciudad'
                ]);
            }

            // Procesar cada línea del archivo
// Procesar cada línea del archivo
while (($datos = fgetcsv($gestor)) !== false) {
    // Verificar si la línea está vacía o es el encabezado
    if (empty($datos) || strcasecmp(trim($datos[0]), 'Canal') === 0) {
        echo "⚠️ Línea vacía o encabezado detectado. Saltando...\n";
        continue;
    }

    try {
        // Clic en el botón "Siguiente"
        $botonSiguiente = (new WebDriverWait($driver, 10))->until(
            WebDriverExpectedCondition::elementToBeClickable(
                WebDriverBy::xpath("//button[text()='Siguiente']")
            )
        );
        $botonSiguiente->click();

        // Definir dropdowns con data-id, índice CSV y aria-owns para diferenciarlos
        $dropdowns = [
            ['data_id' => 'var_canal_front', 'index' => 0, 'aria_owns' => 'bs-select-1'],
            ['data_id' => 'inputState', 'index' => 1, 'aria_owns' => 'bs-select-2'],
            ['data_id' => 'inputState', 'index' => 2, 'aria_owns' => 'bs-select-3'],
            ['data_id' => 'inputState', 'index' => 3, 'aria_owns' => 'bs-select-4'],
            ['data_id' => 'inputState', 'index' => 4, 'aria_owns' => 'bs-select-5'],
            ['data_id' => 'TipoDocumento2', 'index' => 11, 'aria_owns' => 'bs-select-6'],
        ];

        foreach ($dropdowns as $dropdown) {
            // Validar que el índice exista en la línea actual del CSV
            if (!isset($datos[$dropdown['index']])) {
                echo "⚠️ Índice '{$dropdown['index']}' no definido en CSV para data-id '{$dropdown['data_id']}'. Saltando...\n";
                continue;
            }

            $valor = trim($datos[$dropdown['index']]);
            if (empty($valor)) {
                echo "⚠️ Valor vacío para data-id '{$dropdown['data_id']}'. Saltando...\n";
                continue;
            }

            // Si es el segundo dropdown, usar solo aria-owns
            if ($dropdown['aria_owns'] === 'bs-select-2') {
                $dropdownBoton = (new WebDriverWait($driver, 10))->until(
                    WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::cssSelector("button[aria-owns='{$dropdown['aria_owns']}']")
                    )
                );
            } else {
                // Para los demás dropdowns, usar data-id y aria-owns
                $dropdownBoton = (new WebDriverWait($driver, 10))->until(
                    WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::cssSelector("button[data-id='{$dropdown['data_id']}'][aria-owns='{$dropdown['aria_owns']}']")
                    )
                );
            }

            $driver->executeScript("arguments[0].scrollIntoView(true);", [$dropdownBoton]);
            usleep(500000);

            try {
                $dropdownBoton->click();
            } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                // Si está tapado, click con JS para evitar error
                $driver->executeScript("arguments[0].click();", [$dropdownBoton]);
            }

            // Esperar las opciones desplegadas
            $opciones = (new WebDriverWait($driver, 10))->until(
                WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                    WebDriverBy::cssSelector("#{$dropdown['aria_owns']} ul li")
                )
            );

            $opcionSeleccionada = false;
            foreach ($opciones as $opcion) {
                try {
                    $span = $opcion->findElement(WebDriverBy::cssSelector('span.text'));
                    $textoOpcion = trim($span->getText());

                    echo "Comparando: CSV='{$valor}' con opción='{$textoOpcion}'\n"; flush();

                    if (strcasecmp($textoOpcion, $valor) === 0) {
                        $driver->executeScript("arguments[0].scrollIntoView(true);", [$opcion]);
                        usleep(300000);
                        $opcion->click();

                        echo "✅ Opción '{$textoOpcion}' seleccionada correctamente.\n"; flush();

                        $opcionSeleccionada = true;
                        break;
                    }
                } catch (Exception $e) {
                    echo "⚠️ Error accediendo a opción: " . $e->getMessage() . "\n"; flush();
                }
            }

            if (!$opcionSeleccionada) {
                echo "Opción '{$valor}' no encontrada en menú con data-id '{$dropdown['data_id']}' y aria-owns '{$dropdown['aria_owns']}'.\n";
                continue 2; // pasa a siguiente línea CSV
            }
        }

        // Asignar valores a campos de texto por ID
        $camposTexto = [
            ['id' => 'NumeroDocumento', 'index' => 5],
            ['id' => 'Cliente', 'index' => 6],
            ['id' => 'Contacto', 'index' => 7],
            ['id' => 'LineaServicio', 'index' => 8],
            ['id' => 'PedidoOrden', 'index' => 9],
            ['id' => 'CuentaFacturacion', 'index' => 10],
        ];

        foreach ($camposTexto as $campo) {
            if (!isset($datos[$campo['index']])) {
                echo "⚠️ Valor faltante para campo '{$campo['id']}'. Saltando...\n";
                continue;
            }

            $valorCampo = trim($datos[$campo['index']]);
            $input = (new WebDriverWait($driver, 10))->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::id($campo['id'])
                )
            );
            $input->clear();
            $input->sendKeys($valorCampo);

            echo "✅ Campo '{$campo['id']}' completado con '{$valorCampo}'.\n"; flush();
        }

        sleep(10);

        // Clic en "Guardar"
        //$driver->wait(10)->until(
            //WebDriverExpectedCondition::elementToBeClickable(
                //WebDriverBy::id('id_boton_guardar')
            //)
        //)->click();

    } catch (TimeoutException $e) {
        echo "<script>alert('Se ha superado el tiempo de espera');</script>";
            header("Location: script_Bot.php");
        exit;
    }
}


            // Cerrar los archivos abiertos
            fclose($gestor);
            fclose($file);

        } catch (TimeoutException $e) {
            echo "<script type='text/javascript'>
                alert('Se ha superado el tiempo de entrega');
            </script>";
            header("Location: script_Bot.php");
        } catch (NoSuchElementException $e) {
            echo "<script type='text/javascript'>
                alert('Elemento no encontrado o Inexistente');
            </script>";
            header("Location: script_Bot.php");
        } finally {
            $driver->quit();
            header("Location: again.php");
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