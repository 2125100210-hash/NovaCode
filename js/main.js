// Solo maneja fechas, mensajes en pantalla y detalles visuales del menu

function p2(n) {
    return (n < 10 ? "0" : "") + n;
}

function $(id) {
    return document.getElementById(id);
}

function esc(s) {
    if (s === null || s === undefined) return "";
    const amp = String.fromCharCode(38);
    const mapa = {
        "&": amp + "amp;",
        "<": amp + "lt;",
        ">": amp + "gt;",
        '"': amp + "quot;",
       "'": amp + "#" + "039;"
    };
    return String(s).replace(/[&<>"']/g, (m) => mapa[m]);
}

function hoy() {
    const d = new Date();
    return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;
}

function fmtFecha(iso) {
    if (!iso) return "";
    const [anio, mes, dia] = iso.split("-");
    if (!anio || !mes || !dia) return iso;
    return `${dia}/${mes}/${anio}`;
}

function digits(s) {
    return String(s || "").replace(/\D/g, "");
}

function msg(id, texto, esExito) {
    const el = $(id);
    if (!el) return;

    el.textContent = texto;
    el.className = `err alert ${esExito ? "alert-success" : "alert-danger"} py-2 mb-3`;
    el.classList.remove("d-none");
}

function emptyStateHtml(cols, icon, titulo) {
    return `<tr><td colspan="${cols}">` +
        `<div class="empty-state-box">` +
        `<h6 class="fw-bold text-dark mb-1">${esc(titulo)}</h6>` +
        `</div></td></tr>`;
}

const CANCHAS = [
    { id: 1, nombre: "Cancha 1 (Futbol 7)", tipo: 7 },
    { id: 2, nombre: "Cancha 2 (Futbol 11)", tipo: 11 },
    { id: 3, nombre: "Cancha 3 (Futbol 5)", tipo: 5 },
    { id: 4, nombre: "Cancha 4 (Futbol 5)", tipo: 5 },
    { id: 5, nombre: "Cancha 5 (Futbol 11)", tipo: 11 }
];

const HORAS = [];
for (let hh = 8; hh < 22; hh++) {
    HORAS.push(`${p2(hh)}:00 - ${p2(hh + 1)}:00`);
}

document.addEventListener("DOMContentLoaded", () => {
    const paginaActual = location.pathname.split("/").pop() || "equipos.php";
    document.querySelectorAll(".navbar-custom .nav-link").forEach((link) => {
        const href = link.getAttribute("href");
        if (href) {
            link.classList.toggle("active", href === paginaActual);
        }
    });

    const parFecha = $("parFecha");
    if (parFecha && !parFecha.min) {
        parFecha.min = hoy();
    }
});