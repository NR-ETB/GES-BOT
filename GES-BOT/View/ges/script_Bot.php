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
                //'--headless',
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

                $driver->executeScript('window.localStorage.clear();');
                $driver->executeScript('window.sessionStorage.clear();');

                // Espera hasta que el enlace con el href específico sea clickeable
                $enlace = $driver->wait(10)->until(
                    WebDriverExpectedCondition::elementToBeClickable(
                        WebDriverBy::xpath("//a[@href='AseguramientoFijos_v2/index.php']")
                    )
                );
                $enlace->click();

                // Esperar a que la URL cambie correctamente
                $driver->wait(2)->until(
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
                
                // Leer el primer dato del archivo 'process.csv'
                $archivo = './process.csv';

                ini_set('memory_limit', '512M');
                
                // Verificar si el archivo existe
                if (!file_exists($archivo)) {
                    die("El archivo 'process.csv' no existe.\n");
                }
                
                // Abrir el archivo en modo lectura
                $gestor = fopen($archivo, 'r');
                if (!$gestor) {
                    die("No se pudo abrir el archivo 'process.csv'.\n");
                }
                
                // Ruta del archivo CSV
                $filePath = './Gestiones_Realizadas1.csv';
                
                // Comprobar si el archivo CSV existe para agregar encabezados si es necesario
                $fileExists = file_exists($filePath);
                
                // Abrir el archivo CSV en modo de escritura (lo creará si no existe)
                $file = fopen($filePath, 'a');
                if (!$file) {
                    die("No se pudo abrir o crear el archivo CSV.\n");
                }
                
                // Si el archivo CSV no existía, escribir los encabezados
                if (!$fileExists) {
                    fputcsv($file, ['NumOrden', 'Usu_Mod', 'Usu_Pass', 'Obser', 'Hor_Ini', 'Hor_Fin']);
                }
                
                // Procesar cada línea del archivo
                while (($linea = fgets($gestor)) !== false) {
                    $primerDato = trim($linea);
                    // if ($primerDato === '') {
                        //continue;
                    //}

                    $exceptionHandled = false;
                
                    try {

                        //$wait = new WebDriverWait($driver, 0.61, 500);

                        //$wait2 = new WebDriverWait($driver, 1.61, 500);

                        // Capturar el tiempo de inicio
                        $horaInicio = microtime(true);

                        $inputPersonaNatural = (new WebDriverWait($driver, 10))->until(
                            WebDriverExpectedCondition::visibilityOfElementLocated(
                                WebDriverBy::xpath("//input[@placeholder='Persona Natural']")
                            )
                        );

                        $botonSiguiente = $driver->wait(10)->until(
                            WebDriverExpectedCondition::elementToBeClickable(
                                WebDriverBy::xpath("//button[contains(text(), 'Siguiente')]")
                            )
                        );
                        $botonSiguiente->click();

                        // Asignar los valores del CSV a variables
                        $campo1 = $fila[0];
                        $campo2 = $fila[1];
                        // ... y así sucesivamente

                        // Esperar y llenar el campo 1
                        $driver->wait(10)->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('id_campo1'))
                        )->sendKeys($campo1);

                        // Esperar y llenar el campo 2
                        $driver->wait(10)->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('id_campo2'))
                        )->sendKeys($campo2);

                        // ... repetir para los demás campos

                        // Hacer clic en el botón "Guardar"
                        $driver->wait(10)->until(
                            WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::id('id_boton_guardar'))
                        )->click();
                
                    } catch (TimeoutException $e) {

                        echo "<script type='text/javascript'>
                            alert('Se ha superado el tiempo de entrega');
                        </script>";
                        header("Location: script_Bot.php");
                        
                    }
                }             
                
                // Cerrar los archivos abiertos
                fclose($gestor);
                fclose($file);                    

            }catch (TimeoutException $e) {

                echo "<script type='text/javascript'>
                    alert('Se ha superado el tiempo de entrega');
                </script>";
                header("Location: script_Bot.php");

            }catch (NoSuchElementException $e) {

                echo "<script type='text/javascript'>
                    alert('Elemento no encontrado o Inexistente');
                </script>";
                header("Location: script_Bot.php");

            }finally {
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