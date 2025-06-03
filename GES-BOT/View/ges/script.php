                        //ADECUACIONES Y CAMBIO DE NUMERO
                        
                        if (isset($datos[12]) && !empty(trim($datos[12]))) {
                            try {
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
                                
                                // Verificar que el clic fue exitoso esperando algún cambio
                                usleep(500000); // Esperar medio segundo para que se procese
                                
                            } catch (Exception $e) {
                                $procesamientoExitoso = false;
                                error_log("Error haciendo clic en opción '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
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

                        if (isset($datos[15]) && !empty(trim($datos[15]))) {
                            $nombreImagen = trim($datos[15]);
                            $rutaCompleta = "/ruta/completa/imagenes/" . $nombreImagen;
                            
                            // Validar que el archivo existe
                            if (!file_exists($rutaCompleta)) {
                                $procesamientoExitoso = false;
                                error_log("Archivo de imagen no encontrado: $rutaCompleta\n", 3, 'errores_bot.log');
                            } else {
                                try {
                                    // Entrar al iframe con espera explícita
                                    $iframe = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//iframe[contains(@src, 'B_User_archivo_Ok3.php')]")
                                        )
                                    );
                                    $driver->switchTo()->frame($iframe);
                                    
                                    // Ubicar el input file con espera
                                    $inputFile = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//input[@type='file']")
                                        )
                                    );
                                    
                                    // Enviar la imagen
                                    $inputFile->sendKeys($rutaCompleta);
                                    
                                    // Esperar un momento para que se procese la carga
                                    usleep(1000000); // 1 segundo
                                    
                                    error_log("Imagen subida exitosamente: $nombreImagen\n", 3, 'errores_bot.log');
                                    
                                } catch (Exception $e) {
                                    $procesamientoExitoso = false;
                                    error_log("Error subiendo imagen '$nombreImagen': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                } finally {
                                    // Siempre volver al contexto principal
                                    try {
                                        $driver->switchTo()->defaultContent();
                                    } catch (Exception $ex) {
                                        error_log("Error crítico: No se pudo volver al contexto principal: " . $ex->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                }
                            }
                        }


                        //ADICION DE BUNDLE AUXILIAR
                        
                        if (isset($datos[12]) && !empty(trim($datos[12]))) {
                            try {
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
                                
                                // Verificar que el clic fue exitoso esperando algún cambio
                                usleep(500000); // Esperar medio segundo para que se procese
                                
                            } catch (Exception $e) {
                                $procesamientoExitoso = false;
                                error_log("Error haciendo clic en opción '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
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

                        if (isset($datos[15]) && !empty(trim($datos[15]))) {
                            $nombreImagen = trim($datos[15]);
                            $rutaCompleta = "/ruta/completa/imagenes/" . $nombreImagen;
                            
                            // Validar que el archivo existe
                            if (!file_exists($rutaCompleta)) {
                                $procesamientoExitoso = false;
                                error_log("Archivo de imagen no encontrado: $rutaCompleta\n", 3, 'errores_bot.log');
                            } else {
                                try {
                                    // Entrar al iframe con espera explícita
                                    $iframe = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//iframe[contains(@src, 'B_User_archivo_Ok3.php')]")
                                        )
                                    );
                                    $driver->switchTo()->frame($iframe);
                                    
                                    // Ubicar el input file con espera
                                    $inputFile = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//input[@type='file']")
                                        )
                                    );
                                    
                                    // Enviar la imagen
                                    $inputFile->sendKeys($rutaCompleta);
                                    
                                    // Esperar un momento para que se procese la carga
                                    usleep(1000000); // 1 segundo
                                    
                                    error_log("Imagen subida exitosamente: $nombreImagen\n", 3, 'errores_bot.log');
                                    
                                } catch (Exception $e) {
                                    $procesamientoExitoso = false;
                                    error_log("Error subiendo imagen '$nombreImagen': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                } finally {
                                    // Siempre volver al contexto principal
                                    try {
                                        $driver->switchTo()->defaultContent();
                                    } catch (Exception $ex) {
                                        error_log("Error crítico: No se pudo volver al contexto principal: " . $ex->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                }
                            }
                        }


                        //ADICION DE SVA'S
                        
                        if (isset($datos[12]) && !empty(trim($datos[12]))) {
                            try {
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
                                
                                // Verificar que el clic fue exitoso esperando algún cambio
                                usleep(500000); // Esperar medio segundo para que se procese
                                
                            } catch (Exception $e) {
                                $procesamientoExitoso = false;
                                error_log("Error haciendo clic en opción '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
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

                        if (isset($datos[15]) && !empty(trim($datos[15]))) {
                            $nombreImagen = trim($datos[15]);
                            $rutaCompleta = "/ruta/completa/imagenes/" . $nombreImagen;
                            
                            // Validar que el archivo existe
                            if (!file_exists($rutaCompleta)) {
                                $procesamientoExitoso = false;
                                error_log("Archivo de imagen no encontrado: $rutaCompleta\n", 3, 'errores_bot.log');
                            } else {
                                try {
                                    // Entrar al iframe con espera explícita
                                    $iframe = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//iframe[contains(@src, 'B_User_archivo_Ok3.php')]")
                                        )
                                    );
                                    $driver->switchTo()->frame($iframe);
                                    
                                    // Ubicar el input file con espera
                                    $inputFile = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//input[@type='file']")
                                        )
                                    );
                                    
                                    // Enviar la imagen
                                    $inputFile->sendKeys($rutaCompleta);
                                    
                                    // Esperar un momento para que se procese la carga
                                    usleep(1000000); // 1 segundo
                                    
                                    error_log("Imagen subida exitosamente: $nombreImagen\n", 3, 'errores_bot.log');
                                    
                                } catch (Exception $e) {
                                    $procesamientoExitoso = false;
                                    error_log("Error subiendo imagen '$nombreImagen': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                } finally {
                                    // Siempre volver al contexto principal
                                    try {
                                        $driver->switchTo()->defaultContent();
                                    } catch (Exception $ex) {
                                        error_log("Error crítico: No se pudo volver al contexto principal: " . $ex->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                }
                            }
                        }


                        //CAMBIO DE BUNDLE AUXILIAR
                        
                        if (isset($datos[12]) && !empty(trim($datos[12]))) {
                            try {
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
                                
                                // Verificar que el clic fue exitoso esperando algún cambio
                                usleep(500000); // Esperar medio segundo para que se procese
                                
                            } catch (Exception $e) {
                                $procesamientoExitoso = false;
                                error_log("Error haciendo clic en opción '$valor': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
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

                        if (isset($datos[15]) && !empty(trim($datos[15]))) {
                            $nombreImagen = trim($datos[15]);
                            $rutaCompleta = "/ruta/completa/imagenes/" . $nombreImagen;
                            
                            // Validar que el archivo existe
                            if (!file_exists($rutaCompleta)) {
                                $procesamientoExitoso = false;
                                error_log("Archivo de imagen no encontrado: $rutaCompleta\n", 3, 'errores_bot.log');
                            } else {
                                try {
                                    // Entrar al iframe con espera explícita
                                    $iframe = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//iframe[contains(@src, 'B_User_archivo_Ok3.php')]")
                                        )
                                    );
                                    $driver->switchTo()->frame($iframe);
                                    
                                    // Ubicar el input file con espera
                                    $inputFile = $driver->wait(6)->until(
                                        WebDriverExpectedCondition::presenceOfElementLocated(
                                            WebDriverBy::xpath("//input[@type='file']")
                                        )
                                    );
                                    
                                    // Enviar la imagen
                                    $inputFile->sendKeys($rutaCompleta);
                                    
                                    // Esperar un momento para que se procese la carga
                                    usleep(1000000); // 1 segundo
                                    
                                    error_log("Imagen subida exitosamente: $nombreImagen\n", 3, 'errores_bot.log');
                                    
                                } catch (Exception $e) {
                                    $procesamientoExitoso = false;
                                    error_log("Error subiendo imagen '$nombreImagen': " . $e->getMessage() . "\n", 3, 'errores_bot.log');
                                } finally {
                                    // Siempre volver al contexto principal
                                    try {
                                        $driver->switchTo()->defaultContent();
                                    } catch (Exception $ex) {
                                        error_log("Error crítico: No se pudo volver al contexto principal: " . $ex->getMessage() . "\n", 3, 'errores_bot.log');
                                    }
                                }
                            }
                        }