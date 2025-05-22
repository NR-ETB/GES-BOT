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
                // Omitir la primera línea (cabecera)
                fgetcsv($handle);

                // Abrir o crear el archivo process.csv para agregar datos
                $outputFile = './process.csv';
                $modo = file_exists($outputFile) ? 'a' : 'w';

                if (($outputHandle = fopen($outputFile, $modo)) !== FALSE) {
                    // Leer cada línea del CSV y escribirla en process.csv
                    while (($data = fgetcsv($handle, 2000, ',')) !== FALSE) {
                        // Convertir el array en una línea de texto separada por comas
                        $linea = implode(',', $data) . PHP_EOL;
                        fwrite($outputHandle, $linea);
                    }
                    fclose($outputHandle);
                    echo "<script type='text/javascript'>
                        alert('Base Exitosamente Anexada');
                    </script>";
                } else {
                    // $mensaje = "No se pudo abrir 'process.csv' para escritura.";
                }
                fclose($handle);
            } else {
                echo "<script type='text/javascript'>
                    alert('Error al Abrir la Base');
                </script>";
            }
        } else {
            // $mensaje = "Por favor, sube un archivo con formato CSV.";
        }
    } else {
        echo "<script type='text/javascript'>
            alert('Error al Subir la Base');
        </script>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="View/css/style.css">
    <link rel="shortcut icon" href="View/images/icons/window.png" />
    <title>Ges-Bot</title>
</head>
<body>
    <div class="container">
        
        <div class="inic" id="star1">

            <div class="help">
                <a href="Model/Documents/manReto-Suma.pdf" target="_blank"><span>?</span></a>
            </div>

            <div class="tittle">
                <img src="View/images/icons/robot.png" alt="">
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
                        <img src="View/images/icons/download.png" alt="">
                        <span class="add">Añade la base de datos en formato CSV</span>
                    </div>
                </form>
            </div>

            <div class="buttons-act">
                <button class="act" style="position: relative; left: 550px; top: 200px;" onclick="location.href='View/ges/script_Bot.php';">Gestionar</button>
            </div>

        </div>

        <div class="backdrop"></div>

    </div>

<script src="View/bootstrap/jquery.js"></script>
<script src="View/bootstrap/bootstrap.bundle.min.js"></script>
<script src="Controller/buttons_Action.js"></script>

</body>
</html>