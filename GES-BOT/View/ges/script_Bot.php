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

                                $valor = strtoupper(trim($datos[12]));
                                
                                if ($valor === "ADECUACIONES") {
                                    
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
                                    
                                } else if ($valor === "ADICION DE BUNDLE AUXILIAR") {

                                    $valor_2 = trim($datos[13]);
                                    
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
                                        
                                    } else if ($valor_2 === "PARAMOUNT +") {
                                        // Seleccionar checkbox PARAMOUNT
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::id("customCheckBox1")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }
                                        
                                    } else if ($valor_2 === "UNIVERSAL +") {
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
                                        $valorEscapado = json_encode($valor_2);
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
                                        ['name' => 'ErrorPresentado', 'index' => 14],
                                        ['name' => 'SolucionRequerida', 'index' => 15],
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
                                    
                                } else if ($valor === "ADICION DE SVAS") {

                                    $valor_2 = trim($datos[13]);
                                    
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
                                        ['name' => 'ErrorPresentado', 'index' => 14],
                                        ['name' => 'SolucionRequerida', 'index' => 15],
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
                                    
                                } else if ($valor === "CAMBIO DE BUNDLE AUXILIAR") {

                                    $valor_2 = trim($datos[13]);

                                    // Escapar valor para XPath seguroMore actions
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
                                        ['name' => 'ErrorPresentado', 'index' => 15],
                                        ['name' => 'SolucionRequerida', 'index' => 16],
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
                                    
                                } else if ($valor === "CAMBIO DE ESTRATO") {

                                    // PRIMERO: Hacer clic en el enlace para habilitar los campos
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

                                    usleep(500000); // Esperar a que los campos se carguen

                                    // SEGUNDO: Ahora llenar los campos de input (que ya están disponibles)
                                    $camposInput = [
                                        ['name' => 'DireccionActual', 'index' => 13],
                                        ['name' => 'DireccionDestino', 'index' => 14],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000);
                                            
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // TERCERO: Llenar los textareas
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
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
                                    
                                } else if ($valor === "CAMBIO DE NUMERO") {
                                    
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
                                    
                                } else if ($valor === "CAMBIO DE PLAN") {

                                    // PRIMERO: Hacer clic en el enlace para habilitar los campos
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

                                    usleep(500000); // Esperar a que los campos se carguen

                                    // SEGUNDO: Ahora llenar los campos de input (que ya están disponibles)
                                    $camposInput = [
                                        ['name' => 'DireccionDestino', 'index' => 14],
                                        ['name' => 'PlanOfrecido', 'index' => 15],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000);
                                            
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // TERCERO: Llenar los textareas
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 17],
                                        ['name' => 'SolucionRequerida', 'index' => 18],
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
                                    
                                } else if ($valor === "CAMBIO DE TECNOLOGIA") {

                                    // Primero hacer clic en el enlace para que aparezcan todos los campos
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

                                    usleep(500000); // Esperar a que la página se actualice

                                    // AHORA procesar los campos de input (después del clic)
                                    $camposInput = [
                                        ['name' => 'DireccionActual', 'index' => 13],
                                        ['name' => 'DireccionDestino', 'index' => 14],
                                        ['name' => 'PlanOfrecido', 'index' => 15],
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

                                    // Procesar los campos textarea
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
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

                                    // PASO 2: Esperar a que la página se actualice completamente
                                    usleep(1000000); // Aumentar a 1 segundo para asegurar que todo se carge

                                    // PASO 3: AHORA procesar los campos de input (después del clic)
                                    $camposInput = [
                                        ['name' => 'DireccionDestino', 'index' => 14],
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }
                                        
                                        try {
                                            // Esperar a que el input esté presente después del clic
                                            $input = $driver->wait(10)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Hacer scroll para asegurar que el elemento esté visible
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(300000); // 300ms
                                            
                                            // Limpiar el campo y escribir el nuevo valor
                                            $input->clear();
                                            usleep(100000); // Pequeña pausa después de limpiar
                                            
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
                                            
                                            usleep(200000); // Pausa después de cada campo
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // PASO 4: Procesar los campos textarea
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(200000);
                                            
                                            $textarea->clear();
                                            usleep(100000);
                                            
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                            usleep(200000); // Pausa después de cada campo
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3); 
                                    
                                } else if ($valor === "CANCELACION VOLUNTARIA") {

                                    
                                    $valor_2 = trim($datos[13]);
    
                                    // PASO 1: Hacer click en "CANCELACION VOLUNTARIA"
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
                                    
                                    usleep(1000000); // Esperar 1 segundo para que se abra el submenu
                                    
                                    // PASO 2: Hacer clic en el menú desplegable para abrirlo
                                    try {
                                        $menuDropdown = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::cssSelector("button.dropdown-toggle, .bootstrap-select button, [data-toggle='dropdown']")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$menuDropdown]);
                                        usleep(300000);
                                        $menuDropdown->click();
                                        usleep(500000); // Esperar que se abra el menú
                                        error_log("Menú desplegable abierto\n", 3, 'proceso_bot.log');
                                    } catch (Exception $e) {
                                        error_log("Error abriendo menú desplegable: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                    
                                    // PASO 3: Seleccionar la opción del submenu
                                    error_log("Intentando seleccionar opción del submenu: '$valor_2'\n", 3, 'proceso_bot.log');
                                    
                                    $opcionSeleccionada = false;
                                    
                                    if ($valor_2 === "MOTIVO ECONOMICO") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-0")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opición 'MOTIVO ECONOMICO' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando MOTIVO ECONOMICO: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "FALLA DEL SERVICIO") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-1")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'FALLA DEL SERVICIO' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando FALLA DEL SERVICIO: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "MEJOR OFERTA DE LA COMPETENCIA") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-2")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'MEJOR OFERTA DE LA COMPETENCIA' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando MEJOR OFERTA DE LA COMPETENCIA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "TRASLADO SIN COBERTURA") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-3")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'TRASLADO SIN COBERTURA' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando TRASLADO SIN COBERTURA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "VIAJE FUERA DE LA CIUDAD") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-4")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'VIAJE FUERA DE LA CIUDAD' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando VIAJE FUERA DE LA CIUDAD: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "INCONFORMIDAD CON LA FACTURA") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-5")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'INCONFORMIDAD CON LA FACTURA' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando INCONFORMIDAD CON LA FACTURA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "MOTIVOS PERSONALES") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-6")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'MOTIVOS PERSONALES' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando MOTIVOS PERSONALES: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "INCONFORMIDAD CON EL SERVICIO") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-7")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'INCONFORMIDAD CON EL SERVICIO' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando INCONFORMIDAD CON EL SERVICIO: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else if ($valor_2 === "TITULAR FALLECIDO") {
                                        try {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-8")
                                                )
                                            );
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Opción 'TITULAR FALLECIDO' seleccionada\n", 3, 'proceso_bot.log');
                                            }
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error seleccionando TITULAR FALLECIDO: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else {
                                        // Fallback para opciones no listadas
                                        try {
                                            error_log("Usando fallback para opción: '$valor_2'\n", 3, 'proceso_bot.log');
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
                                            error_log("Opción '$valor_2' seleccionada con fallback\n", 3, 'proceso_bot.log');
                                            $opcionSeleccionada = true;
                                        } catch (Exception $e) {
                                            error_log("Error en fallback para '$valor_2': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }
                                    
                                    if (!$opcionSeleccionada) {
                                        error_log("ADVERTENCIA: No se pudo seleccionar ninguna opción para '$valor_2'\n", 3, 'errores_bot.log');
                                        $procesamientoExitoso = false;
                                    }

                                    usleep(1000000); // Esperar que se procese la selección
                                    
                                    // PASO 3: PROCESAR CAMPOS INPUT CON VALIDACIÓN MEJORADA
                                    $camposInput = [
                                        ['name' => 'SoportePQR', 'index' => 15],   
                                        ['name' => 'DireccionDestino', 'index' => 14],         
                                    ];

                                    foreach ($camposInput as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            error_log("Campo {$campo['name']} está vacío o no existe en índice {$campo['index']}\n", 3, 'proceso_bot.log');
                                            continue;
                                        }
                                        
                                        try {
                                            // Buscar el input field
                                            $input = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Scroll y esperar
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(300000);
                                            
                                            // Hacer click para enfocar el campo
                                            $input->click();
                                            usleep(200000);
                                            
                                            // Limpiar y escribir
                                            $input->clear();
                                            usleep(200000);
                                            
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            usleep(200000);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("ADVERTENCIA: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                                
                                                // Intentar escribir de nuevo
                                                $input->clear();
                                                usleep(200000);
                                                $input->sendKeys($texto);
                                                usleep(200000);
                                            }
                                            
                                            // Disparar eventos para validaciones JavaScript
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].blur();", [$input]); // Perder el foco para activar validaciones
                                            
                                            usleep(300000); // Esperar que se procesen las validaciones
                                            
                                            error_log("Campo {$campo['name']} completado exitosamente con: '$texto'\n", 3, 'proceso_bot.log');
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // Esperar adicional para asegurar que los campos se procesen
                                    usleep(800000);

                                    // PASO 4: PROCESAR CAMPOS TEXTAREA
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 21],
                                        ['name' => 'SolucionRequerida', 'index' => 22],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(300000);
                                            
                                            $textarea->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide\n", 3, 'errores_bot.log');
                                            }
                                            
                                            error_log("Campo {$campo['name']} completado exitosamente\n", 3, 'proceso_bot.log');
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);

                                } else if ($valor === "CESION DE CONTRATO PERSONA NATURAL") {

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(300000); // 300ms
                                        $enlace->click();
                                        usleep(500000); // Espera para que cargue el contenido dependiente del clic
                                    } catch (Exception $e) {
                                        error_log("Error al hacer clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    // Ahora sí procesamos los campos del formulario
                                    $camposFormulario = [
                                        ['name' => 'NuevoTitularNombresApellidos', 'type' => 'input', 'index' => '7'],
                                        ['name' => 'NuevoTitularCorreoElectronico', 'type' => 'input', 'index' => '13'],
                                        ['name' => 'NuevoTitularNumeroDocumento', 'type' => 'number', 'index' => '5'],
                                        ['name' => 'NuevoTitularCelular', 'type' => 'number', 'index' => '8'],
                                        ['name' => 'NuevoTitularTipoDocumento', 'type' => 'select', 'index' => '4'],
                                    ];

                                    $procesamientoExitoso = true;

                                    foreach ($camposFormulario as $campo) {
                                        if (empty($campo['index']) || !isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }

                                        try {
                                            $valorCampo = trim($datos[$campo['index']]);

                                            if ($campo['type'] === 'input' || $campo['type'] === 'number') {
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                    )
                                                );
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                                usleep(200000);
                                                $input->clear();
                                                $input->sendKeys($valorCampo);
                                                $textoActual = $input->getAttribute('value');
                                                if ($textoActual !== $valorCampo) {
                                                    error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                                }
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            } elseif ($campo['type'] === 'select') {
                                                $selectElement = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath("//select[@name='{$campo['name']}']")
                                                    )
                                                );
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$selectElement]);
                                                usleep(200000);
                                                $selectElement->click();
                                                usleep(300000);
                                                $valorEscapado = json_encode($valorCampo);
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
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$selectElement]);
                                            }

                                            usleep(300000);
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error procesando campo {$campo['name']} (tipo: {$campo['type']}): " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 19],
                                        ['name' => 'SolucionRequerida', 'index' => 20],
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

                                    if ($procesamientoExitoso) {
                                        error_log("Todos los campos se procesaron exitosamente\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar algunos campos. Revisar errores_bot.log\n", 3, 'proceso_bot.log');
                                    }

                                    sleep(3);
                       
                                } else if ($valor === "CESION DE CONTRATO PERSONA JURIDICA") {

                                    // Hacer clic en el enlace ANTES de procesar los campos
                                    try {
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
                                        usleep(500000); // Tiempo para que carguen los campos dependientes
                                    } catch (Exception $e) {
                                        error_log("Error al hacer clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    $camposFormulario = [
                                        ['name' => 'NuevoTitularNIT', 'type' => 'input', 'index' => '14'],
                                        ['name' => 'NuevoTitularActividadEconomica', 'type' => 'input', 'index' => '15'],
                                        ['name' => 'NuevoTitularRLNombreEmpresa', 'type' => 'input', 'index' => '17'],
                                        ['name' => 'NuevoTitularRLNombresApellidos', 'type' => 'input', 'index' => '7'],
                                        ['name' => 'NuevoTitularRLCorreoElectronico', 'type' => 'input', 'index' => '13'],
                                        ['name' => 'NuevoTitularFechaConstitucion', 'type' => 'date', 'index' => '16'],
                                        ['name' => 'NuevoTitularRLFechaNacimiento', 'type' => 'date', 'index' => '18'],
                                        ['name' => 'NuevoTitularRLNumeroDocumento', 'type' => 'number', 'index' => '5'],
                                        ['name' => 'NuevoTitularRLContacto', 'type' => 'number', 'index' => '8'],
                                    ];

                                    $procesamientoExitoso = true;

                                    foreach ($camposFormulario as $campo) {
                                        if (empty($campo['index']) || !isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            error_log("Saltando campo {$campo['name']}: índice vacío o sin datos\n", 3, 'proceso_bot.log');
                                            continue;
                                        }

                                        try {
                                            $valorCampo = trim($datos[$campo['index']]);

                                            if ($campo['type'] === 'input') {
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}']")
                                                    )
                                                );
                                            } elseif ($campo['type'] === 'number') {
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='number']")
                                                    )
                                                );
                                                if (!is_numeric($valorCampo)) {
                                                    throw new Exception("El valor '$valorCampo' no es numérico para el campo {$campo['name']}");
                                                }
                                            } elseif ($campo['type'] === 'date') {
                                                $input = $driver->wait(10)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//input[@name='{$campo['name']}' and @type='date']")
                                                    )
                                                );
                                                if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valorCampo, $matches)) {
                                                    $valorCampo = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
                                                }
                                                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valorCampo)) {
                                                    throw new Exception("Formato de fecha inválido: '$valorCampo'");
                                                }
                                            } else {
                                                continue;
                                            }

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000);
                                            $input->clear();
                                            $input->sendKeys($valorCampo);
                                            $valorActual = $input->getAttribute('value');
                                            if ($valorActual !== $valorCampo) {
                                                error_log("Advertencia: El valor en {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$valorActual'\n", 3, 'errores_bot.log');
                                            }
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);

                                            usleep(300000);
                                            error_log("Campo {$campo['name']} procesado exitosamente con valor: '$valorCampo'\n", 3, 'proceso_bot.log');

                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error procesando campo {$campo['name']} (tipo: {$campo['type']}): " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            try {
                                                $driver->takeScreenshot('error_campo_' . $campo['name'] . '_' . date('Y-m-d_H-i-s') . '.png');
                                            } catch (Exception $screenshotError) {
                                                error_log("No se pudo tomar screenshot: " . $screenshotError->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 19],
                                        ['name' => 'SolucionRequerida', 'index' => 20],
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

                                    if ($procesamientoExitoso) {
                                        error_log("Todos los campos del formulario se procesaron exitosamente\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar algunos campos del formulario. Revisar errores_bot.log\n", 3, 'proceso_bot.log');
                                    }

                                    sleep(3);
                                } else if ($valor === "CONTROL FRAUDE") {

                                    $valor_2 = trim($datos[14]);

                                    try {
                                        // PASO 1: PROCESAR ENLACE CON VALOR usando los 3 MÉTODOS
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }

                                        error_log("Enlace '$valor' clickeado exitosamente\n", 3, 'proceso_bot.log');

                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error procesando enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                   
                                    try {
                                        $menuDropdown = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::cssSelector("button.dropdown-toggle, .bootstrap-select button, [data-toggle='dropdown']")
                                            )
                                        );
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$menuDropdown]);
                                        usleep(300000);
                                        $menuDropdown->click();
                                        usleep(500000); // Esperar que se abra el menú
                                        error_log("Menú desplegable abierto\n", 3, 'proceso_bot.log');
                                    } catch (Exception $e) {
                                        error_log("Error abriendo menú desplegable: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                    
                                    error_log("Intentando seleccionar opción del submenu: '$valor_2'\n", 3, 'proceso_bot.log');
                                    
                                    $opcionSeleccionada = false;

                                    try {
                                        // PASO 2: PROCESAR CHECKBOXES SEGÚN VALOR_2 (IDs corregidas)
                                        if ($valor_2 === "DESBLOQUEO ADMINISTRATIVO") {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-1")
                                                )
                                            );
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Checkbox DESBLOQUEO ADMINISTRATIVO seleccionado\n", 3, 'proceso_bot.log');
                                            }

                                        } else if ($valor_2 === "LEVANTAR RESTRICCION EN RECMA") {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-2")
                                                )
                                            );
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Checkbox LEVANTAR RESTRICCION EN RECMA seleccionado\n", 3, 'proceso_bot.log');
                                            }

                                        } else if ($valor_2 === "LEVANTAR BLOQUEO DOCUMENTO SUPLANTACION") {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-3")
                                                )
                                            );
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Checkbox LEVANTAR BLOQUEO DOCUMENTO SUPLANTACION seleccionado\n", 3, 'proceso_bot.log');
                                            }

                                        } else if ($valor_2 === "SUSPENSION CONTROL PREVENTIVO") {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-4")
                                                )
                                            );
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Checkbox SUSPENSION CONTROL PREVENTIVO seleccionado\n", 3, 'proceso_bot.log');
                                            }

                                        } else if ($valor_2 === "RECONEXION CONTROL PREVENTIVA") {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-5")
                                                )
                                            );
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Checkbox RECONEXION CONTROL PREVENTIVA seleccionado\n", 3, 'proceso_bot.log');
                                            }

                                        } else if ($valor_2 === "OTRO") {
                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::id("bs-select-1-6")
                                                )
                                            );
                                            if (!$checkbox->isSelected()) {
                                                $checkbox->click();
                                                error_log("Checkbox OTRO seleccionado\n", 3, 'proceso_bot.log');
                                            }

                                        } else {
                                            $valorEscapado = json_encode($valor_2);
                                            $xpath = "//span[contains(@class, 'text') and contains(text(), $valorEscapado)]/parent::a";

                                            $checkbox = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000); // 300ms
                                            $checkbox->click();
                                            error_log("Checkbox genérico '$valor_2' seleccionado\n", 3, 'proceso_bot.log');
                                        }

                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error procesando checkboxes: " . $e->getMessage() . "\n", 3, 'errores_bot.log');

                                        try {
                                            $driver->takeScreenshot('error_checkbox_' . date('Y-m-d_H-i-s') . '.png');
                                        } catch (Exception $screenshotError) {
                                            error_log("No se pudo tomar screenshot: " . $screenshotError->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    // PASO 3: PROCESAR CAMPOS TEXTAREA
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 15],
                                        ['name' => 'SolucionRequerida', 'index' => 16],
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

                                            error_log("Campo {$campo['name']} completado exitosamente\n", 3, 'proceso_bot.log');

                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    if ($procesamientoExitoso) {
                                        error_log("Formulario procesado exitosamente - Enlace: '$valor', Checkbox: '$valor_2'\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar el formulario. Revisar errores_bot.log para detalles\n", 3, 'proceso_bot.log');
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "DESCUENTOS") {

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    $camposFormulario = [
                                        // Campos de fecha
                                        ['name' => 'FechaInicio', 'type' => 'date', 'index' => '16'],
                                        ['name' => 'FechaFin', 'type' => 'date', 'index' => '17'],
                                        
                                        // Campos de input de texto
                                        ['name' => 'PlanoPredio', 'type' => 'input', 'index' => '18'],
                                        ['name' => 'SoportePQR', 'type' => 'input', 'index' => '15'],
                                        
                                        // Campo de textarea
                                        ['name' => 'MesesAplicarDescuentoManual', 'type' => 'textarea', 'index' => '19'],
                                        
                                        // Campo numérico con restricciones específicas
                                        ['name' => 'Porcentaje', 'type' => 'number', 'index' => '20', 'min' => 0, 'max' => 100],
                                    ];

                                    // Variable para controlar si el procesamiento fue exitoso
                                    $procesamientoExitoso = true;

                                    // Procesar cada campo del primer formulario
                                    foreach ($camposFormulario as $campo) {
                                        error_log("--- Procesando campo: {$campo['name']} (índice: {$campo['index']}) ---\n", 3, 'proceso_bot.log');
                                        
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

                                    // Verificar el resultado del procesamiento del primer formulario
                                    if ($procesamientoExitoso) {
                                        error_log("Todos los campos del primer formulario se procesaron exitosamente\n", 3, 'proceso_bot.log');
                                        
                                        // Opcional: Validar que todos los campos requeridos tienen valores
                                        try {
                                            $camposRequeridos = ['FechaInicio', 'FechaFin', 'PlanoPredio', 'SoportePQR', 'Porcentaje'];
                                            foreach ($camposRequeridos as $campoRequerido) {
                                                $elemento = $driver->findElement(WebDriverBy::xpath("//input[@name='$campoRequerido'] | //textarea[@name='$campoRequerido']"));
                                                $valor = $elemento->getAttribute('value');
                                                
                                                if (empty($valor)) {
                                                    error_log("Advertencia: El campo requerido '$campoRequerido' está vacío\n", 3, 'errores_bot.log');
                                                } else {
                                                    error_log("Campo requerido '$campoRequerido' completado con valor: '$valor'\n", 3, 'proceso_bot.log');
                                                }
                                            }
                                        } catch (Exception $e) {
                                            error_log("Error verificando campos requeridos: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                        
                                    } else {
                                        error_log("Hubo errores al procesar algunos campos del primer formulario. Revisar errores_bot.log para detalles\n", 3, 'proceso_bot.log');
                                    }

                                    // PASO 3: Llenar los campos del SEGUNDO formulario (textareas)
                                    error_log("=== LLENANDO SEGUNDO FORMULARIO (TEXTAREAS) ===\n", 3, 'proceso_bot.log');

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 21],
                                        ['name' => 'SolucionRequerida', 'index' => 22],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        error_log("--- Procesando textarea: {$campo['name']} (índice: {$campo['index']}) ---\n", 3, 'proceso_bot.log');
                                        
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            error_log("Saltando textarea {$campo['name']}: sin datos en índice {$campo['index']}\n", 3, 'proceso_bot.log');
                                            continue;
                                        }
                                        
                                        try {
                                            $valorCampo = trim($datos[$campo['index']]);
                                            error_log("Procesando textarea {$campo['name']} con valor: '$valorCampo'\n", 3, 'proceso_bot.log');
                                            
                                            $textarea = $driver->wait(6)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );
                                            
                                            // Hacer scroll para asegurar que el elemento esté visible
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(200000); // 200ms
                                            
                                            $textarea->clear();
                                            $textarea->sendKeys($valorCampo);
                                            
                                            // Verificar que el texto se escribió correctamente
                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual !== $valorCampo) {
                                                error_log("Advertencia: El texto en textarea {$campo['name']} no coincide. Esperado: '$valorCampo', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            } else {
                                                error_log("Textarea {$campo['name']} completada exitosamente\n", 3, 'proceso_bot.log');
                                            }
                                            
                                            // Disparar eventos para validaciones JavaScript
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$textarea]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$textarea]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // Tomar screenshot para debugging
                                            try {
                                                $driver->takeScreenshot('error_textarea_' . $campo['name'] . '_' . date('Y-m-d_H-i-s') . '.png');
                                            } catch (Exception $screenshotError) {
                                                error_log("No se pudo tomar screenshot: " . $screenshotError->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }
                                    }

                                    error_log("=== PROCESAMIENTO COMPLETO ===\n", 3, 'proceso_bot.log');
                                    sleep(3);
                                    
                                } else if ($valor === "FALLA TECNICA") {

                                    try {
                                        // MÉTODO 1: Scroll directo en el body
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);
                                        
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                        
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        // Asegurar que el elemento esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        
                                        // Restaurar overflow hidden si es necesario
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");
                                        
                                        // Esperar que sea clickeable y hacer click
                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $enlace->click();
                                        usleep(500000);
                                        
                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // MÉTODO 2: Forzar visibilidad temporal del elemento
                                        try {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                            
                                            // Cambiar temporalmente el overflow y hacer visible
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);
                                            
                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            // Hacer click usando JavaScript directamente
                                            $driver->executeScript("arguments[0].click();", [$enlace]);
                                            
                                            // Restaurar estilos originales
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            
                                            usleep(500000);
                                            
                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // MÉTODO 3: Scroll de página completa y búsqueda
                                            try {
                                                // Hacer scroll de página múltiples veces
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }
                                                
                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                
                                                $enlace->click();
                                                usleep(500000);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para FALLA TECNICA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return; // Salir si no se puede hacer click
                                            }
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
                                    
                                } else if ($valor === "MI ETB") {
                                    
                                    try {
                                        // MÉTODO 1: Scroll directo en el body
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);
                                        
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                        
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        // Asegurar que el elemento esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        
                                        // Restaurar overflow hidden si es necesario
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");
                                        
                                        // Esperar que sea clickeable y hacer click
                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $enlace->click();
                                        usleep(500000);
                                        
                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // MÉTODO 2: Forzar visibilidad temporal del elemento
                                        try {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                            
                                            // Cambiar temporalmente el overflow y hacer visible
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);
                                            
                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            // Hacer click usando JavaScript directamente
                                            $driver->executeScript("arguments[0].click();", [$enlace]);
                                            
                                            // Restaurar estilos originales
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            
                                            usleep(500000);
                                            
                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // MÉTODO 3: Scroll de página completa y búsqueda
                                            try {
                                                // Hacer scroll de página múltiples veces
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }
                                                
                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                
                                                $enlace->click();
                                                usleep(500000);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para FALLA TECNICA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return; // Salir si no se puede hacer click
                                            }
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
                                    
                                } else if ($valor === "MODIFICACION ADICIONAL CATEGORIA") {

                                    $valor_2 = trim($datos[13]);

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

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
                                    
                                    usleep(500000); 

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 15],
                                        ['name' => 'SolucionRequerida', 'index' => 16],
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
                                    
                                } else if ($valor === "ORDEN DE RETOMA EQUIPOS") {
                                    
                                    try {
                                        // MÉTODO 1: Scroll directo en el body
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);
                                        
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                        
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        // Asegurar que el elemento esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        
                                        // Restaurar overflow hidden si es necesario
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");
                                        
                                        // Esperar que sea clickeable y hacer click
                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $enlace->click();
                                        usleep(500000);
                                        
                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // MÉTODO 2: Forzar visibilidad temporal del elemento
                                        try {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                            
                                            // Cambiar temporalmente el overflow y hacer visible
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);
                                            
                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            // Hacer click usando JavaScript directamente
                                            $driver->executeScript("arguments[0].click();", [$enlace]);
                                            
                                            // Restaurar estilos originales
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            
                                            usleep(500000);
                                            
                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // MÉTODO 3: Scroll de página completa y búsqueda
                                            try {
                                                // Hacer scroll de página múltiples veces
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }
                                                
                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                
                                                $enlace->click();
                                                usleep(500000);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para FALLA TECNICA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return; // Salir si no se puede hacer click
                                            }
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
                                    
                                } else if ($valor === "RECONEXION POR PAGO") {
                                    
                                    try {
                                        // MÉTODO 1: Scroll directo en el body
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);
                                        
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                        
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        // Asegurar que el elemento esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        
                                        // Restaurar overflow hidden si es necesario
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");
                                        
                                        // Esperar que sea clickeable y hacer click
                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $enlace->click();
                                        usleep(500000);
                                        
                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // MÉTODO 2: Forzar visibilidad temporal del elemento
                                        try {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                            
                                            // Cambiar temporalmente el overflow y hacer visible
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);
                                            
                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            // Hacer click usando JavaScript directamente
                                            $driver->executeScript("arguments[0].click();", [$enlace]);
                                            
                                            // Restaurar estilos originales
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            
                                            usleep(500000);
                                            
                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // MÉTODO 3: Scroll de página completa y búsqueda
                                            try {
                                                // Hacer scroll de página múltiples veces
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }
                                                
                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                
                                                $enlace->click();
                                                usleep(500000);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para FALLA TECNICA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return; // Salir si no se puede hacer click
                                            }
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
                                    
                                } else if ($valor === "RECONEXION VOLUNTARIA") {
                                    
                                    try {
                                        // MÉTODO 1: Scroll directo en el body
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);
                                        
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                        
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        // Asegurar que el elemento esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        
                                        // Restaurar overflow hidden si es necesario
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");
                                        
                                        // Esperar que sea clickeable y hacer click
                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $enlace->click();
                                        usleep(500000);
                                        
                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // MÉTODO 2: Forzar visibilidad temporal del elemento
                                        try {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                            
                                            // Cambiar temporalmente el overflow y hacer visible
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);
                                            
                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            // Hacer click usando JavaScript directamente
                                            $driver->executeScript("arguments[0].click();", [$enlace]);
                                            
                                            // Restaurar estilos originales
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            
                                            usleep(500000);
                                            
                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // MÉTODO 3: Scroll de página completa y búsqueda
                                            try {
                                                // Hacer scroll de página múltiples veces
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }
                                                
                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                
                                                $enlace->click();
                                                usleep(500000);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para FALLA TECNICA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return; // Salir si no se puede hacer click
                                            }
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
                                    
                                } else if ($valor === "RETENCION") {

                                   $planOfrecido = trim($datos[15]);

                                    // PASO 1: Primero hacer clic en el enlace para cargar todos los campos (con los 3 MÉTODOS)
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    // PASO 3: AHORA llenar el campo PlanOfrecido (después del clic)
                                    if (!empty($planOfrecido)) {
                                        try {
                                            $campo = $driver->wait(10)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//input[@name='PlanOfrecido']")
                                                )
                                            );

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath("//input[@name='PlanOfrecido']")
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                            usleep(300000);

                                            $campo->clear();
                                            usleep(200000);
                                            $campo->sendKeys($planOfrecido);

                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$campo]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$campo]);

                                            $valorActual = $campo->getAttribute('value');
                                            if ($valorActual === $planOfrecido) {
                                                error_log("Campo PlanOfrecido completado correctamente: $planOfrecido\n", 3, 'errores_bot.log');
                                            } else {
                                                error_log("Advertencia: PlanOfrecido no coincide. Esperado: '$planOfrecido', Actual: '$valorActual'\n", 3, 'errores_bot.log');
                                            }

                                        } catch (Exception $e) {
                                            error_log("Error en campo PlanOfrecido: " . $e->getMessage() . "\n", 3, 'errores_bot.log');

                                            try {
                                                $driver->executeScript("
                                                    var campo = document.querySelector('input[name=\"PlanOfrecido\"]');
                                                    if (campo) {
                                                        campo.value = arguments[0];
                                                        campo.dispatchEvent(new Event('input', { bubbles: true }));
                                                        campo.dispatchEvent(new Event('change', { bubbles: true }));
                                                    }
                                                ", [$planOfrecido]);
                                                error_log("PlanOfrecido completado usando JavaScript como respaldo\n", 3, 'errores_bot.log');
                                            } catch (Exception $jsError) {
                                                error_log("Error también en método JavaScript: " . $jsError->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }
                                    }

                                    // PASO 4: Procesar los campos textarea
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }

                                        try {
                                            $textarea = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(300000);

                                            $textarea->clear();
                                            usleep(200000);

                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);

                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual === $texto) {
                                                error_log("Campo {$campo['name']} completado correctamente\n", 3, 'errores_bot.log');
                                            } else {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }

                                            usleep(300000);

                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "RETIRO DE BUNDLE AUXILIAR") {

                                    $valor_2 = trim($datos[13]);

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    // MÉTODO 1: Scroll y clic normal
                                    try {
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);

                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );

                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );

                                        $enlace->click();
                                        usleep(500000);

                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');

                                        // MÉTODO 2: Forzar visibilidad
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].click();", [$enlace]);

                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            usleep(500000);

                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 3: Scroll repetido
                                            try {
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                $enlace->click();
                                                usleep(500000);

                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para enlace '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return;
                                            }
                                        }
                                    }

                                    try {
                                        $valorCheckbox = trim($valor_2);
                                        $valorEscapado = json_encode($valorCheckbox);
                                        $xpath = "//input[@type='checkbox' and @value=$valorEscapado]";

                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );

                                        if (!$checkbox->isSelected()) {
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$checkbox]);
                                            usleep(300000);
                                            $checkbox->click();
                                        }
                                    } catch (Exception $e) {
                                        error_log("No se pudo seleccionar checkbox con value='$valor_2': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 14],
                                        ['name' => 'SolucionRequerida', 'index' => 15],
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
                                    
                                } else if ($valor === "RETIRO DE INCREMENTO") {
                                    
                                    try {
                                        // MÉTODO 1: Scroll directo en el body
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);
                                        
                                        $valorEscapado = json_encode($valor);
                                        $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                        
                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        // Asegurar que el elemento esté visible
                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        
                                        // Restaurar overflow hidden si es necesario
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");
                                        
                                        // Esperar que sea clickeable y hacer click
                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );
                                        
                                        $enlace->click();
                                        usleep(500000);
                                        
                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        
                                        // MÉTODO 2: Forzar visibilidad temporal del elemento
                                        try {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";
                                            
                                            // Cambiar temporalmente el overflow y hacer visible
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);
                                            
                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );
                                            
                                            // Hacer click usando JavaScript directamente
                                            $driver->executeScript("arguments[0].click();", [$enlace]);
                                            
                                            // Restaurar estilos originales
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            
                                            usleep(500000);
                                            
                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            
                                            // MÉTODO 3: Scroll de página completa y búsqueda
                                            try {
                                                // Hacer scroll de página múltiples veces
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }
                                                
                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );
                                                
                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                
                                                $enlace->click();
                                                usleep(500000);
                                                
                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para FALLA TECNICA: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return; // Salir si no se puede hacer click
                                            }
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
                                    
                                } else if ($valor === "RETIRO DE SVAS") {

                                    $valor_2 = trim($datos[13]);

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    // MÉTODO 1: Scroll y clic normal
                                    try {
                                        $driver->executeScript("
                                            document.body.style.overflowY = 'auto';
                                            window.scrollBy(0, 300);
                                        ");
                                        usleep(800000);

                                        $enlace = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::presenceOfElementLocated(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );

                                        $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                        usleep(500000);
                                        $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                        $driver->wait(5)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath($xpath)
                                            )
                                        );

                                        $enlace->click();
                                        usleep(500000);

                                    } catch (Exception $e) {
                                        error_log("Método 1 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');

                                        // MÉTODO 2: Forzar visibilidad
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'visible';
                                                document.documentElement.style.overflowY = 'visible';
                                            ");
                                            usleep(500000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].click();", [$enlace]);

                                            $driver->executeScript("
                                                document.body.style.overflowY = 'hidden';
                                                document.documentElement.style.overflowY = '';
                                            ");
                                            usleep(500000);

                                        } catch (Exception $e) {
                                            error_log("Método 2 falló: " . $e->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 3: Scroll repetido
                                            try {
                                                for ($i = 0; $i < 3; $i++) {
                                                    $driver->executeScript("window.scrollBy(0, 200);");
                                                    usleep(300000);
                                                }

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                usleep(500000);
                                                $enlace->click();
                                                usleep(500000);

                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Todos los métodos fallaron para enlace '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                                return;
                                            }
                                        }
                                    }

                                    // El resto del script permanece exactamente igual
                                    if ($valor_2 === "DECO") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='DECO']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "WIFI PLUS") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='WIFI PLUS']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "SOLUCION AP MESH 2") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SOLUCION AP MESH 2']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "SOLUCION AP MESH 3") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SOLUCION AP MESH 3']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "AP MESH ADICIONAL") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='AP MESH ADICIONAL']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "HOTPACK") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='HOTPACK']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "PUNTO CABLEADO") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='PUNTO CABLEADO']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "SVA MAS VELOCIDAD") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='SVA MAS VELOCIDAD']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "HBO PACK") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='HBO PACK']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else if ($valor_2 === "WIN SPORT SD/HD/DTV") {
                                        $checkbox = $driver->wait(8)->until(
                                            WebDriverExpectedCondition::elementToBeClickable(
                                                WebDriverBy::xpath("//input[@value='WIN SPORT SD/HD/DTV']")
                                            )
                                        );
                                        if (!$checkbox->isSelected()) {
                                            $checkbox->click();
                                        }

                                    } else {
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
                                        ['name' => 'SerialDeco', 'index' => 14],
                                        ['name' => 'ErrorPresentado', 'index' => 15],
                                        ['name' => 'SolucionRequerida', 'index' => 16],
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
                                    
                                } else if ($valor === "SERVICIOS SUPLEMENTARIOS") {

                                    $serviciosSuplemantarios = trim($datos[14]);

                                    // Escapar valor para XPath seguro
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    // Ahora llenar el campo ServiciosSuplemantarios
                                    try {
                                        $campo = $driver->wait(6)->until(
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

                                    // Procesar los textareas
                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 15],
                                        ['name' => 'SolucionRequerida', 'index' => 16],
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
                                    
                                } else if ($valor === "SINCRONIZAR ACTIVOS") {

                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    usleep(1000000);

                                    $camposInput = [
                                        ['name' => 'PlanOfrecido', 'index' => 15],
                                        ['name' => 'DireccionActual', 'index' => 13],
                                        ['name' => 'DireccionDestino', 'index' => 14],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000);
                                            
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }

                                        try {
                                            $textarea = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(300000);

                                            $textarea->clear();
                                            usleep(200000);

                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);

                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual === $texto) {
                                                error_log("Campo {$campo['name']} completado correctamente\n", 3, 'errores_bot.log');
                                            } else {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }

                                            usleep(300000);

                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "SINCRONIZAR DIRECCION") {

                                    // PASO 1: Primero hacer clic en el enlace para cargar todos los campos (con los 3 MÉTODOS)
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    usleep(1000000);

                                    $camposInput = [
                                        ['name' => 'DireccionActual', 'index' => 13],
                                        ['name' => 'DireccionDestino', 'index' => 14],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000);
                                            
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }

                                        try {
                                            $textarea = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(300000);

                                            $textarea->clear();
                                            usleep(200000);

                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);

                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual === $texto) {
                                                error_log("Campo {$campo['name']} completado correctamente\n", 3, 'errores_bot.log');
                                            } else {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }

                                            usleep(300000);

                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "SUSPENSION VOLUNTARIA") {
                                    
                                    $fechaInicio = trim($datos[16]);
                                    $fechaFin = trim($datos[17]);

                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    try {
                                        // PRIMERO: hacer clic en el enlace
                                        if (!empty($valor)) {
                                            $valorEscapado = json_encode($valor);
                                            $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $enlace->click();

                                            usleep(800000); // Esperar que cargue todo
                                            error_log("Enlace '$valor' clickeado exitosamente\n", 3, 'proceso_bot.log');
                                        } else {
                                            error_log("Valor del enlace está vacío (índice 12)\n", 3, 'proceso_bot.log');
                                        }

                                        // DESPUÉS: procesar campos de fecha
                                        if (!empty($fechaInicio)) {
                                            try {
                                                $campo = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath("//input[@name='FechaConstitucion']")
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                                usleep(300000);
                                                $campo->click();
                                                usleep(200000);
                                                $campo->clear();
                                                usleep(200000);
                                                $campo->sendKeys($fechaInicio);

                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$campo]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$campo]);
                                                $driver->executeScript("arguments[0].blur();", [$campo]);

                                                usleep(300000);
                                                error_log("Campo FechaConstitucion completado: $fechaInicio\n", 3, 'proceso_bot.log');

                                            } catch (Exception $e) {
                                                error_log("Error en campo FechaConstitucion: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }

                                        if (!empty($fechaFin)) {
                                            try {
                                                $campo = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::elementToBeClickable(
                                                        WebDriverBy::xpath("//input[@name='FechaFin']")
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$campo]);
                                                usleep(300000);
                                                $campo->click();
                                                usleep(200000);
                                                $campo->clear();
                                                usleep(200000);
                                                $campo->sendKeys($fechaFin);

                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$campo]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$campo]);
                                                $driver->executeScript("arguments[0].blur();", [$campo]);

                                                usleep(300000);
                                                error_log("Campo FechaFin completado: $fechaFin\n", 3, 'proceso_bot.log');

                                            } catch (Exception $e) {
                                                error_log("Error en campo FechaFin: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }

                                        // FINALMENTE: procesar textareas
                                        $camposTextarea = [
                                            ['name' => 'ErrorPresentado', 'index' => 21],
                                            ['name' => 'SolucionRequerida', 'index' => 22],
                                        ];

                                        foreach ($camposTextarea as $campo) {
                                            if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                                error_log("Campo {$campo['name']} está vacío (índice {$campo['index']})\n", 3, 'proceso_bot.log');
                                                continue;
                                            }

                                            try {
                                                $textarea = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                                usleep(300000);
                                                $textarea->click();
                                                usleep(200000);
                                                $textarea->clear();
                                                usleep(200000);
                                                $texto = trim($datos[$campo['index']]);
                                                $textarea->sendKeys($texto);

                                                $textoActual = $textarea->getAttribute('value');
                                                if ($textoActual !== $texto) {
                                                    error_log("ADVERTENCIA: El texto en {$campo['name']} no coincide. Reintentando...\n", 3, 'errores_bot.log');
                                                    $textarea->clear();
                                                    usleep(200000);
                                                    $textarea->sendKeys($texto);
                                                }

                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$textarea]);
                                                $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$textarea]);
                                                $driver->executeScript("arguments[0].blur();", [$textarea]);

                                                usleep(300000);
                                                error_log("Campo {$campo['name']} completado exitosamente\n", 3, 'proceso_bot.log');

                                            } catch (Exception $e) {
                                                $procesamientoExitoso = false;
                                                error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                            }
                                        }

                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error general procesando formulario: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    if ($procesamientoExitoso) {
                                        error_log("Formulario procesado exitosamente - Fechas: '$fechaInicio' - '$fechaFin', Enlace: '$valor'\n", 3, 'proceso_bot.log');
                                    } else {
                                        error_log("Hubo errores al procesar el formulario. Revisar errores_bot.log\n", 3, 'proceso_bot.log');
                                    }

                                    sleep(3);
                                    
                                } else if ($valor === "TRASLADO") {
                                    
                                    $valorEscapado = json_encode($valor);
                                    $xpath = "//li/a[contains(text(), $valorEscapado)]";

                                    try {
                                        // MÉTODO 1
                                        try {
                                            $driver->executeScript("
                                                document.body.style.overflowY = 'auto';
                                                window.scrollBy(0, 300);
                                            ");
                                            usleep(800000);

                                            $enlace = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                            usleep(500000);
                                            $driver->executeScript("document.body.style.overflowY = 'hidden';");

                                            $driver->wait(5)->until(
                                                WebDriverExpectedCondition::elementToBeClickable(
                                                    WebDriverBy::xpath($xpath)
                                                )
                                            );

                                            $enlace->click();
                                            usleep(500000);

                                        } catch (Exception $e1) {
                                            error_log("Método 1 falló: " . $e1->getMessage() . "\n", 3, 'errores_bot.log');

                                            // MÉTODO 2
                                            try {
                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'visible';
                                                    document.documentElement.style.overflowY = 'visible';
                                                ");
                                                usleep(500000);

                                                $enlace = $driver->wait(8)->until(
                                                    WebDriverExpectedCondition::presenceOfElementLocated(
                                                        WebDriverBy::xpath($xpath)
                                                    )
                                                );

                                                $driver->executeScript("arguments[0].click();", [$enlace]);

                                                $driver->executeScript("
                                                    document.body.style.overflowY = 'hidden';
                                                    document.documentElement.style.overflowY = '';
                                                ");
                                                usleep(500000);

                                            } catch (Exception $e2) {
                                                error_log("Método 2 falló: " . $e2->getMessage() . "\n", 3, 'errores_bot.log');

                                                // MÉTODO 3
                                                try {
                                                    for ($i = 0; $i < 3; $i++) {
                                                        $driver->executeScript("window.scrollBy(0, 200);");
                                                        usleep(300000);
                                                    }

                                                    $enlace = $driver->wait(8)->until(
                                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                                            WebDriverBy::xpath($xpath)
                                                        )
                                                    );

                                                    $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$enlace]);
                                                    usleep(500000);
                                                    $enlace->click();
                                                    usleep(500000);

                                                } catch (Exception $e3) {
                                                    $procesamientoExitoso = false;
                                                    error_log("Todos los métodos fallaron para enlace: " . $e3->getMessage() . "\n", 3, 'errores_bot.log');
                                                    return;
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $procesamientoExitoso = false;
                                        error_log("Error haciendo clic en el enlace: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                    }

                                    usleep(1000000);

                                    $camposInput = [
                                        ['name' => 'PlanOfrecido', 'index' => 15],
                                        ['name' => 'DireccionActual', 'index' => 13],
                                        ['name' => 'DireccionDestino', 'index' => 14],
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
                                            
                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$input]);
                                            usleep(200000);
                                            
                                            $input->clear();
                                            $texto = trim($datos[$campo['index']]);
                                            $input->sendKeys($texto);
                                            
                                            $textoActual = $input->getAttribute('value');
                                            if ($textoActual !== $texto) {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }
                                            
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$input]);
                                            $driver->executeScript("arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", [$input]);
                                            
                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando input {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    $camposTextarea = [
                                        ['name' => 'ErrorPresentado', 'index' => 16],
                                        ['name' => 'SolucionRequerida', 'index' => 17],
                                    ];

                                    foreach ($camposTextarea as $campo) {
                                        if (!isset($datos[$campo['index']]) || empty(trim($datos[$campo['index']]))) {
                                            continue;
                                        }

                                        try {
                                            $textarea = $driver->wait(8)->until(
                                                WebDriverExpectedCondition::presenceOfElementLocated(
                                                    WebDriverBy::xpath("//textarea[@name='{$campo['name']}']")
                                                )
                                            );

                                            $driver->executeScript("arguments[0].scrollIntoView({block: 'center'});", [$textarea]);
                                            usleep(300000);

                                            $textarea->clear();
                                            usleep(200000);

                                            $texto = trim($datos[$campo['index']]);
                                            $textarea->sendKeys($texto);

                                            $textoActual = $textarea->getAttribute('value');
                                            if ($textoActual === $texto) {
                                                error_log("Campo {$campo['name']} completado correctamente\n", 3, 'errores_bot.log');
                                            } else {
                                                error_log("Advertencia: El texto en {$campo['name']} no coincide. Esperado: '$texto', Actual: '$textoActual'\n", 3, 'errores_bot.log');
                                            }

                                            usleep(300000);

                                        } catch (Exception $e) {
                                            $procesamientoExitoso = false;
                                            error_log("Error llenando textarea {$campo['name']}: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                        }
                                    }

                                    sleep(3);
                                    
                                } else {
                                }
                                
                            } catch (Exception $e) {
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

                error_log("Error final procesando archivos: " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                $driver->quit();
            }

            } catch (TimeoutException | NoSuchElementException $e) {

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