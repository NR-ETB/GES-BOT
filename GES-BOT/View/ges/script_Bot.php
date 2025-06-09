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
                    'Canal','Sistema','UEN','Tecnologia','Tipo_de_Documento','Numero_de_Documento','Cliente','Contacto','Linea_del_Servicio','Pedido_Orden','Cuenta_de_Facturacion','Ciudad','Error','Solucion','Adjunto'
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
                        $driver->wait(6)->until( // Reducido de 20 a 12
                            WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::xpath("//button[text()='Siguiente']"))
                        );

                        // Hacer scroll hacia arriba
                        $driver->executeScript("window.scrollTo(0, 0);");
                        sleep(1); // Reducido de 2 a 1

                        // Hacer clic en "Siguiente" 
                        $botonSiguiente = $driver->wait(6)->until( // Reducido de 15 a 10
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
                                $dropdownBoton = (new WebDriverWait($driver, 6))->until( // Reducido de 20 a 12
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
                                $opciones = (new WebDriverWait($driver, 6))->until( // Reducido de 20 a 12
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
                                $input = (new WebDriverWait($driver, 6))->until( // Reducido de 15 a 10
                                    WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id($campo['id']))
                                );
                                $input->clear();
                                $input->sendKeys(trim($datos[$campo['index']]));
                            } catch (Exception $e) {
                                error_log("Error llenando campo {$campo['id']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                            }
                        }

                        sleep(2); // Reducido de 5 a 3

                        // Guardar
                        $botonGuardar = $driver->wait(6)->until( // Reducido de 20 a 12
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

                        $procesamientoExitoso = true;

                        if (isset($datos[12]) && !empty(trim($datos[12]))) {
                            try {

                                $valor = trim($datos[12]);
                                
                                if ($valor === "ADECUACIONES") {
                                    
                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "ADICION DE BUNDLE AUXILIAR") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[16]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    if ($valor_2 === "DIRECTV GO") {
                                        // Seleccionar checkbox DIRECTV GO
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::id("customCheckBox1")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "PARAMOUNT") {
                                        // Seleccionar checkbox PARAMOUNT
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::id("customCheckBox1")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "UNIVERSAL") {
                                        // Seleccionar checkbox UNIVERSAL
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::id("customCheckBox1")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "WIN SPORT ONLINE") {
                                        // Seleccionar checkbox WIN SPORT ONLINE
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::id("customCheckBox1")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "HBO MAX") {
                                        // Seleccionar checkbox HBO MAX
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::id("customCheckBox5")
                                            )
                                    );

                                    if (!$checkbox->isSelected()) {
                                        $checkbox->click();
                                    }
                                    
                                    } else {
                                        // Si no coincide con ninguna opción específica, buscar por texto del label
                                        $valorEscapado = json_encode($$valor_2);
                                        $xpath = "//label[contains(text(), $valorEscapado)]/preceding-sibling::input[@type='checkbox']";
                                        
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        if (!$checkbox->isSelected()) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000); // 300ms
                                            $checkbox->click();
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "ADICION DE SVAS") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[16]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    if ($valor_2 === "DECO") {
                                        // Seleccionar checkbox DECO
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='DECO']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "WIFI PLUS") {
                                        // Seleccionar checkbox WIFI PLUS
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='WIFI PLUS']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "SOLUCION AP MESH 2") {
                                        // Seleccionar checkbox SOLUCION AP MESH 2
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SOLUCION AP MESH 2']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "SOLUCION AP MESH 3") {
                                        // Seleccionar checkbox SOLUCION AP MESH 3
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SOLUCION AP MESH 3']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "AP MESH ADICIONAL") {
                                        // Seleccionar checkbox AP MESH ADICIONAL
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='AP MESH ADICIONAL']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "HOTPACK") {
                                        // Seleccionar checkbox HOTPACK
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='HOTPACK']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "PUNTO CABLEADO") {
                                        // Seleccionar checkbox PUNTO CABLEADO
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='PUNTO CABLEADO']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "SVA MAS VELOCIDAD") {
                                        // Seleccionar checkbox SVA MAS VELOCIDAD
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SVA MAS VELOCIDAD']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "HBO PACK") {
                                        // Seleccionar checkbox HBO PACK
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='HBO PACK']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "WIN SPORT SD/HD/DTV") {
                                        // Seleccionar checkbox WIN SPORT SD/HD/DTV
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='WIN SPORT SD/HD/DTV']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else {
                                        // Si no coincide con ninguna opción específica, buscar por valor_2
                                        $valorEscapado = json_encode($valor_2);
                                        $xpath = "//input[@type='checkbox' and @value=$valorEscapado]";
                                        
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        if (!$checkbox->isSelected()) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000); // 300ms
                                            $checkbox->click();
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "CAMBIO DE BUNDLE AUXILIAR") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[17]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000);
                                    $enlace->click();
                                    usleep(500000); 

                                    // PASO CRÍTICO: Abrir el dropdown primero
                                    try {
                                        // Buscar el botón del dropdown (puede tener diferentes selectores)
                                        $dropdownButton = null;
                                        $selectoresDropdown = [
                                            "//button[contains(@class, 'dropdown-toggle')]",
                                            "//div[contains(@class, 'dropdown')]//button",
                                            "//select[contains(@name, 'bundle') or contains(@name, 'Bundle')]",
                                            "//div[@role='button' and contains(@class, 'select')]"
                                        ];
                                        
                                        foreach ($selectoresDropdown as $selector) {
                                            try {
                                                $dropdownButton = $driver->wait(3)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath($selector)
                                                    )
                                                );
                                                error_log("Dropdown encontrado con selector: $selector\n", 3, 'errores_bot.log');
                                                break;
                                            } catch (Exception $e) {
                                                continue;
                                            }
                                        }
                                        
                                        if ($dropdownButton) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$dropdownButton]);
                                            usleep(300000);
                                            $dropdownButton->click();
                                            usleep(800000); // Esperar a que se abra el dropdown
                                            error_log("Dropdown abierto exitosamente\n", 3, 'errores_bot.log');
                                        } else {
                                            error_log("No se pudo encontrar el botón del dropdown\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } catch (Exception $e) {
                                        error_log("Error abriendo dropdown: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    // Ahora seleccionar la opción específica
                                    if ($valor_2 === "NUEVO DIRECTV FULL") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'NUEVO DIRECTV FULL')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "NUEVO DIRECTV FLEX") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'NUEVO DIRECTV FLEX')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "NUEVO DIRECTV BASICO") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'NUEVO DIRECTV BASICO')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else {
                                        // Si no coincide con ninguna opción específica, buscar por texto
                                        $valorEscapado = json_encode($valor_2);
                                        $xpath = "//a[@role='option' and contains(text(), $valorEscapado)]";
                                        
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "CAMBIO DE ESTRATO") { //CON BUGS 

                                    $valor = trim($datos[12]);

                                    // Definir los campos de input con sus respectivos índices del CSV
                                    $camposInput = [
                                        ['name' => 'DireccionActual', 'index' => 18],    // Ajusta el índice según tu CSV
                                        ['name' => 'DireccionDestino', 'index' => 19],   // Ajusta el índice según tu CSV
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $input = $driver->wait(10)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Hacer scroll para asegurar que el elemento esté visible
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000); // 200ms
                                            
                                            // Limpiar el campo y escribir el nuevo valor
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            // Opcional: disparar evento para validaciones JavaScript
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                                                        sleep(3); 
                                    
                                } else if ($valor === "CAMBIO DE NUMERO") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                                                        sleep(3); 
                                    
                                } else if ($valor === "CAMBIO DE PLAN") { //CON BUGS 

                                    $valor = trim($datos[12]);

                                   // Definir los campos de input con sus respectivos índices del CSV
                                    $camposInput = [
                                        ['name' => 'DireccionDestino', 'index' => 19],   // Ajusta el índice según tu CSV
                                        ['name' => 'PlanOfrecido', 'index' => 20],       // Ajusta el índice según tu CSV
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $input = $driver->wait(10)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Hacer scroll para asegurar que el elemento esté visible
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000); // 200ms
                                            
                                            // Limpiar el campo y escribir el nuevo valor
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            // Opcional: disparar evento para validaciones JavaScript
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                                                        sleep(3); 
                                    
                                } else if ($valor === "CAMBIO DE TECNOLOGIA") { //CON BUGS 

                                    $valor = trim($datos[12]);

                                    // Definir los campos de input con sus respectivos índices del CSV
                                    $camposInput = [
                                        ['name' => 'DireccionActual', 'index' => 18],    // Ajusta el índice según tu CSV
                                        ['name' => 'DireccionDestino', 'index' => 19],   // Ajusta el índice según tu CSV
                                        ['name' => 'PlanOfrecido', 'index' => 20],       // Ajusta el índice según tu CSV
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $input = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Hacer scroll para asegurar que el elemento esté visible
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000); // 200ms
                                            
                                            // Limpiar el campo y escribir el nuevo valor
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            // Opcional: disparar evento para validaciones JavaScript
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "CANCELACION INMEDIATA") {

                                    $valor = trim($datos[12]);
                                    
                                    // Definir los campos de input con sus respectivos índices del CSV
                                    $camposInput = [
                                        ['name' => 'DireccionDestino', 'index' => 19],   // Ajusta el índice según tu CSV
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $input = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Hacer scroll para asegurar que el elemento esté visible
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000); // 200ms
                                            
                                            // Limpiar el campo y escribir el nuevo valor
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            // Opcional: disparar evento para validaciones JavaScript
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                                                        sleep(3); 
                                    
                                } else if ($valor === "CANCELACION VOLUNTARIA") {

                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[21]);
        
                                    if ($valor_2 === "MOTIVO ECONOMICO") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'MOTIVO ECONOMICO')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "FALLA DEL SERVICIO") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'FALLA DEL SERVICIO')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "MEJOR OFERTA DE LA COMPETENCIA") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'MEJOR OFERTA DE LA COMPETENCIA')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "TRASLADO SIN COBERTURA") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'TRASLADO SIN COBERTURA')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "VIAJE FUERA DE LA CIUDAD") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'VIAJE FUERA DE LA CIUDAD')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "INCONFORMIDAD CON LA FACTURA") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'INCONFORMIDAD CON LA FACTURA')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "MOTIVOS PERSONALES") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'MOTIVOS PERSONALES')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "INCONFORMIDAD CON EL SERVICIO") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'INCONFORMIDAD CON EL SERVICIO')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "TITULAR FALLECIDO") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'TITULAR FALLECIDO')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else {
                                        // Fallback para opciones no listadas
                                        $valorEscapado = json_encode($valor_2);
                                        $xpath = "//a[@role='option' and contains(text(), $valorEscapado)]";
                                        
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                    }
                                    
                                    usleep(500000); // Esperar que se procese la selección

                                    $camposInput = [
                                        ['name' => 'SoportePQR', 'index' => 13],         
                                        ['name' => 'DireccionDestino', 'index' => 14],   
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        foreach ($camposInput as $campo) {
                                            if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                                continue;
                                            }
                                            
                                            try {
                                                $input = $driver->wait(6)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000);
                                                
                                                $input->clear();
                                                $texto = trim($datos[$campo['index']]);
                                                $input->sendKeys($texto);
                                                
                                                // Verificar que el texto se escribió correctamente
                                                $textoActual = $input->getAttribute('value');
                                                if ($textoActual !== $texto) {
                                                    error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }

                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }
                                    
                                } else if ($valor === "CESION DE CONTRATO PERSONA NATURAL") {

                                    $valor = trim($datos[12]);

                                    $camposFormulario = [
                                        // Campos de input de texto
                                        ['name' => 'NuevoTitularNombresApellidos', 'type' => 'input', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularCorreoElectronico', 'type' => 'input', 'index' => ''], // Índice por definir
                                        
                                        // Campos de input numéricos
                                        ['name' => 'NuevoTitularNumeroDocumento', 'type' => 'number', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularCelular', 'type' => 'number', 'index' => ''], // Índice por definir
                                        
                                        // Campos select (dropdown)
                                        ['name' => 'NuevoTitularTipoDocumento', 'type' => 'select', 'index' => ''], // Índice por definir
                                    ];

                                    // Variable para controlar si el procesamiento fue exitoso
                                    $procesamientoExitoso = true;

                                    // Procesar cada campo del formulario
                                    foreach ($camposFormulario as $campo) {
                                        // Verificar que el índice esté definido y que el dato exista en el CSV
                                        if (empty($campo['index']) || !isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue; // Saltar este campo si no hay datos
                                        }
                                        
                                        try {
                                            $valorCampo = trim($datos[$campo['index']]);
                                            
                                            if ($campo['type'] === 'input' || $campo['type'] === 'number') {
                                                // Manejar campos de input (texto y numéricos)
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Limpiar el campo y escribir el nuevo valor
                                                $input->clear();
                                                $input->sendKeys($valorCampo);
                                                
                                                // Verificar que el texto se escribió correctamente
                                                $textoActual = $input->getAttribute('value');
                                                if ($textoActual !== $valorCampo) {
                                                    error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } elseif ($campo['type'] === 'select') {
                                                // Manejar campos select (dropdown)
                                                
                                                // Primero intentar encontrar y hacer clic en el select para abrirlo
                                                $selectElement = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath("//select[@name='{$campo['name']}']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$selectElement]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Hacer clic en el select para abrirlo
                                                $selectElement->click();
                                                usleep(300000); // Pausa adicional para que se abra el dropdown
                                                
                                                // Escapar valor para XPath seguro
                                                $valorEscapado = json_encode($valorCampo);
                                                
                                                // Buscar la opción en el dropdown
                                                // Intentar diferentes variaciones de XPath para encontrar la opción
                                                $xpathOptions = [
                                                    "//select[@name='{$campo['name']}']/option[contains(text(), $valorEscapado)]",
                                                    "//select[@name='{$campo['name']}']/option[@value=$valorEscapado]",
                                                    "//option[contains(text(), $valorEscapado)]", // Para dropdowns dinámicos
                                                    "//li/a[contains(text(), $valorEscapado)]", // Para dropdowns personalizados
                                                ];
                                                
                                                $opcionEncontrada = false;
                                                foreach ($xpathOptions as $xpath) {
                                                    try {
                                                        $opcion = $driver->wait(5)->until(
                                                            WebDriverExpectedCondition::elementToBeClickable(
                                                                WebDriverBy::xpath($xpath)
                                                            )
                                                        );
                                                        
                                                        if ($opcion) {
                                                            $opcion->click();
                                                            $opcionEncontrada = true;
                                                            break;
                                                        }
                                                    } catch (Exception $e) {
                                                        // Continuar con el siguiente XPath si este falla
                                                        continue;
                                                    }
                                                }
                                                
                                                if (!$opcionEncontrada) {
                                                    throw new Exception("No se pudo encontrar la opción '$valorCampo' en el select '{$campo['name']}'");
                                                }
                                                
                                                // Disparar evento change para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$selectElement]);
                                            }
                                            
                                            // Pausa breve entre campos para evitar problemas de timing
                                            usleep(300000); // 300ms
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error procesando campo {$campo['name']} (tipo: {$campo['type']}): " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // Opcional: tomar screenshot para debugging
                                            // $driver->takeScreenshot('error_campo_' . $campo['name'] . '_' . date('Y-m-d_H-i-s') . '.png');
                                        }
                                    }

                                    // Verificar el resultado del procesamiento
                                    if ($procesamientoExitoso) {
                                        error_log("Todos los campos se procesaron exitosamente\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar algunos campos. Revisar errores_bot.log\n", 3, 'proceso_bot.log');
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "CESION DE CONTRATO PERSONA JURIDICA") {

                                    $valor = trim($datos[12]);

                                    $camposFormulario = [
                                        // Campos de input de texto
                                        ['name' => 'NuevoTitularNIT', 'type' => 'input', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularActividadEconomica', 'type' => 'input', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularRLNombreEmpresa', 'type' => 'input', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularRLNombresApellidos', 'type' => 'input', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularRLCorreoElectronico', 'type' => 'input', 'index' => ''], // Índice por definir
                                        
                                        // Campos de fecha
                                        ['name' => 'NuevoTitularFechaConstitucion', 'type' => 'date', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularRLFechaNacimiento', 'type' => 'date', 'index' => ''], // Índice por definir
                                        
                                        // Campos numéricos
                                        ['name' => 'NuevoTitularRLNumeroDocumento', 'type' => 'number', 'index' => ''], // Índice por definir
                                        ['name' => 'NuevoTitularRLContacto', 'type' => 'number', 'index' => ''], // Índice por definir
                                        
                                        // Campo select/dropdown (si existe en tu formulario pero no se ve en la imagen)
                                        // ['name' => 'NuevoTitularTipoDocumento', 'type' => 'select', 'index' => ''], // Descomenta si existe
                                    ];

                                    // Variable para controlar si el procesamiento fue exitoso
                                    $procesamientoExitoso = true;

                                    // Procesar cada campo del formulario
                                    foreach ($camposFormulario as $campo) {
                                        // Verificar que el índice esté definido y que el dato exista en el CSV
                                        if (empty($campo['index']) || !isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            error_log("Saltando campo {$campo['name']}: índice vacío o sin datos\n", 3, 'proceso_bot.log');
                                            continue; // Saltar este campo si no hay datos
                                        }
                                        
                                        try {
                                            $valorCampo = trim($datos[$campo['index']]);
                                            
                                            if ($campo['type'] === 'input') {
                                                // Manejar campos de input de texto
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Limpiar el campo y escribir el nuevo valor
                                                $input->clear();
                                                $input->sendKeys($valorCampo);
                                                
                                                // Verificar que el texto se escribió correctamente
                                                $textoActual = $input->getAttribute('value');
                                                if ($textoActual !== $valorCampo) {
                                                    error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } elseif ($campo['type'] === 'number') {
                                                // Manejar campos numéricos
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='number']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Validar que el valor sea numérico
                                                if (!is_numeric($valorCampo)) {
                                                    throw new Exception("El valor '$valorCampo' no es numérico para el campo {$campo['name']}");
                                                }
                                                
                                                // Limpiar el campo y escribir el nuevo valor
                                                $input->clear();
                                                $input->sendKeys($valorCampo);
                                                
                                                // Verificar que el número se escribió correctamente
                                                $numeroActual = $input->getAttribute('value');
                                                if ($numeroActual !== $valorCampo) {
                                                    error_log("Advertencia: El número en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$numeroActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } elseif ($campo['type'] === 'date') {
                                                // Manejar campos de fecha
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='date']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Validar y convertir formato de fecha si es necesario
                                                $fechaFormateada = $valorCampo;
                                                
                                                // Si la fecha viene en formato diferente (ej: dd/mm/yyyy), convertir a yyyy-mm-dd
                                                if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valorCampo, $matches)) {
                                                    $fechaFormateada = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
                                                }
                                                
                                                // Validar que la fecha sea válida
                                                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFormateada)) {
                                                    throw new Exception("El formato de fecha '$valorCampo' no es válido para el campo {$campo['name']}");
                                                }
                                                
                                                // Limpiar el campo y escribir la fecha
                                                $input->clear();
                                                $input->sendKeys($fechaFormateada);
                                                
                                                // Verificar que la fecha se escribió correctamente
                                                $fechaActual = $input->getAttribute('value');
                                                if ($fechaActual !== $fechaFormateada) {
                                                    error_log("Advertencia: La fecha en {$campo['name']} no coincide. Esperado: '$fechaFormateada', Actual: '$fechaActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } elseif ($campo['type'] === 'select') {
                                                // Manejar campos select (dropdown) - descomenta si tienes dropdowns
                                                $selectElement = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath("//select[@name='{$campo['name']}']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$selectElement]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Hacer clic en el select para abrirlo
                                                $selectElement->click();
                                                usleep(300000); // Pausa adicional para que se abra el dropdown
                                                
                                                // Escapar valor para XPath seguro
                                                $valorEscapado = json_encode($valorCampo);
                                                
                                                // Buscar la opción en el dropdown
                                                $xpathOptions = [
                                                    "//select[@name='{$campo['name']}']/option[contains(text(), $valorEscapado)]",
                                                    "//select[@name='{$campo['name']}']/option[@value=$valorEscapado]",
                                                    "//option[contains(text(), $valorEscapado)]",
                                                    "//li/a[contains(text(), $valorEscapado)]",
                                                ];
                                                
                                                $opcionEncontrada = false;
                                                foreach ($xpathOptions as $xpath) {
                                                    try {
                                                        $opcion = $driver->wait(5)->until(
                                                            WebDriverExpectedCondition::elementToBeClickable(
                                                                WebDriverBy::xpath($xpath)
                                                            )
                                                        );
                                                        
                                                        if ($opcion) {
                                                            $opcion->click();
                                                            $opcionEncontrada = true;
                                                            break;
                                                        }
                                                    } catch (Exception $e) {
                                                        continue;
                                                    }
                                                }
                                                
                                                if (!$opcionEncontrada) {
                                                    throw new Exception("No se pudo encontrar la opción '$valorCampo' en el select '{$campo['name']}'");
                                                }
                                                
                                                // Disparar evento change
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$selectElement]);
                                            }
                                            
                                            // Pausa breve entre campos para evitar problemas de timing
                                            usleep(300000); // 300ms
                                            
                                            error_log("Campo {$campo['name']} procesado exitosamente con valor: '$valorCampo'\n", 3, 'proceso_bot.log');
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error procesando campo {$campo['name']} (tipo: {$campo['type']}): " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // Opcional: tomar screenshot para debugging
                                            try {
                                                $driver->takeScreenshot('error_campo_' . $campo['name'] . '_' . date('Y-m-d_H-i-s') . '.png');
                                            } catch (Exception $screenshotError) {
                                                error_log("No se pudo tomar screenshot: " . $screenshotError->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }
                                    }

                                    // Verificar el resultado del procesamiento
                                    if ($procesamientoExitoso) {
                                        error_log("Todos los campos del formulario se procesaron exitosamente\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar algunos campos del formulario. Revisar errores_bot.log para detalles\n", 3, 'proceso_bot.log');
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "CONTROL FRAUDE") {
                                    
                                    $valor = trim($datos[12]);

                                    // Configuración del menú dropdown
                                    $menuDropdown = [
                                        'button_id' => 'inputState', // ID del botón que abre el menú
                                        'csv_index' => '', // Índice del CSV por definir
                                        'opciones_disponibles' => [
                                            'DESBLOQUEO ADMINISTRATIVO',
                                            'LEVANTAR RESTRICCION EN RECMA',
                                            'LEVANTAR BLOQUEO DOCUMENTO SUPLANTACION',
                                            'SUSPENSION CONTROL PREVENTIVO',
                                            'RECONEXION CONTROL PREVENTIVA',
                                            'OTRO'
                                        ]
                                    ];

                                    // Variable para controlar si el procesamiento fue exitoso
                                    $procesamientoExitoso = true;

                                    try {
                                        // Verificar que el índice esté definido y que el dato exista en el CSV
                                        if (empty($menuDropdown['csv_index']) || !isset($datos[$menuDropdown['csv_index']]) || empty(trim($datos[$menuDropdown['csv_index']]))) {
                                            error_log("Saltando menú dropdown: índice vacío o sin datos\n", 3, 'proceso_bot.log');
                                            throw new Exception("No hay datos para el menú dropdown");
                                        }
                                        
                                        $valorSeleccionar = trim($datos[$menuDropdown['csv_index']]);
                                        error_log("Intentando seleccionar opción: '$valorSeleccionar'\n", 3, 'proceso_bot.log');
                                        
                                        // PASO 1: Encontrar y hacer clic en el botón para abrir el menú
                                        $botonDropdown = $driver->wait(10)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//button[@data-id='{$menuDropdown['button_id']}']")
                                            )
                                        );
                                        
                                        // Hacer scroll para asegurar que el botón esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$botonDropdown]);
                                        usleep(300000); // Pausa de 300ms
                                        
                                        // Hacer clic en el botón para abrir el menú
                                        $botonDropdown->click();
                                        error_log("Botón dropdown clickeado exitosamente\n", 3, 'proceso_bot.log');
                                        
                                        // Pausa para que el menú se abra completamente
                                        usleep(500000); // 500ms
                                        
                                        // PASO 2: Esperar a que el menú dropdown sea visible
                                        $menuVisible = $driver->wait(10)->until(
                                            WebDriverExpectedCondition::visibilityOfElementLocated(
                                                WebDriverBy::xpath("//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]")
                                            )
                                        );
                                        
                                        if (!$menuVisible) {
                                            throw new Exception("El menú dropdown no se abrió correctamente");
                                        }
                                        
                                        error_log("Menú dropdown abierto y visible\n", 3, 'proceso_bot.log');
                                        
                                        // PASO 3: Buscar y seleccionar la opción correcta
                                        $opcionEncontrada = false;
                                        
                                        // Escapar el valor para XPath seguro
                                        $valorEscapado = json_encode($valorSeleccionar);
                                        
                                        // Lista de estrategias XPath para encontrar la opción
                                        $xpathStrategies = [
                                            // Buscar por texto exacto en span
                                            "//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]//span[contains(@class, 'text') and text()=$valorEscapado]",
                                            
                                            // Buscar por texto que contenga el valor en span
                                            "//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]//span[contains(@class, 'text') and contains(text(), $valorEscapado)]",
                                            
                                            // Buscar en el elemento padre (a o li) que contenga el span
                                            "//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]//a[contains(@class, 'dropdown-item') and .//span[contains(text(), $valorEscapado)]]",
                                            
                                            // Buscar por li que contenga el texto
                                            "//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]//li//a[contains(text(), $valorEscapado)]",
                                            
                                            // Buscar más general por cualquier elemento clickeable que contenga el texto
                                            "//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]//*[contains(text(), $valorEscapado)]"
                                        ];
                                        
                                        foreach ($xpathStrategies as $index => $xpath) {
                                            try {
                                                error_log("Intentando estrategia XPath " . ($index + 1) . ": $xpath\n", 3, 'proceso_bot.log');
                                                
                                                $opcion = $driver->wait(5)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                if ($opcion) {
                                                    // Hacer scroll a la opción para asegurar que esté visible
                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                                    usleep(200000); // 200ms
                                                    
                                                    // Hacer clic en la opción
                                                    $opcion->click();
                                                    $opcionEncontrada = true;
                                                    error_log("Opción '$valorSeleccionar' seleccionada exitosamente usando estrategia " . ($index + 1) . "\n", 3, 'proceso_bot.log');
                                                    break;
                                                }
                                            } catch (Exception $e) {
                                                error_log("Estrategia XPath " . ($index + 1) . " falló: " . $e->getMessage() . "\n", 3, 'proceso_bot.log');
                                                continue;
                                            }
                                        }
                                        
                                        // Si no se encontró con las estrategias anteriores, intentar buscar por coincidencia parcial
                                        if (!$opcionEncontrada) {
                                            error_log("Intentando búsqueda por coincidencia parcial\n", 3, 'proceso_bot.log');
                                            
                                            foreach ($menuDropdown['opciones_disponibles'] as $opcionDisponible) {
                                                if (stripos($opcionDisponible, $valorSeleccionar) !== false || stripos($valorSeleccionar, $opcionDisponible) !== false) {
                                                    $valorEscapadoParcial = json_encode($opcionDisponible);
                                                    
                                                    try {
                                                        $opcion = $driver->wait(5)->until(
                                                            WebDriverExpectedCondition::elementToBeClickable(
                                                                WebDriverBy::xpath("//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]//*[contains(text(), $valorEscapadoParcial)]")
                                                            )
                                                        );
                                                        
                                                        if ($opcion) {
                                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                                            usleep(200000);
                                                            $opcion->click();
                                                            $opcionEncontrada = true;
                                                            error_log("Opción '$opcionDisponible' seleccionada por coincidencia parcial con '$valorSeleccionar'\n", 3, 'proceso_bot.log');
                                                            break;
                                                        }
                                                    } catch (Exception $e) {
                                                        continue;
                                                    }
                                                }
                                            }
                                        }
                                        
                                        if (!$opcionEncontrada) {
                                            throw new Exception("No se pudo encontrar la opción '$valorSeleccionar' en el menú dropdown. Opciones disponibles: " . implode(', ', $menuDropdown['opciones_disponibles']));
                                        }
                                        
                                        // PASO 4: Verificar que el menú se cerró después de la selección
                                        usleep(300000); // Pausa para que el menú se cierre
                                        
                                        try {
                                            // Verificar que el menú ya no esté visible
                                            $menuCerrado = $driver->wait(5)->until(
                                                WebDriverExpectedCondition::invisibilityOfElementLocated(
                                                    WebDriverBy::xpath("//div[contains(@class, 'dropdown-menu') and contains(@class, 'show')]")
                                                )
                                            );
                                            
                                            if ($menuCerrado) {
                                                error_log("Menú dropdown cerrado correctamente después de la selección\n", 3, 'proceso_bot.log');
                                            }
                                        } catch (Exception $e) {
                                            error_log("Advertencia: No se pudo verificar el cierre del menú: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                        // PASO 5: Verificar que la selección se reflejó en el botón (opcional)
                                        try {
                                            $textoBoton = $botonDropdown->getText();
                                            error_log("Texto actual del botón después de selección: '$textoBoton'\n", 3, 'proceso_bot.log');
                                            
                                            if (stripos($textoBoton, $valorSeleccionar) === false) {
                                                error_log("Advertencia: El texto del botón no parece reflejar la selección realizada\n", 3, 'errores_bot.log');
                                            }
                                        } catch (Exception $e) {
                                            error_log("No se pudo verificar el texto del botón: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                        // Pausa final antes de continuar
                                        usleep(500000); // 500ms
                                        
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error procesando menú dropdown: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // Tomar screenshot para debugging
                                        try {
                                            $driver->takeScreenshot('error_dropdown_menu_' . date('Y-m-d_H-i-s') . '.png');
                                        } catch (Exception $screenshotError) {
                                            error_log("No se pudo tomar screenshot: " . $screenshotError->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                        // Intentar cerrar el menú si quedó abierto
                                        try {
                                            $driver->executeScript("document.querySelectorAll('.dropdown-menu.show').forEach(menu => menu.classList.remove('show'));");
                                        } catch (Exception $closeError) {
                                            // Ignorar errores al intentar cerrar
                                        }
                                    }

                                    // Verificar el resultado del procesamiento
                                    if ($procesamientoExitoso) {
                                        error_log("Menú dropdown procesado exitosamente\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar el menú dropdown. Revisar errores_bot.log para detalles\n", 3, 'proceso_bot.log');
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "DESCUENTOS") {

                                    $valor = trim($datos[12]);
                                   
                                    $camposFormulario = [
                                        // Campos de fecha
                                        ['name' => 'FechaInicio', 'type' => 'date', 'index' => ''], // Índice por definir
                                        ['name' => 'FechaFin', 'type' => 'date', 'index' => ''], // Índice por definir
                                        
                                        // Campos de input de texto
                                        ['name' => 'PlanoPredio', 'type' => 'input', 'index' => ''], // Índice por definir
                                        ['name' => 'SoportePQR', 'type' => 'input', 'index' => ''], // Índice por definir
                                        
                                        // Campo de textarea
                                        ['name' => 'MesesAplicarDescuentoManual', 'type' => 'textarea', 'index' => ''], // Índice por definir
                                        
                                        // Campo numérico con restricciones específicas
                                        ['name' => 'Porcentaje', 'type' => 'number', 'index' => '', 'min' => 0, 'max' => 100], // Índice por definir
                                    ];

                                    // Variable para controlar si el procesamiento fue exitoso
                                    $procesamientoExitoso = true;

                                    // Procesar cada campo del formulario
                                    foreach ($camposFormulario as $campo) {
                                        // Verificar que el índice esté definido y que el dato exista en el CSV
                                        if (empty($campo['index']) || !isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            error_log("Saltando campo {$campo['name']}: índice vacío o sin datos\n", 3, 'proceso_bot.log');
                                            continue; // Saltar este campo si no hay datos
                                        }
                                        
                                        try {
                                            $valorCampo = trim($datos[$campo['index']]);
                                            error_log("Procesando campo {$campo['name']} con valor: '$valorCampo'\n", 3, 'proceso_bot.log');
                                            
                                            if ($campo['type'] === 'date') {
                                                // Manejar campos de fecha
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='date']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Validar y convertir formato de fecha si es necesario
                                                $fechaFormateada = $valorCampo;
                                                
                                                // Si la fecha viene en formato dd/mm/yyyy, convertir a yyyy-mm-dd
                                                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valorCampo, $matches)) {
                                                    $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                                                    $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                                                    $ano = $matches[3];
                                                    $fechaFormateada = $ano . '-' . $mes . '-' . $dia;
                                                }
                                                // Si viene en formato dd-mm-yyyy
                                                elseif (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $valorCampo, $matches)) {
                                                    $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                                                    $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                                                    $ano = $matches[3];
                                                    $fechaFormateada = $ano . '-' . $mes . '-' . $dia;
                                                }
                                                
                                                // Validar que la fecha sea válida
                                                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFormateada)) {
                                                    throw new Exception("El formato de fecha '$valorCampo' no es válido para el campo {$campo['name']}. Se esperaba formato yyyy-mm-dd, dd/mm/yyyy o dd-mm-yyyy");
                                                }
                                                
                                                // Limpiar el campo y escribir la fecha
                                                $input->clear();
                                                usleep(100000); // 100ms
                                                $input->sendKeys($fechaFormateada);
                                                
                                                // Verificar que la fecha se escribió correctamente
                                                $fechaActual = $input->getAttribute('value');
                                                if ($fechaActual !== $fechaFormateada) {
                                                    error_log("Advertencia: La fecha en {$campo['name']} no coincide. Esperado: '$fechaFormateada', Actual: '$fechaActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } elseif ($campo['type'] === 'input') {
                                                // Manejar campos de input de texto
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='text']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Limpiar el campo y escribir el nuevo valor
                                                $input->clear();
                                                usleep(100000); // 100ms
                                                $input->sendKeys($valorCampo);
                                                
                                                // Verificar que el texto se escribió correctamente
                                                $textoActual = $input->getAttribute('value');
                                                if ($textoActual !== $valorCampo) {
                                                    error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                            } elseif ($campo['type'] === 'textarea') {
                                                // Manejar campo de textarea
                                                $textarea = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Limpiar el textarea y escribir el nuevo valor
                                                $textarea->clear();
                                                usleep(100000); // 100ms
                                                
                                                // Para textarea, podemos manejar texto multilínea
                                                $textoMultilinea = str_replace(['\\n', '\n'], "\n", $valorCampo);
                                                $textarea->sendKeys($textoMultilinea);
                                                
                                                // Verificar que el texto se escribió correctamente
                                                $textoActual = $textarea->getAttribute('value');
                                                if ($textoActual !== $textoMultilinea) {
                                                    error_log("Advertencia: El texto en textarea {$campo['name']} no coincide completamente\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$textarea]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$textarea]);
                                                
                                            } elseif ($campo['type'] === 'number') {
                                                // Manejar campos numéricos con validaciones específicas
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='number']")
                                                    )
                                                );
                                                
                                                // Hacer scroll para asegurar que el elemento esté visible
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000); // Pausa de 200ms
                                                
                                                // Validar que el valor sea numérico
                                                if (!is_numeric($valorCampo)) {
                                                    throw new Exception("El valor '$valorCampo' no es numérico para el campo {$campo['name']}");
                                                }
                                                
                                                $numeroFloat = floatval($valorCampo);
                                                
                                                // Validar rangos si están definidos
                                                if (isset($campo['min']) && $numeroFloat < $campo['min']) {
                                                    throw new Exception("El valor $numeroFloat es menor que el mínimo permitido ({$campo['min']}) para el campo {$campo['name']}");
                                                }
                                                
                                                if (isset($campo['max']) && $numeroFloat > $campo['max']) {
                                                    throw new Exception("El valor $numeroFloat es mayor que el máximo permitido ({$campo['max']}) para el campo {$campo['name']}");
                                                }
                                                
                                                // Limpiar el campo y escribir el nuevo valor
                                                $input->clear();
                                                usleep(100000); // 100ms
                                                $input->sendKeys($valorCampo);
                                                
                                                // Verificar que el número se escribió correctamente
                                                $numeroActual = $input->getAttribute('value');
                                                if ($numeroActual !== $valorCampo) {
                                                    error_log("Advertencia: El número en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$numeroActual'\n", 3, 'errores_bot.log');
                                                }
                                                
                                                // Disparar eventos para validaciones JavaScript
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                                
                                                // Validación adicional para campos con keypress restrictions
                                                if ($campo['name'] === 'Porcentaje') {
                                                    // Verificar que el valor esté dentro del rango después de la entrada
                                                    usleep(200000); // Pausa para que se procesen las validaciones JavaScript
                                                    
                                                    $valorFinal = $input->getAttribute('value');
                                                    if (empty($valorFinal) || floatval($valorFinal) !== $numeroFloat) {
                                                        error_log("Advertencia: El campo Porcentaje puede haber sido filtrado por validaciones JavaScript. Valor final: '$valorFinal'\n", 3, 'errores_bot.log');
                                                    }
                                                }
                                            }
                                            
                                            // Pausa breve entre campos para evitar problemas de timing
                                            usleep(300000); // 300ms
                                            
                                            error_log("Campo {$campo['name']} procesado exitosamente\n", 3, 'proceso_bot.log');
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error procesando campo {$campo['name']} (tipo: {$campo['type']}): " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // Tomar screenshot para debugging
                                            try {
                                                $driver->takeScreenshot('error_campo_' . $campo['name'] . '_' . date('Y-m-d_H-i-s') . '.png');
                                            } catch (Exception $screenshotError) {
                                                error_log("No se pudo tomar screenshot: " . $screenshotError->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }
                                    }

                                    // Verificar el resultado del procesamiento
                                    if ($procesamientoExitoso) {
                                        error_log("Todos los campos del formulario se procesaron exitosamente\n", 3, 'proceso_bot.log');
                                        
                                        // Opcional: Validar que todos los campos requeridos tienen valores
                                        try {
                                            $camposRequeridos = ['FechaInicio', 'FechaFin', 'PlanoPredio', 'SoportePQR', 'Porcentaje'];
                                            foreach ($camposRequeridos as $campoRequerido) {
                                                $elemento = $driver->findElement(WebDriverBy::xpath("//input[@name='$campoRequerido'] | //textarea[@name='$campoRequerido']"));
                                                $valor = $elemento->getAttribute('value');
                                                
                                                if (empty($valor)) {
                                                    error_log("Advertencia: El campo requerido '$campoRequerido' está vacío\n", 3, 'errores_bot.log');
                                                }
                                            }
                                        } catch (Exception $e) {
                                            error_log("Error verificando campos requeridos: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else {
                                        error_log("Hubo errores al procesar algunos campos del formulario. Revisar errores_bot.log para detalles\n", 3, 'proceso_bot.log');
                                    }

                                                                        // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "FALLA TECNICA") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }
                                    
                                } else if ($valor === "MI ETB") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }
                                    
                                } else if ($valor === "MODIFICACION ADICIONAL CATEGORIA") {
                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[17]);

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );

                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000);
                                    $enlace->click();
                                    usleep(500000); 

                                    // PASO CRÍTICO: Abrir el dropdown primero
                                    try {
                                        // Buscar el botón del dropdown con diferentes selectores
                                        $dropdownButton = null;
                                        $selectoresDropdown = [
                                            "//button[contains(@class, 'btn dropdown-toggle btn-light show')]",
                                            "//button[@data-bs-toggle='dropdown']",
                                            "//button[contains(@class, 'dropdown-toggle')]",
                                            "//div[contains(@class, 'dropdown')]//button",
                                            "//button[@role='combobox']",
                                            "//div[@role='button' and contains(@class, 'select')]"
                                        ];
                                        
                                        foreach ($selectoresDropdown as $selector) {
                                            try {
                                                $dropdownButton = $driver->wait(3)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath($selector)
                                                    )
                                                );
                                                error_log("Dropdown encontrado con selector: $selector\n", 3, 'errores_bot.log');
                                                break;
                                            } catch (Exception $e) {
                                                continue;
                                            }
                                        }
                                        
                                        if ($dropdownButton) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$dropdownButton]);
                                            usleep(300000);
                                            $dropdownButton->click();
                                            usleep(800000); // Esperar a que se abra el dropdown
                                            error_log("Dropdown abierto exitosamente\n", 3, 'errores_bot.log');
                                        } else {
                                            error_log("No se pudo encontrar el botón del dropdown\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } catch (Exception $e) {
                                        error_log("Error abriendo dropdown: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    // Seleccionar la opción específica basada en el valor del CSV
                                    try {
                                        $opcionSeleccionada = false;
                                        
                                        // Lógica condicional para seleccionar opciones específicas
                                        if ($valor_2 === "LOCAL EXCLUSIVO") {
                                            $opcion = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath("//a[@role='option' and @id='bs-select-1-0' and contains(.,'LOCAL EXCLUSIVO')]")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                            usleep(300000);
                                            $opcion->click();
                                            $opcionSeleccionada = true;
                                            
                                        } else if ($valor_2 === "LOCAL EXCLUSIVO +LDM") {
                                            $opcion = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath("//a[@role='option' and @id='bs-select-1-1' and contains(.,'LOCAL EXCLUSIVO +LDM')]")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                            usleep(300000);
                                            $opcion->click();
                                            $opcionSeleccionada = true;
                                            
                                        } else if ($valor_2 === "LOCAL EXCLUSIVO +LDM + MOVILES") {
                                            $opcion = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath("//a[@role='option' and @id='bs-select-1-2' and contains(.,'LOCAL EXCLUSIVO +LDM + MOVILES')]")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                            usleep(300000);
                                            $opcion->click();
                                            $opcionSeleccionada = true;
                                            
                                        } else if ($valor_2 === "LOCAL EXCLUSIVO +LDM + LDI + MOVILES") {
                                            $opcion = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath("//a[@role='option' and @id='bs-select-1-3' and contains(.,'LOCAL EXCLUSIVO +LDM + LDI + MOVILES')]")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                            usleep(300000);
                                            $opcion->click();
                                            $opcionSeleccionada = true;
                                            
                                        } else if ($valor_2 === "LOCAL EXCLUSIVO +LDM + LDI + MOVILES + 01901 + CODIGO SECRETO") {
                                            $opcion = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath("//a[@role='option' and @id='bs-select-1-4' and contains(.,'LOCAL EXCLUSIVO +LDM + LDI + MOVILES + 01901 + CODIGO SECRETO')]")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                            usleep(300000);
                                            $opcion->click();
                                            $opcionSeleccionada = true;
                                        }
                                        
                                        // Si no coincide con ninguna opción específica, buscar por texto
                                        if (!$opcionSeleccionada) {
                                            $valorEscapado = json_encode($valor_2);
                                            $xpath = "//a[@role='option' and contains(text(), $valorEscapado)]";
                                            
                                            $opcion = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                            usleep(300000);
                                            $opcion->click();
                                        }
                                        
                                        usleep(500000); // Esperar después de la selección
                                        error_log("Opción seleccionada exitosamente: $valor_2\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error seleccionando opción del dropdown: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // Intentar selección alternativa por índice si falla la selección por texto
                                        try {
                                            $opcionesAlternativas = [
                                                "//ul[@class='dropdown-menu inner show']//li[1]//a",
                                                "//ul[@class='dropdown-menu inner show']//li[2]//a",
                                                "//ul[@class='dropdown-menu inner show']//li[3]//a",
                                                "//ul[@class='dropdown-menu inner show']//li[4]//a",
                                                "//ul[@class='dropdown-menu inner show']//li[5]//a"
                                            ];
                                            
                                            foreach ($opcionesAlternativas as $index => $selector) {
                                                try {
                                                    $opcion = $driver->findElement(WebDriverBy::xpath($selector));
                                                    $textoOpcion = $opcion->getText();
                                                    
                                                    if (stripos($textoOpcion, $valor_2) !== false) {
                                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                                        usleep(300000);
                                                        $opcion->click();
                                                        error_log("Opción encontrada por método alternativo: $textoOpcion\n", 3, 'errores_bot.log');
                                                        break;
                                                    }
                                                } catch (Exception $e2) {
                                                    continue;
                                                }
                                            }
                                        } catch (Exception $e2) {
                                            error_log("Error en selección alternativa: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // Cerrar dropdown si queda abierto
                                    try {
                                        $driver->executeScript("
                                            var dropdowns = document.querySelectorAll('.dropdown-menu.show');
                                            dropdowns.forEach(function(dropdown) {
                                                dropdown.classList.remove('show');
                                            });
                                        ");
                                    } catch (Exception $e) {
                                        // Ignorar errores al cerrar dropdown
                                    }

                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "ORDEN DE RETOMA EQUIPOS") { //CHECKED 
                                    
                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "RECONEXION POR PAGO") { //CHECKED 
                                    
                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "RECONEXION VOLUNTARIA") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "RETENCION") {

                                    $valor = trim($datos[12]);
                                    $planOfrecido = trim($datos[0]);

                                    try {
                                        // Buscar y completar el campo PlanOfrecido
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='PlanOfrecido']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($planOfrecido);
                                        
                                        error_log("Campo PlanOfrecido completado: $planOfrecido\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo PlanOfrecido: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // Método alternativo con JavaScript
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"PlanOfrecido\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$planOfrecido]);
                                    }
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "RETIRO DE BUNDLE AUXILIAR") { //CHECKED 
                                    
                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[17]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000);
                                    $enlace->click();
                                    usleep(500000); 

                                    // PASO CRÍTICO: Abrir el dropdown primero
                                    try {
                                        // Buscar el botón del dropdown (puede tener diferentes selectores)
                                        $dropdownButton = null;
                                        $selectoresDropdown = [
                                            "//button[contains(@class, 'dropdown-toggle')]",
                                            "//div[contains(@class, 'dropdown')]//button",
                                            "//select[contains(@name, 'bundle') or contains(@name, 'Bundle')]",
                                            "//div[@role='button' and contains(@class, 'select')]"
                                        ];
                                        
                                        foreach ($selectoresDropdown as $selector) {
                                            try {
                                                $dropdownButton = $driver->wait(3)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath($selector)
                                                    )
                                                );
                                                error_log("Dropdown encontrado con selector: $selector\n", 3, 'errores_bot.log');
                                                break;
                                            } catch (Exception $e) {
                                                continue;
                                            }
                                        }
                                        
                                        if ($dropdownButton) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$dropdownButton]);
                                            usleep(300000);
                                            $dropdownButton->click();
                                            usleep(800000); // Esperar a que se abra el dropdown
                                            error_log("Dropdown abierto exitosamente\n", 3, 'errores_bot.log');
                                        } else {
                                            error_log("No se pudo encontrar el botón del dropdown\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } catch (Exception $e) {
                                        error_log("Error abriendo dropdown: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    // Ahora seleccionar la opción específica
                                    if ($valor_2 === "NUEVO DIRECTV FULL") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'NUEVO DIRECTV FULL')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "NUEVO DIRECTV FLEX") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'NUEVO DIRECTV FLEX')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else if ($valor_2 === "NUEVO DIRECTV BASICO") {
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//a[@role='option' and contains(.,'NUEVO DIRECTV BASICO')]")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                        
                                    } else {
                                        // Si no coincide con ninguna opción específica, buscar por texto
                                        $valorEscapado = json_encode($valor_2);
                                        $xpath = "//a[@role='option' and contains(text(), $valorEscapado)]";
                                        
                                        $opcion = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$opcion]);
                                        usleep(300000);
                                        $opcion->click();
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "RETIRO DE INCREMENTO") { //CHECKED 

                                    $valor = trim($datos[12]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "RETIRO DE SVAS") { //CHECKED 
                                    
                                    $valor = trim($datos[12]);
                                    $valor_2 = trim($datos[16]);
                                    $seriaDeco = trim($datos[0]);
                                    
                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    if ($valor_2 === "DECO") {
                                        // Seleccionar checkbox DECO
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='DECO']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "WIFI PLUS") {
                                        // Seleccionar checkbox WIFI PLUS
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='WIFI PLUS']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "SOLUCION AP MESH 2") {
                                        // Seleccionar checkbox SOLUCION AP MESH 2
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SOLUCION AP MESH 2']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "SOLUCION AP MESH 3") {
                                        // Seleccionar checkbox SOLUCION AP MESH 3
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SOLUCION AP MESH 3']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "AP MESH ADICIONAL") {
                                        // Seleccionar checkbox AP MESH ADICIONAL
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='AP MESH ADICIONAL']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "HOTPACK") {
                                        // Seleccionar checkbox HOTPACK
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='HOTPACK']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "PUNTO CABLEADO") {
                                        // Seleccionar checkbox PUNTO CABLEADO
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='PUNTO CABLEADO']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "SVA MAS VELOCIDAD") {
                                        // Seleccionar checkbox SVA MAS VELOCIDAD
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SVA MAS VELOCIDAD']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "HBO PACK") {
                                        // Seleccionar checkbox HBO PACK
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='HBO PACK']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "WIN SPORT SD/HD/DTV") {
                                        // Seleccionar checkbox WIN SPORT SD/HD/DTV
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='WIN SPORT SD/HD/DTV']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else {
                                        // Si no coincide con ninguna opción específica, buscar por valor_2
                                        $valorEscapado = json_encode($valor_2);
                                        $xpath = "//input[@type='checkbox' and @value=$valorEscapado]";
                                        
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        if (!$checkbox->isSelected()) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000); // 300ms
                                            $checkbox->click();
                                        }
                                    }

                                    try {
                                        // Buscar y completar el campo SeriaDeco
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='SeriaDeco']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($seriaDeco);
                                        
                                        error_log("Campo SeriaDeco completado: $seriaDeco\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo SeriaDeco: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // Método alternativo con JavaScript
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"SeriaDeco\"]') || 
                                                    document.querySelector('input[id=\"valor\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$seriaDeco]);
                                    }

                                    usleep(300000);

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "SERVICIOS SUPLEMENTARIOS") {
                                    
                                    $valor = trim($datos[12]);
                                    $serviciosSuplemantarios = trim($datos[0]);

                                    try {
                                        // Buscar y completar el campo ServiciosSuplemantarios
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='ServiciosSuplemantarios']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($serviciosSuplemantarios);
                                        
                                        error_log("Campo ServiciosSuplemantarios completado: $serviciosSuplemantarios\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo ServiciosSuplemantarios: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // Método alternativo con JavaScript
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"ServiciosSuplemantarios\"]') || 
                                                    document.querySelector('input[id=\"DireccionActual\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$serviciosSuplemantarios]);
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "SINCRONIZAR ACTIVOS") {
                                    
                                    $planOfrecido = trim($fila[0]); // Cambia el índice
                                    $direccionActual = trim($fila[1]); // Cambia el índice  
                                    $direccionDestino = trim($fila[2]); // Cambia el índice

                                    try {
                                        // Campo PlanOfrecido
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='PlanOfrecido']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($planOfrecido);
                                        
                                        error_log("Campo PlanOfrecido completado: $planOfrecido\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo PlanOfrecido: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"PlanOfrecido\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$planOfrecido]);
                                    }

                                    try {
                                        // Campo DireccionActual
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='DireccionActual']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($direccionActual);
                                        
                                        error_log("Campo DireccionActual completado: $direccionActual\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo DireccionActual: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"DireccionActual\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$direccionActual]);
                                    }

                                    try {
                                        // Campo DireccionDestino
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='DireccionDestino']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($direccionDestino);
                                        
                                        error_log("Campo DireccionDestino completado: $direccionDestino\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo DireccionDestino: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"DireccionDestino\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$direccionDestino]);
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "SINCRONIZAR DIRECCION") {
                                    
                                    $valor = trim($datos[12]);
                                    $direccionActual = trim($fila[0]); // Cambia el índice
                                    $direccionDestino = trim($fila[1]); // Cambia el índice

                                    try {
                                        // Campo DireccionActual
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='DireccionActual']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($direccionActual);
                                        
                                        error_log("Campo DireccionActual completado: $direccionActual\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo DireccionActual: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"DireccionActual\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$direccionActual]);
                                    }

                                    try {
                                        // Campo DireccionDestino
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='DireccionDestino']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($direccionDestino);
                                        
                                        error_log("Campo DireccionDestino completado: $direccionDestino\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo DireccionDestino: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"DireccionDestino\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$direccionDestino]);
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "SUSPENSION VOLUNTARIA") {
                                    
                                    $valor = trim($datos[12]);
                                    $fechaConstitucion = trim($fila[0]); // Cambia el índice
                                    $fechaFin = trim($fila[1]); // Cambia el índice

                                    try {
                                        // Campo FechaConstitucion
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='FechaConstitucion']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($fechaConstitucion);
                                        
                                        error_log("Campo FechaConstitucion completado: $fechaConstitucion\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo FechaConstitucion: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"FechaConstitucion\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$fechaConstitucion]);
                                    }

                                    try {
                                        // Campo FechaFin
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='FechaFin']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($fechaFin);
                                        
                                        error_log("Campo FechaFin completado: $fechaFin\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo FechaFin: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"FechaFin\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$fechaFin]);
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "TRASLADO") {
                                    
                                    $valor = trim($datos[12]);
                                    $fechaConstitucion = trim($fila[0]); // Cambia el índice
                                    $fechaFin = trim($fila[1]); // Cambia el índice

                                    try {
                                        // Campo FechaConstitucion
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='FechaConstitucion']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($fechaConstitucion);
                                        
                                        error_log("Campo FechaConstitucion completado: $fechaConstitucion\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo FechaConstitucion: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"FechaConstitucion\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$fechaConstitucion]);
                                    }

                                    try {
                                        // Campo FechaFin
                                        $campo = $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@name='FechaFin']")
                                            )
                                        );
                                        
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                        usleep(200000);
                                        
                                        $campo->clear();
                                        $campo->sendKeys($fechaFin);
                                        
                                        error_log("Campo FechaFin completado: $fechaFin\n", 3, 'errores_bot.log');
                                        
                                    } catch (Exception $e) {
                                        error_log("Error en campo FechaFin: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        $driver->executeScript("
                                            var campo = document.querySelector('input[name=\"FechaFin\"]');
                                            if (campo) {
                                                campo.value = arguments[0];
                                                campo.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        ", [$fechaFin]);
                                    }

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                    
                                    $enlace = $driver->wait(8)->until(
                                        WebDriverExpectedCondition::elementToBeClickable(
                                            WebDriverBy::xpath($xpath)
                                        )
                                    );
                                    
                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                    usleep(300000); // 300ms
                                    $enlace->click();
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 13],
                                        ['name' => 'SolucionRequerida', 'index' => 14],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else {
                                    $procesamientoExitoso = false;
                                    error_log("Error procesando trámite '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                }
                                
                            } catch (Exception $e) {
                                $procesamientoExitoso = false;
                                error_log("Error procesando trámite '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                            }
                        }

                        // Log del resultado final
                        if ($procesamientoExitoso) {
                            error_log("Procesamiento completado exitosamente\n", 3, 'errores_bot.log');
                        } else {
                            error_log("Procesamiento completado con errores\n", 3, 'errores_bot.log');
                        }

                        $procesamientoExitoso = true;

                        // Guardar
                        $botonGuardar_2 = $driver->wait(8)->until( // Reducido de 20 a 12
                            WebDriverExpectedCondition::elementToBeClickable(
                                WebDriverBy::xpath("//button[normalize-space()='Guardar']")
                            )
                        );
                        
                        try {
                            $botonGuardar_2->click();
                        } catch (Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                            $driver->executeScript("arguments[0].click();", [$botonGuardar_2]);
                        }

                        // Navegar a DATOS CLIENTE
                        $spanDatosCliente = (new WebDriverWait($driver, 8))->until(
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
                $driver->quit();
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