// Imprime el reporte sin salir de la pagina: carga reporte.php en un marco oculto y manda a imprimir ese marco
function imprimirReporte(url, boton) {
    let marco = document.getElementById('marcoReporte');
    if (marco) {
        marco.remove();
    }

    marco = document.createElement('iframe');
    marco.id = 'marcoReporte';
    marco.setAttribute('aria-hidden', 'true');
    marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';

    const textoOriginal = boton ? boton.innerHTML : '';
    if (boton) {
        boton.classList.add('disabled');
        boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Preparando...';
    }

    marco.onload = function () {
        if (boton) {
            boton.classList.remove('disabled');
            boton.innerHTML = textoOriginal;
        }
        try {
            marco.contentWindow.focus();
            marco.contentWindow.print();
        } catch (e) {
            // Si el navegador no deja imprimir desde el marco, abre el reporte en otra pestaña
            window.open(url, '_blank');
        }
    };

    marco.src = url;
    document.body.appendChild(marco);
    return false;
}
