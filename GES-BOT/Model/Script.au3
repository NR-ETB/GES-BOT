#include <MsgBoxConstants.au3>

Global $rutaBase = "C:\Users\" & EnvGet("USERNAME") & "\Pictures\"

; Verificar imágenes
If Not FileExists($rutaBase & "adecuaciones1.png") Then
    MsgBox($MB_ICONERROR, "Error", "No se encuentra adecuaciones1.png")
    Exit
EndIf

If Not FileExists($rutaBase & "adecuaciones2.png") Then
    MsgBox($MB_ICONERROR, "Error", "No se encuentra adecuaciones2.png")
    Exit
EndIf

; Activar navegador
WinActivate("Google Chrome")
WinWaitActive("Google Chrome", "", 5)

; Scroll hasta el fondo (overflow-y hacia abajo)
Send("{END}")
Sleep(500)
Send("{PGDN}")
Sleep(500)
Send("{PGDN}")
Sleep(500)
Send("{PGDN}")
Sleep(1000)

Func SubirImagen($nombreImagen, $numero, $xInicial, $yInicial, $esperaExtra)
    ; Click en las coordenadas especificadas
    MouseClick("left", $xInicial, $yInicial, 1)
    Sleep(500)

    ; Click para abrir el explorador
    MouseClick("left", 818, 768, 1)
    Sleep(1000)

    ; Esperar ventana de explorador
    If WinWaitActive("[CLASS:#32770]", "", 10) Then
        ; Click en sección "Imágenes"
        MouseClick("left", 570, 748, 1)
        Sleep(1000)

        ; Escribir nombre de la imagen
        Send($nombreImagen)
        Sleep(300)
        Send("{ENTER}")
        Sleep(1500)

        ; Click para confirmar
        MouseClick("left", 820, 767, 1)
        Sleep(1000)

        If $esperaExtra Then Sleep(5000)

        Return True
    Else
        MsgBox($MB_ICONERROR, "Error", "No se pudo abrir el diálogo para imagen " & $numero)
        Return False
    EndIf
EndFunc

; === PROCESO COMPLETO CON 6 CLICKS ===

; PRIMERA IMAGEN
; Click 1: Activar selector primera imagen - Coordenadas: 581, 769
; Click 2: Abrir explorador - Coordenadas: 818, 768
; Click 3: Ir a sección "Imágenes" - Coordenadas: 570, 748
If Not SubirImagen("adecuaciones1.png", "1", 581, 769, True) Then Exit

; SEGUNDA IMAGEN (mismo proceso)
; Click 4: Activar selector segunda imagen - Coordenadas: 474, 762
; Click 5: Abrir explorador - Coordenadas: 818, 768
; Click 6: Ir a sección "Imágenes" - Coordenadas: 570, 748
If Not SubirImagen("adecuaciones2.png", "2", 474, 762, False) Then Exit

MsgBox($MB_ICONINFO, "Éxito", "Imágenes subidas correctamente")

; === RESUMEN DE LOS 6 CLICKS ===
; Click 1: (581, 769) - Activar primera imagen
; Click 2: (818, 768) - Abrir explorador
; Click 3: (570, 748) - Ir a "Imágenes"
; Click 4: (474, 762) - Activar segunda imagen  
; Click 5: (818, 768) - Abrir explorador
; Click 6: (570, 748) - Ir a "Imágenes"