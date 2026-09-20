// --- CONFIGURACIÓN DEL MAPA ---
const bqtoLat = 10.0645;
const bqtoLng = -69.3569;
const localLat = 10.0768;
const localLng = -69.2974;

const mapa = L.map('mapa-cliente').setView([bqtoLat, bqtoLng], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: 'Yora Delivery'
}).addTo(mapa);

L.marker([localLat, localLng]).addTo(mapa).bindPopup("Tu Comercio").openPopup();

const iconoCliente = L.icon({
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-orange.png',
    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
    iconSize: [25, 41], iconAnchor: [12, 41]
});

const markerCliente = L.marker([bqtoLat, bqtoLng], {
    icon: iconoCliente,
    draggable: true 
}).addTo(mapa);

// --- CÁLCULO DE DISTANCIA Y PRECIO DINÁMICO ---
const TARIFA_POR_KM = window.PRECIO_KM || 0.80;

function calcularCosto() {
    let posCliente = markerCliente.getLatLng();
    let puntoComercio = L.latLng(localLat, localLng);
    let metros = puntoComercio.distanceTo(posCliente);
    
    let km = (metros / 1000) * 1.3; 
    let costoTotal = km * TARIFA_POR_KM;

    if(costoTotal < 1.50) costoTotal = 1.50;

    document.getElementById('distancia-km').innerText = km.toFixed(1);
    document.getElementById('costo-total').innerText = costoTotal.toFixed(2);
    document.querySelector('.km').innerText = `Costo por KM: $${TARIFA_POR_KM.toFixed(2)}`;
    
    document.getElementById('latDestino').value = posCliente.lat;
    document.getElementById('lngDestino').value = posCliente.lng;
}

markerCliente.on('dragend', calcularCosto);
calcularCosto();

// --- ENVÍO DE PEDIDO REAL (EN TIEMPO REAL SIN RECARGAR) ---
async function enviarPedido(e) {
    e.preventDefault();
    
    const btn = document.querySelector('.btn-request');
    btn.innerHTML = "Procesando orden... ⏳";
    btn.disabled = true;

    const formData = new FormData();
    formData.append('nombreCliente', document.getElementById('nombreCliente').value);
    formData.append('telCliente', document.getElementById('telCliente').value);
    formData.append('detallesEntrega', document.getElementById('detallesEntrega').value);
    formData.append('tiempoEstimado', document.getElementById('tiempoEstimado').value); // Captura el tiempo
    formData.append('lat', document.getElementById('latDestino').value);
    formData.append('lng', document.getElementById('lngDestino').value);
    formData.append('distancia', document.getElementById('distancia-km').innerText);
    formData.append('costo', document.getElementById('costo-total').innerText.replace('$', '')); 

    try {
        const res = await fetch('/api/crear_comanda.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if(data.status === 'success') {
            document.getElementById('form-pedido').reset(); // Limpia campos
            cargarPedidosEnVivo(); // Actualiza el radar instantáneamente
        } else {
            alert("Atención: " + data.mensaje);
        }
    } catch(error) {
        alert("Ocurrió un error de conexión con los servidores.");
    }
    
    btn.innerHTML = "🚀 Solicitar Conductor";
    btn.disabled = false;
}

// --- FUNCIÓN PARA CANCELAR Y REEMBOLSAR ---
async function cancelarPedido(id) {
    if(!confirm("¿Estás seguro de cancelar esta orden? El crédito se liberará de inmediato.")) return;

    const formData = new FormData();
    formData.append('comanda_id', id);

    try {
        const res = await fetch('/api/cancelar_comanda.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if(data.status === 'success') {
            cargarPedidosEnVivo(); // Actualiza la lista y el saldo al instante
        } else {
            alert("❌ Error: " + data.mensaje);
        }
    } catch(error) {
        alert("Ocurrió un error al intentar cancelar la orden.");
    }
}

// ==========================================
// 📡 RADAR EN TIEMPO REAL (AJAX POLLING)
// ==========================================
async function cargarPedidosEnVivo() {
    try {
        let res = await fetch('/api/obtener_pedidos_activos.php');
        let data = await res.json();
        
        if(data.html) {
            document.getElementById('contenedor-pedidos').innerHTML = data.html;
            document.querySelector('.wallet-badge').innerText = 'Saldo: $' + data.saldo;
        }
    } catch(error) {
        console.log("Esperando red...");
    }
}

// Cargar al iniciar y repetir cada 5 segundos de forma silenciosa
cargarPedidosEnVivo();
setInterval(cargarPedidosEnVivo, 5000);