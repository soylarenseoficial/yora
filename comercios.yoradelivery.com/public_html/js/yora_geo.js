/**
 * Buscador de direcciones sesgado a Barquisimeto / Lara.
 * Llama a /api/buscar_direccion.php y pinta sugerencias.
 */
function yoraDebounce(fn, ms) {
    let t;
    return function () {
        const ctx = this;
        const args = arguments;
        clearTimeout(t);
        t = setTimeout(function () { fn.apply(ctx, args); }, ms || 420);
    };
}

async function yoraBuscarDireccion(texto, lista, alElegir) {
    const q = String(texto || '').trim();
    if (q.length < 3) {
        lista.innerHTML = '<div style="color:#94a3b8;">Escribe al menos 3 caracteres.</div>';
        lista.style.display = 'block';
        return;
    }
    lista.innerHTML = '<div style="color:#94a3b8;">Buscando en Barquisimeto / Lara…</div>';
    lista.style.display = 'block';
    try {
        const fd = new FormData();
        fd.append('q', q);
        const res = await fetch('/api/buscar_direccion.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        lista.innerHTML = '';
        if (data.status !== 'success' || !data.resultados || !data.resultados.length) {
            lista.innerHTML = '<div style="color:#94a3b8;">Sin resultados en Lara. Arrastra el pin o prueba “carrera 19 con 25”.</div>';
            lista.style.display = 'block';
            return;
        }
        data.resultados.forEach(function (r, i) {
            const div = document.createElement('div');
            div.textContent = r.nombre;
            div.onclick = function () {
                lista.style.display = 'none';
                alElegir(r, { auto: false });
            };
            lista.appendChild(div);
            if (i === 0 && (r.fuente === 'cruce' || r.fuente === 'local')) {
                alElegir(r, { auto: true });
            }
        });
        lista.style.display = 'block';
    } catch (e) {
        lista.innerHTML = '<div style="color:#dc2626;">No se pudo buscar. Coloca el pin en el mapa.</div>';
        lista.style.display = 'block';
    }
}
