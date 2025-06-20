<?php
// Iniciar la sesión al comienzo del script
session_start();

// Procesar el formulario de carga de CSV cuando se envía
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csvFile'])) {
    // Verificar si hubo algún error al subir el archivo
    if ($_FILES['csvFile']['error'] === UPLOAD_ERR_OK) {
        $nombreTmp = $_FILES['csvFile']['tmp_name'];
        $nombreArchivo = $_FILES['csvFile']['name'];
        $ext = pathinfo($nombreArchivo, PATHINFO_EXTENSION);

        // Verificar que el archivo sea un CSV
        if (strtolower($ext) === 'csv') {
            // Abrir el archivo CSV para lectura
            if (($handle = fopen($nombreTmp, 'r')) !== FALSE) {
                // Leer la primera línea (cabecera)
                $cabecera = fgetcsv($handle);

                // Abrir o crear el archivo process.csv para agregar datos
                $outputFile = './process.csv';
                $archivoExiste = file_exists($outputFile);
                $modo = $archivoExiste ? 'a' : 'w';

                if (($outputHandle = fopen($outputFile, $modo)) !== FALSE) {
                    // Si el archivo no existe o está vacío, escribir la cabecera
                    if (!$archivoExiste || filesize($outputFile) === 0) {
                        fputcsv($outputHandle, $cabecera);  // CAMBIO AQUÍ
                    }

                    // Leer cada línea del CSV y escribirla en process.csv
                    while (($data = fgetcsv($handle, 2000, ',')) !== FALSE) {
                        fputcsv($outputHandle, $data);  // CAMBIO AQUÍ
                    }
                    fclose($outputHandle);
                    echo "<script type='text/javascript'>
                        alert('Base Exitosamente Anexada');
                        window.location.href = './script_Bot.php';
                    </script>";
                } else {
                    echo "<script type='text/javascript'>
                        alert('No se pudo abrir el archivo de salida para escritura.');
                    </script>";
                }
                fclose($handle);
            } else {
                echo "<script type='text/javascript'>
                    alert('Error al abrir el archivo CSV.');
                </script>";
            }
        } else {
            echo "<script type='text/javascript'>
                alert('Por favor, sube un archivo con formato CSV.');
            </script>";
        }
    } else {
        echo "<script type='text/javascript'>
            alert('Error al subir el archivo.');
        </script>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="shortcut icon" href="../images/icons/window.png" />
    <title>Ges-Bot</title>
</head>
<body>
    <div class="container">
        
        <div class="inic" id="star1">

            <div class="help">
                <a href="../../Model/Documents/ManGes-Bot.pdf" target="_blank"><span>?</span></a>
            </div>

            <div class="tittle">
                <img src="../images/icons/robot.png" alt="">
                <h1>GES-BOT</h1>
            </div>

            <?php
                if (isset($_SESSION['message'])) {
                    echo "<p>" . $_SESSION['message'] . "</p>";
                    unset($_SESSION['message']);
                }
            ?>

            <div class="band-content" onclick="document.getElementById('csvFile').click();">
                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="file" id="csvFile" name="csvFile" accept=".csv" onchange="this.form.submit()" style="display: none;">
                    <div class="band">
                        <img src="../images/icons/download.png" alt="">
                        <span class="add2">Añade una nueva base de datos en formato CSV</span>
                    </div>
                </form>
            </div>

            <div class="buttons-act">
                <button class="act" style="position: relative; left: 550px; top: 200px;" onclick="location.href='./script_Bot.php';">Gestionar</button>
            </div>

            <div class="version">
                <span>1.0.1</span>
            </div>

        </div>

        <div class="backdrop"></div>

    </div>

<script src="View/bootstrap/jquery.js"></script>
<script src="View/bootstrap/bootstrap.bundle.min.js"></script>
<script src="Controller/buttons_Action.js"></script>

</body>
</html>