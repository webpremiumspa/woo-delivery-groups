/**
 * WooCommerce Delivery Groups v1.1.0
 * Webpremium Chile
 */

var GROUP_COLORS = [
    '#e63946','#2196F3','#2a9d8f','#f4a261','#9b59b6',
    '#e76f51','#1d7874','#f72585','#4cc9f0','#06d6a0',
    '#ffb703','#023e8a','#c77dff','#fb8500','#118ab2',
    '#d62828','#588157','#b5179e','#0077b6','#6a4c93'
];

var map, markers = [], infoWindows = [], polylines = [], depotMarker = null;
var activeTokens  = {};  // { groupIdx: token }
var progressTimer = null;
var currentGroups  = [];     // grupos actuales en memoria
var currentPlanId  = null;   // ID del plan cargado (null = sin guardar)
var currentConfig  = {};     // config actual
var planIsSaved    = false;  // true después de guardar → bloquea mover

// Stub global functions before jQuery ready (PHP onclicks need them immediately)
window.wdgNewPlan    = function() { jQuery(function(){ wdgNewPlan(); }); };
window.wdgLoadPlan   = function(id) { jQuery(function(){ wdgLoadPlan(id); }); };
window.wdgDeletePlan = function(id, name) { jQuery(function(){ wdgDeletePlan(id, name); }); };

jQuery(document).ready(function ($) {

    // ── Botones K (número de grupos) ─────────────────────────────────────────
    $(document).on('click', '.wdg-kbtn', function () {
        $('.wdg-kbtn').removeClass('wdg-kbtn--active');
        $(this).addClass('wdg-kbtn--active');
        $('#wdgGroups').val($(this).data('val'));
    });

    // ── Click en dirección → zoom mapa ───────────────────────────────────────
    $(document).on('click', '.wdg-addr-link', function(e) {
        e.preventDefault();
        var lat = parseFloat($(this).data('lat'));
        var lng = parseFloat($(this).data('lng'));
        if (!map || !lat || !lng) return;
        infoWindows.forEach(function(w){ w.close(); });
        map.setCenter({ lat: lat, lng: lng });
        map.setZoom(17);
        // Abrir popup del marcador correspondiente
        for (var i = 0; i < markers.length; i++) {
            var pos = markers[i].getPosition();
            if (Math.abs(pos.lat() - lat) < 0.0001 && Math.abs(pos.lng() - lng) < 0.0001) {
                infoWindows[i].open(map, markers[i]);
                break;
            }
        }
        // Scroll al mapa en pantallas chicas
        document.getElementById('wdgMap').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // ── Google Maps ───────────────────────────────────────────────────────────
    function initMap() {
        if ( typeof google === 'undefined' ) return;
        map = new google.maps.Map(document.getElementById('wdgMap'), {
            center: { lat: -33.45, lng: -70.65 },
            zoom: 11,
            restriction: { latLngBounds: { north:-32.5, south:-34.5, east:-69.5, west:-72.0 }, strictBounds: false },
            mapTypeId: 'roadmap',
            styles: [{ featureType:'poi', stylers:[{visibility:'off'}] }]
        });

        // Mostrar bodega si ya está configurada
        if ( wdgData.depot && wdgData.depot.lat ) {
            placeDepotMarker(wdgData.depot.lat, wdgData.depot.lng, wdgData.depot.address);
        }
    }

    if ( wdgData.apiKey ) {
        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + wdgData.apiKey + '&callback=wdgMapReady';
        s.async = true;
        document.head.appendChild(s);
    }
    window.wdgMapReady = function() { initMap(); };

    // ── Marcador de bodega ────────────────────────────────────────────────────
    function placeDepotMarker(lat, lng, address) {
        if (!map) return;
        if (depotMarker) depotMarker.setMap(null);
        depotMarker = new google.maps.Marker({
            position: { lat: parseFloat(lat), lng: parseFloat(lng) },
            map:      map,
            title:    '🏭 Bodega: ' + address,
            icon: {
                path:        google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                fillColor:   '#1a1a2e',
                fillOpacity: 1,
                strokeColor: '#fff',
                strokeWeight: 2,
                scale:       7,
                rotation:    180,
            },
            zIndex: 9999,
            label: { text: '🏭', fontSize: '16px' },
        });
        var iw = new google.maps.InfoWindow({
            content: '<div style="font-size:12px"><strong>🏭 Bodega / Punto de inicio</strong><br>' +
                     address + '<br><code style="font-size:10px">' + lat + ', ' + lng + '</code></div>',
        });
        depotMarker.addListener('click', function(){ iw.open(map, depotMarker); });
    }

    // ── Guardar bodega ────────────────────────────────────────────────────────
    $('#btnSaveDepot').on('click', function () {
        var address = $('#wdgDepotAddress').val().trim();
        if (!address) { alert('Ingresa una dirección de bodega.'); return; }

        $(this).prop('disabled', true).text('⏳ Geocodificando…');
        $('#wdgDepotStatus').html('<span style="color:#0369a1">⏳ Verificando dirección…</span>');

        $.post(wdgData.ajaxUrl, {
            action:  'wdg_save_depot',
            nonce:   wdgData.nonce,
            address: address,
        }, function(res) {
            $('#btnSaveDepot').prop('disabled', false).text('💾 Guardar');
            if (res.success) {
                var d = res.data;
                wdgData.depot = d;
                $('#wdgDepotAddress').val(d.address);
                $('#wdgDepotStatus').html(
                    '<span class="wdg-depot-ok">✅ Bodega guardada: <strong>' + escHtml(d.address) + '</strong>' +
                    ' <code>' + d.lat.toFixed(6) + ', ' + d.lng.toFixed(6) + '</code>' +
                    ' <a href="https://www.google.com/maps?q=' + d.lat + ',' + d.lng + '" target="_blank">Ver en Maps ↗</a></span>'
                );
                if (map) {
                    placeDepotMarker(d.lat, d.lng, d.address);
                    map.setCenter({ lat: parseFloat(d.lat), lng: parseFloat(d.lng) });
                }
            } else {
                $('#wdgDepotStatus').html('<span style="color:#b91c1c">❌ ' + escHtml(res.data) + '</span>');
            }
        }).fail(function() {
            $('#btnSaveDepot').prop('disabled', false).text('💾 Guardar');
            $('#wdgDepotStatus').html('<span style="color:#b91c1c">❌ Error de conexión</span>');
        });
    });

    // ── Generar grupos ────────────────────────────────────────────────────────
    // ── Paso 1: Buscar pedidos ───────────────────────────────────────────────
    $('#btnSearch').on('click', function () {
        var maxPerGroup = parseInt($('#wdgMaxPerGroup').val()) || 35;
        var dateFrom    = $('#wdgDateFrom').val();
        var dateTo      = $('#wdgDateTo').val();
        var status      = $('#wdgStatus').val();
        var names       = $('#wdgNames').val();

        currentConfig = { max_per_group:maxPerGroup, date_from:dateFrom,
                          date_to:dateTo, status:status, names:names,
                          exclude_delivered: '0' };

        $('#btnSearch').prop('disabled', true).text('Buscando…');
        $('#wdgStatsCard, #wdgGroupsCard, #wdgSavePlanCard, #wdgProgressCard, #wdgRepartidoresCard, #wdgAddOrdersCard').hide();
        // Limpiar mapa y estado previo
        clearMap();
        window.wdgOrders  = [];
        window.wdgGroups  = [];
        currentGroups     = [];
        currentPlanId     = null;
        planIsSaved       = false;
        activeTokens      = {};

        $.post(wdgData.ajaxUrl, {
            action:            'wdg_get_orders',
            nonce:             wdgData.nonce,
            date_from:         dateFrom,
            date_to:           dateTo,
            status:            status,
            exclude_delivered: currentConfig.exclude_delivered,
        }, function(res) {
            $('#btnSearch').prop('disabled', false).text('🔍 Buscar pedidos');
            if (!res.success) { alert('Error: ' + res.data); return; }

window.wdgOrders = res.data.orders;
            var total        = res.data.orders.length;
            var skipped      = res.data.skipped || 0;
            var max          = maxPerGroup;

            // Calcular K automático
            var k = Math.ceil(total / max);
            if (k < 1) k = 1;

            // Distribuir pedidos entre repartidores
            var remainder = total % max;
            var quotas    = [];
            for (var i = 0; i < k; i++) {
                if (i < k - 1 || remainder === 0) quotas.push(max);
                else quotas.push(remainder);
            }

            renderRepartidores(total, skipped, quotas);
        }).fail(function() {
            $('#btnSearch').prop('disabled', false).text('🔍 Buscar pedidos');
            alert('Error de conexión');
        });
    });

    function renderRepartidores(total, skipped, quotas) {
        var activeDrivers = wdgDrivers.filter(function(d){ return d.activo; });

        var html = '<div class="wdg-quota-info">Se encontraron <strong>' + total + ' pedidos</strong>';
        if (skipped) html += ' · <span style="color:#f59e0b">' + skipped + ' sin coordenadas</span>';
        html += ' → <strong>' + quotas.length + ' repartidores</strong></div>';

        quotas.forEach(function(q, i) {
            // Construir select con repartidores activos
            var opts = '<option value="">— Seleccionar —</option>';
            activeDrivers.forEach(function(d) {
                opts += '<option value="'+escHtml(d.id)+'" data-nombre="'+escHtml(d.nombre)+'" data-cap="'+d.capacidad+'">'+
                        escHtml(d.nombre)+(d.zona?' ('+escHtml(d.zona)+')':'')+'</option>';
            });
            if (!activeDrivers.length) {
                opts = '<option value="" disabled>Sin repartidores — crear en pestaña 👥</option>';
            }
            // Select de vehículos disponibles
            var activeVehicles = wdgVehicles.filter(function(v){ return v.activo; });
            var vopts = '<option value="">— Vehículo —</option>';
            activeVehicles.forEach(function(v) {
                vopts += '<option value="'+escHtml(v.id)+'">'+escHtml(v.patente)+' ('+escHtml(v.tipo)+')</option>';
            });
            if (!activeVehicles.length) vopts = '<option value="" disabled>Sin vehículos</option>';

            html += '<div class="wdg-rep-row">'
                  + '<select class="wdg-rep-select" data-idx="' + i + '">' + opts + '</select>'
                  + '<select class="wdg-veh-select" data-idx="' + i + '">' + vopts + '</select>'
                  + '<input type="number" class="wdg-rep-quota" data-idx="' + i + '" value="' + q + '" min="1" max="' + total + '">'
                  + '</div>';
        });

        $('#wdgRepartidoresList').html(html);
        $('#wdgRepartidoresCard').show();
        validateQuotas(total);
    }

    function validateQuotas(total) {
        var sum = 0;
        $('.wdg-rep-quota').each(function(){ sum += parseInt($(this).val()) || 0; });
        var allDrivers   = $('.wdg-rep-select').toArray().every(function(s){ return $(s).val(); });
        var allVehicles  = $('.wdg-veh-select').toArray().every(function(s){ return $(s).val(); });
        var ok = sum === total && allDrivers && allVehicles;
        var pct = total > 0 ? Math.round(sum/total*100) : 0;

        var msg = '';
        if (sum !== total) msg += ' ⚠️ Total debe ser ' + total;
        else if (!allDrivers) msg += ' ⚠️ Asigna un repartidor a cada grupo';
        else if (!allVehicles) msg += ' ⚠️ Asigna un vehículo a cada grupo';
        var summaryHtml = '<div class="wdg-quota-total ' + (ok ? 'ok' : 'err') + '">'
            + 'Total asignado: <strong>' + sum + ' / ' + total + '</strong>'
            + (ok ? ' ✅' : msg)
            + '</div>';
        $('#wdgQuotaSummary').html(summaryHtml);
        $('#btnProcess').prop('disabled', !ok);
    }

    $(document).on('input', '.wdg-rep-quota', function() {
        var total = window.wdgOrders ? window.wdgOrders.length : 0;
        validateQuotas(total);
    });

    $(document).on('change', '.wdg-rep-select', function() {
        refreshDriverSelects();
        var total = window.wdgOrders ? window.wdgOrders.length : 0;
        validateQuotas(total);
    });

    $(document).on('change', '.wdg-veh-select', function() {
        refreshVehicleSelects();
        var total = window.wdgOrders ? window.wdgOrders.length : 0;
        validateQuotas(total);
    });

    function refreshVehicleSelects() {
        var selected = {};
        $('.wdg-veh-select').each(function() {
            var val = $(this).val();
            if (val) selected[val] = true;
        });
        $('.wdg-veh-select').each(function() {
            var $sel    = $(this);
            var current = $sel.val();
            var active  = wdgVehicles.filter(function(v){ return v.activo; });
            var opts    = '<option value="">— Vehículo —</option>';
            active.forEach(function(v) {
                if (!selected[v.id] || v.id === current) {
                    opts += '<option value="'+escHtml(v.id)+'"'+(v.id===current?' selected':'')+'>'+
                            escHtml(v.patente)+' ('+escHtml(v.tipo)+')</option>';
                }
            });
            $sel.html(opts);
        });
    }

    function refreshDriverSelects() {
        // Obtener IDs ya seleccionados
        var selected = {};
        $('.wdg-rep-select').each(function() {
            var val = $(this).val();
            if (val) selected[val] = true;
        });

        // Reconstruir cada select mostrando solo los disponibles + el propio
        $('.wdg-rep-select').each(function() {
            var $sel     = $(this);
            var current  = $sel.val();
            var idx      = $sel.data('idx');
            var active   = wdgDrivers.filter(function(d){ return d.activo; });

            var opts = '<option value="">— Seleccionar —</option>';
            active.forEach(function(d) {
                // Mostrar si no está seleccionado por otro, o si es el propio
                if (!selected[d.id] || d.id === current) {
                    opts += '<option value="'+escHtml(d.id)+'" data-nombre="'+escHtml(d.nombre)+
                            '" data-cap="'+d.capacidad+'"'+(d.id === current ? ' selected' : '')+'>'+
                            escHtml(d.nombre)+(d.zona?' ('+escHtml(d.zona)+')':'')+
                            '</option>';
                }
            });
            if (!active.length) {
                opts = '<option value="" disabled>Sin repartidores — crear en pestaña 👥</option>';
            }
            $sel.html(opts);
        });
    }

    // ── Paso 2: Generar rutas ────────────────────────────────────────────────
    $('#btnProcess').on('click', function () {
        var maxPerGroup = parseInt($('#wdgMaxPerGroup').val()) || 35;
        var dateFrom    = currentConfig.date_from || $('#wdgDateFrom').val();
        var dateTo      = currentConfig.date_to   || $('#wdgDateTo').val();
        var status      = currentConfig.status    || $('#wdgStatus').val();
        var names       = $('#wdgNames').val();

        // Leer cuotas, nombres y vehículos desde los selects
        var maxima = [];
        var namesList = [];
        $('.wdg-rep-quota').each(function(i){
            maxima.push(parseInt($(this).val()) || 35);
        });
        $('.wdg-rep-select').each(function(){
            var $opt = $(this).find('option:selected');
            var nombre = $opt.data('nombre') || ('Repartidor ' + ($(this).data('idx')+1));
            namesList.push(nombre);
        });
        $('#wdgNames').val(namesList.join(','));

        currentConfig = { max_per_group:maxPerGroup, date_from:dateFrom,
                          date_to:dateTo, status:status, names:names,
                          maxima:maxima, k:maxima.length,
                          exclude_delivered: '0' };

        if (!dateFrom || !dateTo) { alert('Selecciona rango de fechas.'); return; }
if (maxima.length < 1) { alert('Primero busca los pedidos.'); return; }

        $(this).prop('disabled', true);
        var orders = window.wdgOrders || [];
        if (!orders.length) { alert('Primero busca los pedidos.'); return; }
if (orders.length < maxima.length) { alert('Menos pedidos ('+orders.length+') que repartidores ('+maxima.length+').'); return; }

        $('#wdgSpinner').show();
        $('#wdgGroupsCard, #wdgSavePlanCard, #wdgProgressCard').hide();

        $.post(wdgData.ajaxUrl, {
            action:  'wdg_cluster',
            nonce:   wdgData.nonce,
            orders:  JSON.stringify(orders),
            maxima:  JSON.stringify(maxima),
            names:   names,
        }, function(res) {
            resetBtn();
            if (!res.success) { alert('Error: ' + res.data); return; }
var groups   = res.data.groups;
            var hasDepot = res.data.has_depot;
            // Enriquecer grupos con info del repartidor seleccionado
            groups.forEach(function(g, i) {
                var $sel = $('.wdg-rep-select[data-idx="'+i+'"]');
                if ($sel.length) {
                    var selId = $sel.val();
                    if (selId) {
                        var drv = wdgDrivers.find(function(d){ return d.id === selId; });
                        if (drv) {
                            g.driver_id   = drv.id;
                            g.driver_name = drv.nombre;
                        }
                    }
                }
            });
            window.wdgGroups = groups;
            renderStats(orders.length, 0, groups, maxima[0]||35, hasDepot);
            renderGroups(groups, hasDepot);
            renderMap(groups, hasDepot);
        }).fail(function() { resetBtn(); alert('Error de conexión'); });
    });

    function resetBtn() {
        $('#btnProcess').prop('disabled', false);
        $('#wdgSpinner').hide();
    }

    // ── Stats ─────────────────────────────────────────────────────────────────
    function renderStats(total, skipped, groups, maxPerGroup, hasDepot) {
        var counts   = groups.map(function(g){ return g.count; });
        var totalKm  = groups.reduce(function(s,g){ return s + (g.route_km||0); }, 0);
        var html = '<div class="wdg-stat-grid">';
        html += statBox(total,                      'Pedidos Santiago',          '');
        html += statBox(groups.length,              'Grupos',                    'ok');
        html += statBox(Math.min.apply(null,counts)+' – '+Math.max.apply(null,counts), 'Pedidos/grupo', Math.max.apply(null,counts) > maxPerGroup ? 'warn' : 'ok');
        html += statBox(totalKm.toFixed(1)+' km',   'Km total' + (hasDepot ? ' (c/retorno)' : ''), '');
        if (skipped > 0) html += statBox(skipped, 'Sin coords/fuera Stgo', 'warn');
        if (!hasDepot)   html += statBox('⚠️', 'Sin bodega configurada', 'warn');
        html += '</div>';
        $('#wdgStats').html(html);
        $('#wdgStatsCard').show();
    }

    function statBox(val, lbl, cls) {
        return '<div class="wdg-stat'+(cls?' wdg-stat--'+cls:'')+'"><div class="wdg-stat-val">'+val+'</div><div class="wdg-stat-lbl">'+lbl+'</div></div>';
    }

    // ── Lista de grupos ───────────────────────────────────────────────────────
    function renderGroups(groups, hasDepot) {
        var html = '';
        groups.forEach(function(g, i) {
            var color    = GROUP_COLORS[i % GROUP_COLORS.length];
            var kmTxt    = g.route_km > 0 ? ' &nbsp;·&nbsp; ~' + g.route_km + ' km' : '';

            html += '<div class="wdg-group-item" id="group-item-'+i+'">';

            // Header
            // Obtener nombre del repartidor asignado
            var $sel       = $('.wdg-rep-select[data-idx="'+i+'"]');
            var driverName = '';
            if ($sel.length) {
                var selId = $sel.val();
                if (selId) {
                    var drv = wdgDrivers.find(function(d){ return d.id === selId; });
                    if (drv) driverName = drv.nombre;
                }
            }
            // Si no hay select (plan cargado), usar g.driver_name si existe
            if (!driverName && g.driver_name) driverName = g.driver_name;

            html += '<div class="wdg-group-header" onclick="wdgToggleGroup('+i+')">';
            html += '<div class="wdg-group-dot" style="background:'+color+'"></div>';
            html += '<div class="wdg-group-title-wrap">';
            if (driverName) {
                html += '<div class="wdg-group-title">'+escHtml(g.name)+' <span class="wdg-group-driver">— '+escHtml(driverName)+'</span></div>';
            } else {
                html += '<div class="wdg-group-title">'+escHtml(g.name)+'</div>';
            }
            html += '</div>';
            html += '<span class="wdg-group-badge">'+g.count+' paradas'+kmTxt+'</span>';
            html += '<span class="wdg-group-toggle" id="toggle-'+i+'">▼</span>';
            html += '</div>';

            // Body
            html += '<div class="wdg-group-body" id="group-body-'+i+'">';

            // Fila 1: botones herramientas
            html += '<div class="wdg-group-actions">';
            html += '<button class="button button-small" onclick="wdgPrintGroup('+i+')">🖨️ Imprimir</button>';
            html += '<button class="button button-small" onclick="wdgFocusGroup('+i+')">🗺️ Ver mapa</button>';
            html += '<button class="button button-small" onclick="wdgExportCSV('+i+')">📄 CSV</button>';
            // Mostrar link si ya fue generado, o el botón si no
            if (activeTokens[i]) {
                var existingUrl = window.location.origin + '/?wdg_ruta=' + activeTokens[i];
                // Intentar obtener la URL real del input si ya está renderizado
                var $existingInput = $('#link-row-'+i+' .wdg-link-input');
                if ($existingInput.length) existingUrl = $existingInput.val();
                html += '<div class="wdg-inline-link-box">'
                      + '<span class="wdg-inline-link-label">📲 Link conductor</span>'
                      + '<input type="text" class="wdg-inline-link-input wdg-link-input-'+i+'" readonly value="" style="flex:1;font-size:11px">'
                      + '<button class="button button-small wdg-copy-inline" data-idx="'+i+'">📋</button>'
                      + '<button class="button button-small wdg-btn-link" onclick="wdgGenerateLink('+i+')">🔄</button>'
                      + '</div>';
            } else {
                html += '<button class="button button-small wdg-btn-link" onclick="wdgGenerateLink('+i+')">📲 Link conductor</button>';
            }
            html += '</div>';
            html += '<div class="wdg-link-row" id="link-row-'+i+'" style="display:none"></div>';

            // Fila 2: botones Google Maps (fila propia)


            // Desglose km si hay bodega
            if (hasDepot && g.route_km > 0) {
                html += '<div class="wdg-km-breakdown">';
                html += '<span>🏭 Bodega → 1ª parada: <strong>'+g.depot_to_first_km+' km</strong></span>';
                html += '<span>Ruta entre paradas: <strong>'+(g.route_km - g.depot_to_first_km - g.last_to_depot_km).toFixed(2)+' km</strong></span>';
                html += '<span>Última parada → 🏭 Bodega: <strong>'+g.last_to_depot_km+' km</strong></span>';
                html += '<span class="wdg-km-total">Total: <strong>'+g.route_km+' km</strong></span>';
                html += '</div>';
            }

            // Paradas: bodega primero si existe
            if (hasDepot && wdgData.depot && wdgData.depot.lat) {
                html += '<div class="wdg-order-row wdg-depot-row-item">';
                html += '<div class="wdg-order-stop wdg-depot-stop">🏭</div>';
                html += '<div class="wdg-order-id" style="color:#555">Bodega</div>';
                html += '<div><div class="wdg-order-addr">'+escHtml(wdgData.depot.address)+'</div><div class="wdg-order-customer">Punto de inicio</div></div>';
                html += '<div></div></div>';
            }

            g.orders.forEach(function(o, idx) {
                html += '<div class="wdg-order-row">';
                html += '<div class="wdg-order-stop" style="background:'+color+'">'+(idx+1)+'</div>';
                html += '<div class="wdg-order-id">'+
                    '<a href="'+wdgData.adminUrl+'post.php?post='+o.id+'&action=edit" target="_blank">#'+o.id+'</a>'+
                    ' <button class="button button-small wdg-btn-move" '+
                    'data-order-idx="'+idx+'" data-group-idx="'+i+'" '+
                    'title="Mover a otra ruta">⇄</button>'+
                    '</div>';
                html += '<div><div class="wdg-order-addr">'+
                    '<a class="wdg-addr-link" href="#" data-lat="'+parseFloat(o.lat)+'" data-lng="'+parseFloat(o.lng)+'">'+
                    escHtml(o.address)+', '+escHtml(o.city)+'</a>'+(o.delivered?'<span class="wdg-delivered-badge">✅ '+escHtml(o.delivered_date)+'</span>':'')+'</div>';
                html += '<div class="wdg-order-customer">'+escHtml(o.customer)+'</div></div>';
                html += '<div class="wdg-order-phone">'+escHtml(o.phone)+'</div>';
                html += '</div>';
            });

            // Bodega al final (retorno)
            if (hasDepot && wdgData.depot && wdgData.depot.lat) {
                html += '<div class="wdg-order-row wdg-depot-row-item">';
                html += '<div class="wdg-order-stop wdg-depot-stop">🏭</div>';
                html += '<div class="wdg-order-id" style="color:#555">Bodega</div>';
                html += '<div><div class="wdg-order-addr">'+escHtml(wdgData.depot.address)+'</div><div class="wdg-order-customer">Retorno</div></div>';
                html += '<div></div></div>';
            }

            html += '</div></div>';
        });

        $('#wdgGroupsList').html(html);

        // Poblar URLs reales en los inline link inputs
        // (la URL completa se obtiene de wdg_route_* via el token guardado)
        groups.forEach(function(g, i) {
            if (activeTokens[i]) {
                var url = window.location.origin + '/?wdg_ruta=' + activeTokens[i];
                // Si hay un link-row con una URL real, usarla
                var $lrInput = $('#link-row-'+i+' .wdg-link-input');
                if ($lrInput.length && $lrInput.val()) url = $lrInput.val();
                $('.wdg-link-input-'+i).val(url).data('url', url);
            }
        });

        // Copiar link inline
        $(document).off('click.inlinecopy').on('click.inlinecopy', '.wdg-copy-inline', function() {
            var idx = $(this).data('idx');
            var url = $('.wdg-link-input-'+idx).val();
            var $btn = $(this);
            navigator.clipboard.writeText(url).then(function() {
                $btn.text('✓').css('color','#15803d');
                setTimeout(function(){ $btn.text('📋').css('color',''); }, 2000);
            });
        });

        $('#wdgGroupsCard').show();
        window.wdgGroups   = groups;
        window.wdgHasDepot = hasDepot;
        currentGroups = groups;
        // Sugerir nombre del plan con la fecha
        var today = new Date();
        var dd = String(today.getDate()).padStart(2,'0');
        var mm = String(today.getMonth()+1).padStart(2,'0');
        if (!$('#wdgPlanName').val()) {
            $('#wdgPlanName').val('Reparto ' + dd + '/' + mm + ' ' + (today.getHours()<12?'mañana':'tarde'));
        }
        $('#wdgSavePlanCard').show();
    }

    // ── Mapa ──────────────────────────────────────────────────────────────────
    function clearMap() {
        markers.forEach(function(m){ m.setMap(null); });
        markers = [];
        infoWindows = [];
        polylines.forEach(function(p){ p.setMap(null); });
        polylines = [];
        if (depotMarker) { depotMarker.setMap(null); depotMarker = null; }
        $('#wdgLegend').hide().html('');
    }

    function renderMap(groups, hasDepot) {
        if (!map) { setTimeout(function(){ renderMap(groups, hasDepot); }, 500); return; }

        markers.forEach(function(m){ m.setMap(null); });
        infoWindows.forEach(function(w){ w.close(); });
        polylines.forEach(function(p){ p.setMap(null); });
        markers = []; infoWindows = []; polylines = [];

        var legendHtml = '<h3>Grupos</h3>';
        if (hasDepot && wdgData.depot.lat) {
            legendHtml += '<div class="wdg-legend-item"><span style="font-size:16px">🏭</span> Bodega (inicio/retorno)</div>';
        }

        groups.forEach(function(g, i) {
            var color = GROUP_COLORS[i % GROUP_COLORS.length];
            var kmInfo = g.route_km > 0 ? ' <span style="color:#888;font-size:10px">~'+g.route_km+'km</span>' : '';
            legendHtml += '<div class="wdg-legend-item"><div class="wdg-legend-dot" style="background:'+color+'"></div>'+escHtml(g.name)+' ('+g.count+')'+kmInfo+'</div>';

            // Polyline: bodega → paradas → bodega
            var path = [];
            if (hasDepot && wdgData.depot.lat) path.push({ lat: parseFloat(wdgData.depot.lat), lng: parseFloat(wdgData.depot.lng) });
            g.orders.forEach(function(o){ path.push({ lat: parseFloat(o.lat), lng: parseFloat(o.lng) }); });
            if (hasDepot && wdgData.depot.lat) path.push({ lat: parseFloat(wdgData.depot.lat), lng: parseFloat(wdgData.depot.lng) });

            if (path.length >= 2) {
                polylines.push(new google.maps.Polyline({
                    path: path, geodesic: true,
                    strokeColor: color, strokeOpacity: 0.5, strokeWeight: 2.5, map: map,
                }));
            }

            // Marcadores numerados
            g.orders.forEach(function(o, stopIdx) {
                var marker = new google.maps.Marker({
                    position: { lat: parseFloat(o.lat), lng: parseFloat(o.lng) },
                    map: map, title: '#'+o.id+' - '+o.address,
                    label: { text: String(stopIdx+1), color:'#fff', fontSize:'11px', fontWeight:'bold' },
                    icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: color, fillOpacity:0.95, strokeColor:'#fff', strokeWeight:1.5, scale:13 },
                    groupId: i, zIndex: 50,
                });

                (function(groupIdx, orderIdx, ord) {
                var iw = new google.maps.InfoWindow({
                    content: '<div style="font-size:12px;max-width:220px">'+
                             '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">'+
                             '<div style="width:20px;height:20px;border-radius:50%;background:'+color+';color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold">'+(orderIdx+1)+'</div>'+
                             '<strong>Parada '+(orderIdx+1)+' — #'+ord.id+'</strong></div>'+
                             escHtml(ord.address)+', '+escHtml(ord.city)+'<br>'+
                             '<span style="color:#555">'+escHtml(ord.customer)+'</span><br>'+
                             '<span style="color:#888;font-family:monospace">'+escHtml(ord.phone)+'</span><br>'+
                             '<div style="margin-top:8px;display:flex;align-items:center;justify-content:space-between">'+
                             '<span style="padding:2px 7px;background:'+color+';color:#fff;border-radius:3px;font-size:11px">'+escHtml(g.name)+'</span>'+
                             '<button onclick="wdgOpenMoveFromMap('+groupIdx+','+orderIdx+')" '+
                             'style="background:#2271b1;color:#fff;border:none;border-radius:4px;padding:3px 8px;font-size:11px;cursor:pointer">⇄ Mover</button>'+
                             '</div>'+
                             '</div>',
                });

                marker.addListener('click', function(){ infoWindows.forEach(function(w){ w.close(); }); iw.open(map, marker); });
                markers.push(marker); infoWindows.push(iw);
                })(i, stopIdx, o);
            });
        });

        // Redibujar marcador de bodega encima de todo
        if (hasDepot && wdgData.depot.lat) {
            placeDepotMarker(wdgData.depot.lat, wdgData.depot.lng, wdgData.depot.address);
        }

        $('#wdgLegend').html(legendHtml).show();

        var bounds = new google.maps.LatLngBounds();
        markers.forEach(function(m){ bounds.extend(m.getPosition()); });
        if (hasDepot && wdgData.depot.lat) bounds.extend({ lat: parseFloat(wdgData.depot.lat), lng: parseFloat(wdgData.depot.lng) });
        if (markers.length > 0) {
            // fitBounds con límite de zoom para no alejarse demasiado
            map.fitBounds(bounds);
            var listener = google.maps.event.addListenerOnce(map, 'idle', function() {
                if (map.getZoom() < 10) map.setZoom(11);
                // Verificar que el centro está en Santiago — si no, forzarlo
                var c = map.getCenter();
                if (!c || Math.abs(c.lat() + 33.45) > 3 || Math.abs(c.lng() + 70.65) > 3) {
                    map.setCenter({ lat: -33.45, lng: -70.65 });
                    map.setZoom(11);
                }
            });
        }
    }

    // ── Google Maps URL con bodega ────────────────────────────────────────────
    // Formato: origin=bodega, waypoints=paradas, destination=bodega (retorno)

    function buildMapsUrl(orders, hasDepot) {
        orders = (orders || []).filter(function(o){
            return o.lat && o.lng && parseFloat(o.lat) !== 0 && parseFloat(o.lng) !== 0;
        });
        if (orders.length < 1) return null;

        var depot     = wdgData.depot;
        var hasDepotCoords = hasDepot && depot && depot.lat && depot.lng;
        var MAX_WP    = 9;   // máx waypoints intermedios por URL (origen y destino no cuentan)
        var urls      = [];

        if (hasDepotCoords) {
            // Ruta: bodega → todas las paradas → bodega
            // Segmentada si hay más de MAX_WP paradas
            var origin      = depot.lat + ',' + depot.lng;
            var destination = depot.lat + ',' + depot.lng;

            for (var start = 0; start < orders.length; start += MAX_WP) {
                var chunk = orders.slice(start, start + MAX_WP);
                var wp    = chunk.map(function(o){ return o.lat+','+o.lng; }).join('|');

                var url = 'https://www.google.com/maps/dir/?api=1' +
                          '&origin='      + encodeURIComponent(start === 0 ? origin : (orders[start-1].lat+','+orders[start-1].lng)) +
                          '&destination=' + encodeURIComponent(start + MAX_WP >= orders.length ? destination : (orders[Math.min(start+MAX_WP, orders.length-1)].lat+','+orders[Math.min(start+MAX_WP, orders.length-1)].lng)) +
                          (wp ? '&waypoints=' + encodeURIComponent(wp) : '') +
                          '&travelmode=driving';
                urls.push(url);
            }
        } else {
            // Sin bodega: primera parada → última parada
            var MAX_CHUNK = MAX_WP + 2;
            for (var s = 0; s < orders.length; s += MAX_CHUNK - 1) {
                var seg  = orders.slice(s, s + MAX_CHUNK);
                if (seg.length < 2) break;
                var wp2  = seg.slice(1,-1).map(function(o){ return o.lat+','+o.lng; }).join('|');
                urls.push('https://www.google.com/maps/dir/?api=1' +
                    '&origin='      + encodeURIComponent(seg[0].lat+','+seg[0].lng) +
                    '&destination=' + encodeURIComponent(seg[seg.length-1].lat+','+seg[seg.length-1].lng) +
                    (wp2 ? '&waypoints=' + encodeURIComponent(wp2) : '') +
                    '&travelmode=driving');
            }
        }
        return urls.length > 0 ? urls : null;
    }

    // ── Funciones globales ────────────────────────────────────────────────────

    window.wdgToggleGroup = function(i) {
        var $b = $('#group-body-'+i);
        $b.toggleClass('open');
        $('#toggle-'+i).text($b.hasClass('open') ? '▲' : '▼');
    };

    window.wdgFocusGroup = function(i) {
        if (!map || !window.wdgGroups) return;
        var bounds = new google.maps.LatLngBounds();
        markers.forEach(function(m){ if (m.groupId === i) bounds.extend(m.getPosition()); });
        if (window.wdgHasDepot && wdgData.depot.lat)
            bounds.extend({ lat: parseFloat(wdgData.depot.lat), lng: parseFloat(wdgData.depot.lng) });
        map.fitBounds(bounds);
    };

    window.wdgPrintGroup = function(i) {
        if (!window.wdgGroups) return;
        var g        = window.wdgGroups[i];
        var hasDepot = window.wdgHasDepot;
        var color    = GROUP_COLORS[i % GROUP_COLORS.length];
        var mapsUrls = buildMapsUrl(g.orders, hasDepot);
        var mapsLinks = (mapsUrls && mapsUrls.length > 0)
            ? '<a href="'+mapsUrls[0]+'" style="color:#2271b1">Ver ruta Google Maps</a>'
            : '';

        var depotRow = (hasDepot && wdgData.depot.lat)
            ? '<tr style="background:#f0fdf4"><td colspan="7" style="padding:6px 8px;font-size:12px">🏭 <strong>Inicio/Retorno:</strong> '+escHtml(wdgData.depot.address)+'</td></tr>'
            : '';

        var rows = g.orders.map(function(o, idx) {
            // Fila principal — columnas: #, Pedido, Dirección, Comuna, Cliente, Teléfono, Nota
            var mainRow = '<tr style="border-bottom:1px solid #eee">'+
                   '<td style="padding:6px 8px;text-align:center"><div style="width:22px;height:22px;border-radius:50%;background:'+color+';color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold">'+(idx+1)+'</div></td>'+
                   '<td style="padding:6px 8px;font-weight:600">#'+o.id+'</td>'+
                   '<td style="padding:6px 8px">'+escHtml(o.address)+'</td>'+
                   '<td style="padding:6px 8px;color:#64748b">'+escHtml(o.city||'')+'</td>'+
                   '<td style="padding:6px 8px">'+escHtml(o.customer)+'</td>'+
                   '<td style="padding:6px 8px;font-family:monospace">'+escHtml(o.phone)+'</td>'+
                   '<td style="padding:6px 8px;font-size:11px;color:#7c3aed">'+escHtml(o.note||'')+'</td>'+
                   '</tr>';
            // Fila de productos
            var prodRow = '';
            if (o.items && o.items.length) {
                var prodList = o.items.map(function(it){
                    var qtyColor = it.qty > 1 ? 'color:#dc2626;font-weight:700' : 'font-weight:600;color:#374151';
                    return '<div style="padding:2px 0">'+escHtml(it.name)+' <span style="'+qtyColor+'">x'+it.qty+'</span></div>';
                }).join('');
                prodRow = '<tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">'+
                    '<td></td>'+
                    '<td colspan="6" style="padding:6px 8px 8px;font-size:13px;color:#374151">'+prodList+'</td>'+
                    '</tr>';
            }
            return mainRow + prodRow;
        }).join('');

        var win = window.open('', '_blank');
        win.document.write(
            '<!DOCTYPE html><html><head><title>Ruta '+escHtml(g.name)+'</title>'+
            '<style>body{font-family:Arial,sans-serif;padding:24px;color:#222;font-size:13px}table{width:100%;border-collapse:collapse}th{background:#f5f5f5;padding:8px;text-align:left;font-size:11px;text-transform:uppercase}@media print{.no-print{display:none}}</style>'+
            '</head><body>'+
            '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">'+
            '<div style="width:20px;height:20px;border-radius:50%;background:'+color+'"></div>'+
            '<h1 style="margin:0;font-size:20px">Ruta — '+escHtml(g.name)+'</h1></div>'+
            '<p style="color:#666;margin-bottom:4px">Paradas: <strong>'+g.count+'</strong>'+
            (g.route_km>0?' &nbsp;·&nbsp; Distancia total: <strong>~'+g.route_km+' km</strong>'+(hasDepot?' (incluye retorno a bodega)':''):'')+'<br>'+
            (hasDepot&&wdgData.depot.lat?'🏭 Bodega: <strong>'+escHtml(wdgData.depot.address)+'</strong><br>':'')+'</p>'+
            (mapsLinks?'<p style="margin-bottom:16px">'+mapsLinks+'</p>':'')+
            '<table><thead><tr><th></th><th>Pedido</th><th>Dirección</th><th>Comuna</th><th>Cliente</th><th>Teléfono</th><th>Nota</th></tr></thead>'+
            '<tbody>'+depotRow+rows+depotRow+'</tbody></table>'+
            '<div class="no-print" style="margin-top:16px"><button onclick="window.print()">🖨️ Imprimir</button></div>'+
            '</body></html>'
        );
        win.document.close();
    };

    window.wdgExportCSV = function(i) {
        if (!window.wdgGroups) return;
        var g    = window.wdgGroups[i];
        var rows = [['Parada','Pedido','Dirección','Comuna','Cliente','Teléfono','Nota','Productos','Lat','Lng']];
        if (window.wdgHasDepot && wdgData.depot.lat)
            rows.push(['BODEGA (INICIO)','—',wdgData.depot.address,'','','','','',wdgData.depot.lat,wdgData.depot.lng]);
        g.orders.forEach(function(o,idx){
            var prods = (o.items||[]).map(function(it){ return it.name+' x'+it.qty; }).join(' | ');
            rows.push([idx+1,o.id,o.address,o.city||'',o.customer,o.phone,o.note||'',prods,o.lat,o.lng]);
        });
        if (window.wdgHasDepot && wdgData.depot.lat)
            rows.push(['BODEGA (RETORNO)','—',wdgData.depot.address,'','','','','',wdgData.depot.lat,wdgData.depot.lng]);

        var csv  = rows.map(function(r){ return r.map(function(c){ return '"'+String(c).replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
        var blob = new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'});
        var a    = document.createElement('a');
        a.href   = URL.createObjectURL(blob);
        a.download = 'ruta_'+(i+1)+'_'+g.name.replace(/\s+/g,'-')+'.csv';
        a.click();
    };

    window.wdgGenerateLink = function(i) {
        if (!window.wdgGroups) return;
        if (!planIsSaved || !currentPlanId) {
            alert('⚠️ Debes guardar la planificación antes de generar el link del conductor.');
            return;
        }
        var g   = window.wdgGroups[i];
        var $row = $('#link-row-'+i);

        $row.html('<span style="font-size:12px;color:#0369a1;padding:8px 12px;display:block">⏳ Generando enlace...</span>').show();

        // Obtener driver_id del select correspondiente
        var $select   = $('.wdg-rep-select[data-idx="'+i+'"]');
        var driver_id = $select.length ? $select.val() : '';

        // Incluir nombre del repartidor y vehículo en el token
        var driverName = '';
        var drv2 = driver_id ? wdgDrivers.find(function(d){ return d.id === driver_id; }) : null;
        if (drv2) driverName = drv2.nombre;

        var $vselect  = $('.wdg-veh-select[data-idx="'+i+'"]');
        var vehicle_id = $vselect.length ? $vselect.val() : '';
        var vehicleLabel = '';
        if (vehicle_id) {
            var veh = wdgVehicles.find(function(v){ return v.id === vehicle_id; });
            if (veh) vehicleLabel = veh.patente + ' (' + veh.tipo + ')';
        }

        $.post(wdgData.ajaxUrl, {
            action:      'wdg_save_token',
            nonce:       wdgData.nonce,
            group:       JSON.stringify(g),
            plan_id:     currentPlanId   || '',
            plan_name:   $('#wdgPlanName').val() || '',
            driver_id:   driver_id,
            driver_name: driverName,
            vehicle:     vehicleLabel,
        }, function(res) {
            if (!res.success) {
                $row.html('<span style="color:#b91c1c;font-size:12px;padding:8px 12px;display:block">❌ ' + res.data + '</span>');
                return;
            }
            var url    = res.data.url;
            var expiry = res.data.expiry;
            var token  = res.data.token;
            activeTokens[i] = token;
            showProgressPanel();
            // Construir HTML sin inyectar url en atributos (evita quoting issues)
            var $box = $(
                '<div class="wdg-link-box">'+
                '<div class="wdg-link-label">📲 Link para '+escHtml(g.name)+' <span class="wdg-link-expiry">Expira: '+expiry+'</span></div>'+
                '<div class="wdg-link-url-row">'+
                '<input type="text" class="wdg-link-input" readonly>'+
                '<button class="button wdg-copy-btn wdg-copy-url">📋 Copiar</button>'+
                '<a class="button wdg-open-url" href="#" target="_blank">↗ Abrir</a>'+
                '</div>'+
                '</div>'
            );
            $box.find('.wdg-link-input').val(url).on('click', function(){ this.select(); });
            $box.find('.wdg-copy-url').data('url', url);
            $box.find('.wdg-open-url').attr('href', url);
            $row.html($box);
            // Actualizar inline link input si existe en la cabecera del grupo
            $('.wdg-link-input-'+i).val(url).data('url', url);
        }).fail(function() {
            $row.html('<span style="color:#b91c1c;font-size:12px;padding:8px 12px;display:block">❌ Error de conexión</span>');
        });
    };

    $(document).on('click', '.wdg-copy-url', function() {
        var url = $(this).data('url');
        var $btn = $(this);
        navigator.clipboard.writeText(url).then(function() {
            $btn.text('✓ Copiado').css('color', '#15803d');
            setTimeout(function(){ $btn.text('📋 Copiar').css('color', ''); }, 2000);
        });
    });

    window.wdgCopyLink = function(btn, url) {
        navigator.clipboard.writeText(url).then(function() {
            $(btn).text('✓ Copiado').css('color', '#15803d');
            setTimeout(function(){ $(btn).text('📋 Copiar').css('color', ''); }, 2000);
        });
    };

    // ── Panel de progreso ─────────────────────────────────────────────────────

    function showProgressPanel() {
        $('#wdgProgressCard').show();
        loadProgress();
        // Auto-refresh cada 30 segundos
        if (progressTimer) clearInterval(progressTimer);
        progressTimer = setInterval(loadProgress, 30000);
        $('#btnRefreshProgress').on('click', loadProgress);
    }

    function loadProgress() {
        var tokens = Object.values(activeTokens);
        if (!tokens.length) return;

        $.post(wdgData.ajaxUrl, {
            action: 'wdg_get_progress',
            nonce:  wdgData.nonce,
            tokens: JSON.stringify(tokens),
        }, function(res) {
            if (!res.success) return;
            renderProgress(res.data);
        });
    }

    function renderProgress(data) {
        // Actualizar marcadores del mapa admin con el progreso actual
        updateAdminMarkers(data);

        var html = '';
        $.each(data, function(token, g) {
            var color   = GROUP_COLORS[Object.keys(data).indexOf(token) % GROUP_COLORS.length];
            var pct     = g.pct;
            var updated = g.last_update ? ' · última actualización: ' + g.last_update : ' · sin actividad aún';

            html += '<div class="wdg-prog-group">';
            // Cabecera
            html += '<div class="wdg-prog-header">';
            html += '<div class="wdg-prog-dot" style="background:'+color+'"></div>';
            html += '<div class="wdg-prog-name">'+escHtml(g.name)+'</div>';
            html += '<div class="wdg-prog-pct">'+g.done+'/'+g.total+' ('+pct+'%)</div>';
            html += '</div>';
            // Barra
            html += '<div class="wdg-prog-bar-wrap"><div class="wdg-prog-bar-fill" style="width:'+pct+'%;background:'+color+'"></div></div>';
            html += '<div class="wdg-prog-updated">'+escHtml(updated)+'</div>';
            // Lista de paradas
            html += '<div class="wdg-prog-stops">';
            if (g.orders && g.orders.length) {
                g.orders.forEach(function(o, idx) {
                    var isDone = g.progress && g.progress[String(idx)];
                    var icon   = isDone ? '✅' : '⬜';
                    var cls    = isDone ? ' wdg-stop-done' : '';
                    html += '<div class="wdg-stop-row'+cls+'">';
                    html += '<span class="wdg-stop-icon">'+icon+'</span>';
                    html += '<span class="wdg-stop-num">'+(idx+1)+'</span>';
                    html += '<span class="wdg-stop-addr">'+escHtml(o.address||'')+(o.city?', '+escHtml(o.city):'')+'</span>';
                    html += '<span class="wdg-stop-customer">'+escHtml(o.customer||'')+'</span>';
                    html += '</div>';
                });
            }
            html += '</div>';
            html += '</div>';
        });

        if (!html) html = '<p style="color:#94a3b8;font-size:13px;padding:8px 0">Aún no hay progreso registrado. El panel se actualiza cuando los conductores marcan paradas.</p>';
        $('#wdgProgressList').html(html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GESTIÓN DE PLANIFICACIONES
    // ══════════════════════════════════════════════════════════════════════════

    // ── Guardar plan ──────────────────────────────────────────────────────────
    window.wdgSavePlan = function() {
        var name = $('#wdgPlanName').val().trim();
        if (!name) { alert('Ingresa un nombre para la planificación.'); return; }
if (!currentGroups.length) { alert('Genera los grupos primero.'); return; }

        // Alerta informativa antes de guardar
        if (!planIsSaved) {
var ok = confirm('⚠️ Importante: Al guardar la planificación, los pedidos quedarán fijos a cada repartidor.\n\nUna vez guardado, NO podrás mover pedidos entre rutas.\n\n¿Deseas continuar y guardar?');
            if (!ok) return;
        }

        $('#btnSavePlan').prop('disabled', true).text('Guardando…');
        $('#wdgSavePlanStatus').html('');

        $.post(wdgData.ajaxUrl, {
            action:   'wdg_save_plan',
            nonce:    wdgData.nonce,
            plan_id:  currentPlanId || '',
            plan_name: name,
            config:   JSON.stringify(currentConfig),
            groups:   JSON.stringify(currentGroups),
            tokens:   JSON.stringify(activeTokens),
        }, function(res) {
            $('#btnSavePlan').prop('disabled', false).text('💾 Guardar');
            if (res.success) {
                currentPlanId = res.data.plan_id;
                planIsSaved   = true;
                $('#wdgSavePlanStatus').html('<span style="color:#15803d">✅ Planificación guardada: <strong>' + escHtml(res.data.name) + '</strong></span>');
                $('#btnSavePlan').text('💾 Actualizar');
                $('#wdgAddOrdersCard').show();
            } else {
                $('#wdgSavePlanStatus').html('<span style="color:#b91c1c">❌ ' + escHtml(res.data) + '</span>');
            }
        }).fail(function() {
            $('#btnSavePlan').prop('disabled', false).text('💾 Guardar');
            $('#wdgSavePlanStatus').html('<span style="color:#b91c1c">❌ Error de conexión</span>');
        });
    };

    // ── Cargar lista de planes ────────────────────────────────────────────────
    function loadPlansList() {
        $('#wdgPlansList').html('<p style="color:#94a3b8;padding:16px">Cargando planificaciones…</p>');
        $.post(wdgData.ajaxUrl, {
            action: 'wdg_get_plans',
            nonce:  wdgData.nonce,
        }, function(res) {
            if (!res.success) return;
            renderPlansList(res.data);
        });
    }

    var plansPage = 0;
    var plansPageSize = 20;
    var allPlansData = [];

    function renderPlansList(plans) {
        allPlansData = plans || [];
        plansPage    = 0;
        renderPlansPage();
    }

    function renderPlansPage() {
        var plans = allPlansData;
        if (!plans || !plans.length) {
            $('#wdgPlansList').html(
                '<div class="wdg-plans-empty">' +
                '<div style="font-size:40px;margin-bottom:8px">📭</div>' +
                '<div style="font-size:15px;font-weight:500;margin-bottom:4px">Sin planificaciones guardadas</div>' +
                '<div style="font-size:13px;color:#94a3b8">Crea una nueva planificación y guárdala para verla aquí.</div>' +
                '<button class="button button-primary" style="margin-top:16px" onclick="wdgNewPlan()">➕ Crear primera planificación</button>' +
                '</div>'
            );
            return;
        }

        var start = plansPage * plansPageSize;
        var pagedPlans = allPlansData.slice(start, start + plansPageSize);
        var html = '<div class="wdg-plans-grid">';
        pagedPlans.forEach(function(p) {
            var pct      = p.pct || 0;
            var barColor = pct >= 100 ? '#4caf50' : '#2271b1';
            var status   = pct >= 100 ? '✅ Completado' : (pct > 0 ? '🚚 En curso' : '📋 Sin iniciar');
            var cfgStr   = '';
            if (p.config) {
                cfgStr = escHtml(p.config.date_from||'') + (p.config.date_to !== p.config.date_from ? ' → '+escHtml(p.config.date_to||'') : '');
            }
            html += '<div class="wdg-plan-card" id="plan-'+escHtml(p.id)+'">';
            html += '<div class="wdg-plan-card-header">';
            html += '<div class="wdg-plan-name">'+escHtml(p.name)+'</div>';
            html += '<div class="wdg-plan-status">'+status+'</div>';
            html += '</div>';
            html += '<div class="wdg-plan-meta">';
            html += '<span class="wdg-plan-id" title="ID del plan">🔑 '+escHtml(p.id)+'</span>';
            html += '<span>📅 '+escHtml(p.date_label)+'</span>';
            if (cfgStr) html += '<span>🗓 '+cfgStr+'</span>';
            html += '<span>👥 '+p.groups+' grupos · '+p.total+' pedidos</span>';
            html += '</div>';
            html += '<div class="wdg-plan-progress">';
            html += '<div class="wdg-plan-bar-wrap"><div class="wdg-plan-bar-fill" style="width:'+pct+'%;background:'+barColor+'"></div></div>';
            html += '<div class="wdg-plan-pct">'+p.done+'/'+p.total+' entregados ('+pct+'%)</div>';
            html += '</div>';
            html += '<div class="wdg-plan-actions">';
            html += '<button class="button button-primary wdg-load-plan" data-id="'+escHtml(p.id)+'">📂 Abrir</button>';
            html += '<button class="button wdg-delete-plan" data-id="'+escHtml(p.id)+'" data-name="'+escHtml(p.name)+'">🗑 Eliminar</button>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';

        // Paginación si hay más de 20 planes
        var total = allPlansData.length;
        var start = plansPage * plansPageSize;
        var end   = Math.min(start + plansPageSize, total);
        plans     = allPlansData.slice(start, end);

        if (total > plansPageSize) {
            html += '<div class="wdg-plans-pagination">';
            if (plansPage > 0) html += '<button class="button" onclick="wdgPlansPage('+(plansPage-1)+')">← Anterior</button>';
            html += '<span style="font-size:12px;color:#64748b">'+(start+1)+'-'+end+' de '+total+'</span>';
            if (end < total) html += '<button class="button" onclick="wdgPlansPage('+(plansPage+1)+')">Siguiente →</button>';
            html += '</div>';
        }

        $('#wdgPlansList').html(html);
    }

    window.wdgPlansPage = function(page) {
        plansPage = page;
        renderPlansPage();
        $('#wdg-tab-plans').scrollTop(0);
    };

    // ── Nueva planificación (ir a tab new) ────────────────────────────────────
    function resetNewPlan() {
        clearMap();
        window.wdgOrders  = [];
        window.wdgGroups  = [];
        currentGroups     = [];
        currentPlanId     = null;
        planIsSaved       = false;
        activeTokens      = {};
        switchTab('new');
        $('#wdgGroupsCard, #wdgStatsCard, #wdgSavePlanCard, #wdgProgressCard, #wdgRepartidoresCard, #wdgAddOrdersCard').hide();
        $('#wdgNewOrdersPanel').hide();
        $('#wdgAddOrdersStatus').html('');
        $('#wdgPlanName').val('');
        $('#wdgSavePlanStatus').html('');
        $('#btnSavePlan').text('💾 Guardar');
        $('#btnProcess').prop('disabled', true);
        // Resetear fechas a ayer/hoy
        var now  = new Date();
        var ayer = new Date(now); ayer.setDate(now.getDate() - 1);
        var fmt  = function(d){ return d.toISOString().split('T')[0]; };
        $('#wdgDateFrom').val(fmt(ayer));
        $('#wdgDateTo').val(fmt(now));
    }

    window.wdgNewPlan = function() {
        var hasUnsaved = currentGroups.length > 0 && !planIsSaved;
        var hasSaved   = currentGroups.length > 0 && planIsSaved;

        if (hasUnsaved) {
            // Hay grupos generados sin guardar → preguntar
            var choice = confirm('⚠️ Tienes una planificación sin guardar.\n\n¿Descartar y crear una nueva?\n\n(Presiona Cancelar para volver y guardarla primero)');
            if (!choice) return; // volver sin hacer nada
            resetNewPlan();
        } else if (hasSaved) {
            // Plan guardado → crear nueva directamente
            resetNewPlan();
        } else {
            // Sin plan en curso → ir directo
            resetNewPlan();
        }
    };

    // ── Cargar planificación guardada ─────────────────────────────────────────
    window.wdgLoadPlan = function(planId) {
        $.post(wdgData.ajaxUrl, {
            action:  'wdg_load_plan',
            nonce:   wdgData.nonce,
            plan_id: planId,
        }, function(res) {
            if (!res.success) { alert('Error: ' + res.data); return; }
var plan = res.data;

            // Restaurar estado
            currentPlanId = plan.id;
            currentGroups = plan.groups;
            planIsSaved   = true;  // plan ya guardado → permitir generar links
            currentConfig = plan.config || {};

            // Restaurar tokens
            activeTokens = {};
            plan.groups.forEach(function(g, i) {
if (g.token) activeTokens[i] = g.token;
            });

            // Restaurar config en el form
            if (plan.config) {
                $('#wdgDateFrom').val(plan.config.date_from || '');
                $('#wdgDateTo').val(plan.config.date_to || '');
                $('#wdgStatus').val(plan.config.status || 'any');
                $('#wdgGroups').val(plan.config.k || 3);
                $('#wdgMaxPerGroup').val(plan.config.max_per_group || 35);
                $('#wdgNames').val(plan.config.names || '');
                // Actualizar botones K
                $('.wdg-kbtn').removeClass('wdg-kbtn--active');
                $('.wdg-kbtn[data-val="'+(plan.config.k||3)+'"]').addClass('wdg-kbtn--active');
            }

            // Restaurar depot
            if (plan.depot) wdgData.depot = plan.depot;

            // Renderizar grupos y mapa
            renderStats(
                currentGroups.reduce(function(s,g){ return s+g.count; },0),
                0, currentGroups, plan.config.max_per_group||35, !!plan.depot
            );
            renderGroups(currentGroups, !!plan.depot);
            // Esperar a que el mapa esté listo antes de renderizar marcadores
            if (map) {
                renderMap(currentGroups, !!plan.depot);
            } else {
                var mapWait = setInterval(function() {
                    if (map) {
                        clearInterval(mapWait);
                        renderMap(currentGroups, !!plan.depot);
                    }
                }, 200);
            }

            // Nombre del plan en el input
            $('#wdgPlanName').val(plan.name);
            $('#btnSavePlan').text('💾 Actualizar');
            $('#wdgSavePlanCard').show();
            $('#wdgAddOrdersCard').show();
            $('#wdgNewOrdersPanel').hide();
            $('#wdgAddOrdersStatus').html('');

            // Progreso
            showProgressPanel();

            // Cambiar a tab de nueva planificación (donde está el mapa y grupos)
            switchTab('new');
        });
    };

    // ══ AÑADIR PEDIDOS NUEVOS A UN PLAN ════════════════════════════════════════
    var wdgNewOrders = []; // pedidos nuevos detectados, cada uno con .group_idx

    function wdgKm(lat1, lng1, lat2, lng2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180) *
                Math.sin(dLng/2) * Math.sin(dLng/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // Ruta cuyo centro queda más cerca, respetando el máximo por ruta
    function wdgNearestGroup(order) {
        var maxPer = parseInt((currentConfig && currentConfig.max_per_group) || 35);
        var best = -1, bestD = Infinity;       // con cupo disponible
        var bestAny = -1, bestAnyD = Infinity; // ignorando cupo (fallback)
        currentGroups.forEach(function(g, gi) {
            var c = g.center || {};
            if (c.lat == null) return;
            var d = wdgKm(+order.lat, +order.lng, +c.lat, +c.lng);
            if (d < bestAnyD) { bestAnyD = d; bestAny = gi; }
            if ((g.count || 0) < maxPer && d < bestD) { bestD = d; best = gi; }
        });
        return best >= 0 ? best : (bestAny >= 0 ? bestAny : 0);
    }

    window.wdgDetectNewOrders = function() {
        if (!currentPlanId) { alert('Primero carga o guarda una planificación.'); return; }
        $('#btnDetectNew').prop('disabled', true).text('Buscando…');
        $('#wdgAddOrdersStatus').html('');
        $('#wdgNewOrdersPanel').hide();

        $.post(wdgData.ajaxUrl, {
            action:  'wdg_new_orders',
            nonce:   wdgData.nonce,
            plan_id: currentPlanId,
        }, function(res) {
            $('#btnDetectNew').prop('disabled', false).text('🔍 Buscar pedidos nuevos');
            if (!res.success) {
                $('#wdgAddOrdersStatus').html('<span style="color:#b91c1c">❌ ' + escHtml(res.data) + '</span>');
                return;
            }
            var orders = res.data.orders || [];
            var rango  = '(rango ' + escHtml(res.data.date_from) + ' → ' + escHtml(res.data.date_to) + ')';
            if (!orders.length) {
                $('#wdgAddOrdersStatus').html('<span style="color:#15803d">✅ No hay pedidos nuevos sin asignar ' + rango + '.</span>');
                return;
            }
            // Asignación automática a la ruta más cercana
            wdgNewOrders = orders.map(function(o) {
                o.group_idx = wdgNearestGroup(o);
                return o;
            });
            $('#wdgAddOrdersStatus').html('<span style="color:#0369a1"><strong>' + orders.length +
                '</strong> pedido(s) nuevo(s) ' + rango + '. Revisa la asignación y confirma.</span>');
            wdgRenderNewOrders();
            $('#wdgNewOrdersPanel').show();
        }).fail(function() {
            $('#btnDetectNew').prop('disabled', false).text('🔍 Buscar pedidos nuevos');
            $('#wdgAddOrdersStatus').html('<span style="color:#b91c1c">❌ Error de conexión</span>');
        });
    };

    function wdgRenderNewOrders() {
        var html = '<table class="wdg-new-orders-table"><thead><tr>' +
                   '<th>Pedido</th><th>Dirección</th><th>Ruta asignada</th></tr></thead><tbody>';
        wdgNewOrders.forEach(function(o, i) {
            var opts = '';
            currentGroups.forEach(function(g, gi) {
                opts += '<option value="' + gi + '"' + (gi === o.group_idx ? ' selected' : '') + '>' +
                        escHtml(g.name) + ' (' + (g.count || 0) + ')</option>';
            });
            var dot = GROUP_COLORS[o.group_idx % GROUP_COLORS.length];
            html += '<tr>' +
                    '<td><a href="' + wdgData.adminUrl + 'post.php?post=' + o.id + '&action=edit" target="_blank">#' + o.id + '</a></td>' +
                    '<td>' + escHtml(o.address) + (o.city ? ', ' + escHtml(o.city) : '') + '</td>' +
                    '<td><span class="wdg-move-dot" style="background:' + dot + '"></span>' +
                    '<select class="wdg-new-order-group" data-i="' + i + '">' + opts + '</select></td>' +
                    '</tr>';
        });
        html += '</tbody></table>';
        $('#wdgNewOrdersList').html(html);
    }

    $(document).on('change', '.wdg-new-order-group', function() {
        var i = parseInt($(this).data('i'));
        wdgNewOrders[i].group_idx = parseInt($(this).val());
        wdgRenderNewOrders(); // refresca el color del punto
    });

    window.wdgConfirmAppend = function() {
        if (!wdgNewOrders.length) return;
        $('#btnConfirmAppend').prop('disabled', true).text('Reoptimizando…');
        $.post(wdgData.ajaxUrl, {
            action:  'wdg_append_orders',
            nonce:   wdgData.nonce,
            plan_id: currentPlanId,
            orders:  JSON.stringify(wdgNewOrders),
        }, function(res) {
            $('#btnConfirmAppend').prop('disabled', false).text('✅ Confirmar y reoptimizar');
            if (!res.success) {
                $('#wdgAddOrdersStatus').html('<span style="color:#b91c1c">❌ ' + escHtml(res.data) + '</span>');
                return;
            }
            wdgNewOrders = [];
            $('#wdgNewOrdersPanel').hide();
            $('#wdgAddOrdersStatus').html('<span style="color:#15803d">✅ ' + res.data.added +
                ' pedido(s) añadido(s) y rutas reoptimizadas. El enlace del conductor se mantiene.</span>');
            // Recargar el plan para reflejar grupos, mapa y progreso actualizados
            window.wdgLoadPlan(currentPlanId);
        }).fail(function() {
            $('#btnConfirmAppend').prop('disabled', false).text('✅ Confirmar y reoptimizar');
            $('#wdgAddOrdersStatus').html('<span style="color:#b91c1c">❌ Error de conexión</span>');
        });
    };

    // ── Eliminar planificación ────────────────────────────────────────────────
    window.wdgDeletePlan = function(planId, planName) {
        if (!confirm('¿Eliminar la planificación "' + planName + '"? Esta acción no se puede deshacer.')) return;

        $.post(wdgData.ajaxUrl, {
            action:  'wdg_delete_plan',
nonce:   wdgData.nonce,
plan_id: planId,
        }, function(res) {
if (res.success) {
                $('#plan-'+planId).fadeOut(300, function(){ $(this).remove(); });
                if (!$('.wdg-plan-card').length) loadPlansList();
            } else {
                alert('Error: ' + res.data);
            }
        });
    };

    // ── Switch de pestañas ────────────────────────────────────────────────────
    function switchTab(tab) {
        $('.wdg-tab').removeClass('wdg-tab--active');
        $('.wdg-tab-content').hide();
        $('.wdg-tab[data-tab="'+tab+'"]').addClass('wdg-tab--active');
        $('#wdg-tab-'+tab).show();
        if (tab === 'plans')     loadPlansList();
        if (tab === 'log')      wdgLoadLog();
        if (tab === 'drivers')  loadDrivers(renderDriversList);
        if (tab === 'vehicles') loadVehicles(renderVehiclesList);
        if (tab === 'analytics'){ loadDrivers(function(){ initAnalyticsFilters(); }); }
        if (tab === 'config')    loadConfigTab();
    }

    // Delegated handlers for plan card buttons (avoid inline onclick quoting issues)
    $(document).on('click', '.wdg-load-plan', function() {
        wdgLoadPlan($(this).data('id'));
    });
    $(document).on('click', '.wdg-delete-plan', function() {
        wdgDeletePlan($(this).data('id'), $(this).data('name'));
    });

    $(document).on('click', '.wdg-tab', function(e) {
        e.preventDefault();
        switchTab($(this).data('tab'));
    });

    // ── Configuración ────────────────────────────────────────────────────────
    function loadConfigTab() {
        // Valores ya renderizados por PHP
    }

    $('#btnSaveConfig').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Guardando…');
        $.post(wdgData.ajaxUrl, {
            action:           'wdg_save_config',
            nonce:            wdgData.nonce,
            send_photo_email: $('#wdgSendPhotoEmail').is(':checked') ? '1' : undefined,
        }, function(res) {
            $btn.prop('disabled', false).text('💾 Guardar');
            if (res.success) {
                var estado = res.data.send_photo_email === '1' ? 'activado' : 'desactivado';
                $('#wdgConfigStatus').html('<span class="wdg-depot-ok">✅ Correo con foto ' + estado + '</span>');
            } else {
                $('#wdgConfigStatus').html('<span style="color:#b91c1c">❌ ' + (res.data||'Error') + '</span>');
            }
        });
    });

    $('#btnSaveApiKey').on('click', function() {
        var key = $('#wdgConfigApiKey').val().trim();
        if (!key) { alert('Ingresa una API Key.'); return; }
        var $btn = $(this).prop('disabled', true).text('Guardando…');
        $.post(wdgData.ajaxUrl, { action: 'wdg_save_api_key', nonce: wdgData.nonce, api_key: key }, function(res) {
            $btn.prop('disabled', false).text('💾 Guardar API Key');
            if (res.success) {
                $('#wdgApiKeyStatus').html('<span class="wdg-depot-ok">✅ API Key guardada. Recargando…</span>');
                setTimeout(function(){ window.location.reload(); }, 800);
            } else {
                $('#wdgApiKeyStatus').html('<span style="color:#b91c1c">❌ ' + (res.data||'Error') + '</span>');
            }
        });
    });

    $('#btnTestApiKey').on('click', function() {
        var key = $('#wdgConfigApiKey').val().trim();
        if (!key) { alert('Ingresa una API Key primero.'); return; }
        var $btn = $(this).prop('disabled', true).text('Probando…');
        $.post(wdgData.ajaxUrl, { action: 'wdg_test_api_key', nonce: wdgData.nonce, api_key: key }, function(res) {
            $btn.prop('disabled', false).text('🔍 Probar');
            var color = res.success ? '#15803d' : '#b91c1c';
            $('#wdgApiKeyStatus').html('<span style="color:'+color+'">' + (res.data.message || res.data) + '</span>');
        });
    });

    // Cargar datos al iniciar
    loadPlansList();
    loadDrivers(function(){});
    loadVehicles(function(){});

    // Fechas por defecto: ayer → hoy
    (function() {
        var now  = new Date();
        var ayer = new Date(now); ayer.setDate(now.getDate() - 1);
        var fmt  = function(d) { return d.toISOString().split('T')[0]; };
        $('#wdgDateFrom').val(fmt(ayer));
        $('#wdgDateTo').val(fmt(now));
    })();

    // Auto-guardar tokens cuando se generan (actualizar plan si ya existe).
    // IMPORTANTE: el save se hace DENTRO del callback del token (no con setTimeout)
    // para garantizar que activeTokens[i] ya tiene el nuevo token antes de guardar.
    var origSaveToken = window.wdgGenerateLink;
    window.wdgGenerateLink = function(i) {
        // Interceptar: envolver la función original para enganchar post-éxito
        var origFn = window.wdgGenerateLink._orig || origSaveToken;
        // Llamar la función original y luego escuchar activeTokens
        origFn(i);
        // Observar hasta que el token aparezca en activeTokens (máx 10s)
        if (currentPlanId) {
            var prevToken = activeTokens[i];
            var waited   = 0;
            var check    = setInterval(function() {
                waited += 200;
                if (activeTokens[i] && activeTokens[i] !== prevToken) {
                    clearInterval(check);
                    $.post(wdgData.ajaxUrl, {
                        action:    'wdg_save_plan',
                        nonce:     wdgData.nonce,
                        plan_id:   currentPlanId,
                        plan_name: $('#wdgPlanName').val(),
                        config:    JSON.stringify(currentConfig),
                        groups:    JSON.stringify(currentGroups),
                        tokens:    JSON.stringify(activeTokens),
                    });
                }
                if (waited >= 10000) clearInterval(check);
            }, 200);
        }
    };

    // ══ LOG DE ACTIVIDAD ══════════════════════════════════════════════════════

    window.wdgLoadLog = function() {
        $('#wdgLogPanel').html('<span style="color:#94a3b8;font-size:12px">Cargando…</span>');
        $.post(wdgData.ajaxUrl, { action:'wdg_get_log', nonce:wdgData.nonce }, function(res) {
            if (!res.success || !res.data.length) {
                $('#wdgLogPanel').html('<span style="color:#94a3b8;font-size:12px">Sin entradas en el log.</span>');
                return;
            }
            var html = '<table class="wdg-log-table"><thead><tr><th>Hora</th><th>Nivel</th><th>Mensaje</th><th>Contexto</th></tr></thead><tbody>';
            res.data.forEach(function(e) {
                var cls = { 'OK':'log-ok', 'ERROR':'log-err', 'WARN':'log-warn', 'INFO':'log-info' }[e.level] || '';
                var ctx = e.context && Object.keys(e.context).length
                    ? '<pre class="log-ctx">' + escHtml(JSON.stringify(e.context, null, 2)) + '</pre>' : '';
                html += '<tr class="'+cls+'"><td>'+escHtml(e.time)+'</td><td><span class="log-badge '+cls+'">'+escHtml(e.level)+'</span></td><td>'+escHtml(e.message)+'</td><td>'+ctx+'</td></tr>';
            });
            html += '</tbody></table>';
            $('#wdgLogPanel').html(html);
        });
    };

    window.wdgClearLog = function() {
        if (!confirm('¿Limpiar el log?')) return;
        $.post(wdgData.ajaxUrl, { action:'wdg_clear_log', nonce:wdgData.nonce }, function() {
            $('#wdgLogPanel').html('<span style="color:#94a3b8;font-size:12px">Log limpiado.</span>');
        });
    };


    // ══ MOVER PEDIDO ENTRE GRUPOS ══════════════════════════════════════════════

    var wdgMoveState = { orderIdx: null, fromGroup: null, toGroup: null };

    window.wdgOpenMoveFromMap = function(groupIdx, orderIdx) {
        if (planIsSaved) {
            alert('⛔ La planificación ya fue guardada. No se pueden mover pedidos entre rutas.');
            infoWindows.forEach(function(w){ w.close(); });
            return;
        }
        // Cerrar InfoWindow
        infoWindows.forEach(function(w){ w.close(); });
        // Reutilizar el mismo modal con los mismos datos
        wdgMoveState.orderIdx  = orderIdx;
        wdgMoveState.fromGroup = groupIdx;
        wdgMoveState.toGroup   = null;

        var groups = window.wdgGroups;
        var order  = groups[groupIdx].orders[orderIdx];

        $('#wdg-move-addr').text('#' + order.id + ' — ' + order.address + ', ' + order.city);
        $('#wdg-move-confirm').prop('disabled', true);

        var html = '';
        groups.forEach(function(g, gi) {
            var color  = GROUP_COLORS[gi % GROUP_COLORS.length];
            var isCurr = gi === groupIdx;
            var cls    = isCurr ? 'wdg-move-btn current' : 'wdg-move-btn';
            html += '<button class="'+cls+'" data-gi="'+gi+'" onclick="wdgSelectMoveTarget('+gi+')">'+
                    '<span class="wdg-move-dot" style="background:'+color+'"></span>'+
                    escHtml(g.name) + (isCurr ? ' (actual)' : '') +
                    ' <small style="opacity:.7">('+g.count+')</small>'+
                    '</button>';
        });
        $('#wdg-move-groups').html(html);
        $('#wdg-move-overlay').addClass('open');
    };

    $(document).on('click', '.wdg-edit-driver', function() {
        wdgEditDriver($(this).data('id'));
    });
    $(document).on('click', '.wdg-toggle-driver', function() {
        wdgToggleDriver($(this).data('id'), $(this).data('activo') === '0');
    });
    $(document).on('click', '.wdg-delete-driver', function() {
        wdgDeleteDriver($(this).data('id'), $(this).data('nombre'));
    });

    $(document).on('click', '.wdg-btn-move', function(e) {
        e.stopPropagation();
        if (planIsSaved) {
            alert('⛔ La planificación ya fue guardada. No se pueden mover pedidos entre rutas.\n\nSi necesitas cambios, elimina el plan y crea uno nuevo.');
            return;
        }
        wdgMoveState.orderIdx  = parseInt($(this).data('order-idx'));
        wdgMoveState.fromGroup = parseInt($(this).data('group-idx'));
        wdgMoveState.toGroup   = null;

        var groups  = window.wdgGroups;
        var order   = groups[wdgMoveState.fromGroup].orders[wdgMoveState.orderIdx];

        $('#wdg-move-addr').text('#' + order.id + ' — ' + order.address + ', ' + order.city);
        $('#wdg-move-confirm').prop('disabled', true);

        // Renderizar botones de grupos destino
        var html = '';
        groups.forEach(function(g, gi) {
            var color   = GROUP_COLORS[gi % GROUP_COLORS.length];
            var isCurr  = gi === wdgMoveState.fromGroup;
            var cls     = isCurr ? 'wdg-move-btn current' : 'wdg-move-btn';
            var label   = isCurr ? escHtml(g.name) + ' (actual)' : escHtml(g.name);
            html += '<button class="'+cls+'" data-gi="'+gi+'" onclick="wdgSelectMoveTarget('+gi+')">'+
                    '<span class="wdg-move-dot" style="background:'+color+'"></span>'+
                    label + ' <small style="opacity:.7">('+g.count+')</small>'+
                    '</button>';
        });
        $('#wdg-move-groups').html(html);
        $('#wdg-move-overlay').addClass('open');
    });

    window.wdgSelectMoveTarget = function(gi) {
        wdgMoveState.toGroup = gi;
        $('.wdg-move-btn').removeClass('selected');
        $('.wdg-move-btn[data-gi="'+gi+'"]').addClass('selected');
        $('#wdg-move-confirm').prop('disabled', false);
    };

    window.wdgCloseMoveModal = function() {
        $('#wdg-move-overlay').removeClass('open');
    };

    window.wdgConfirmMove = function() {
        var from   = wdgMoveState.fromGroup;
        var to     = wdgMoveState.toGroup;
        var oIdx   = wdgMoveState.orderIdx;
        var groups = window.wdgGroups;

        if (from === null || to === null || from === to) return;
        wdgCloseMoveModal();

        // 1. Extraer pedido del grupo origen
        var order = groups[from].orders.splice(oIdx, 1)[0];
        groups[from].count--;

        // 2. Encontrar posición óptima en el grupo destino (insertar donde menos desvío cause)
        var destOrders  = groups[to].orders;
        var hasDepot    = window.wdgHasDepot && wdgData.depot && wdgData.depot.lat;
        var bestPos     = destOrders.length; // por defecto al final
        var bestDist    = Infinity;

        var prevLat = hasDepot ? parseFloat(wdgData.depot.lat) : (destOrders.length ? parseFloat(destOrders[0].lat) : parseFloat(order.lat));
        var prevLng = hasDepot ? parseFloat(wdgData.depot.lng) : (destOrders.length ? parseFloat(destOrders[0].lng) : parseFloat(order.lng));

        for (var pos = 0; pos <= destOrders.length; pos++) {
            var prev = pos === 0
                ? {lat: prevLat, lng: prevLng}
                : {lat: parseFloat(destOrders[pos-1].lat), lng: parseFloat(destOrders[pos-1].lng)};
            var next = pos < destOrders.length
                ? {lat: parseFloat(destOrders[pos].lat), lng: parseFloat(destOrders[pos].lng)}
                : (hasDepot ? {lat: parseFloat(wdgData.depot.lat), lng: parseFloat(wdgData.depot.lng)} : prev);

            var detour = haversine(prev.lat, prev.lng, parseFloat(order.lat), parseFloat(order.lng))
                       + haversine(parseFloat(order.lat), parseFloat(order.lng), next.lat, next.lng)
                       - haversine(prev.lat, prev.lng, next.lat, next.lng);

            if (detour < bestDist) { bestDist = detour; bestPos = pos; }
        }

        // 3. Insertar en posición óptima
        destOrders.splice(bestPos, 0, order);
        groups[to].count++;

        // 4. Re-numerar órdenes del grupo origen
        groups[from].orders.forEach(function(o, i){ o._tsp_idx = i; });

        // 5. Recalcular km aproximado (simplificado)
        [from, to].forEach(function(gi) {
            var g = groups[gi];
            var km = 0;
            var pts = [];
            if (hasDepot) pts.push({lat:parseFloat(wdgData.depot.lat),lng:parseFloat(wdgData.depot.lng)});
            g.orders.forEach(function(o){ pts.push({lat:parseFloat(o.lat),lng:parseFloat(o.lng)}); });
            if (hasDepot) pts.push({lat:parseFloat(wdgData.depot.lat),lng:parseFloat(wdgData.depot.lng)});
            for (var i = 1; i < pts.length; i++) km += haversine(pts[i-1].lat,pts[i-1].lng,pts[i].lat,pts[i].lng);
            g.route_km = Math.round(km * 10) / 10;
        });

        window.wdgGroups = groups;

        // 6. Re-renderizar todo
        renderStats(groups.reduce(function(s,g){return s+g.count;},0), 0, groups, currentConfig.max_per_group||35, hasDepot);
        renderGroups(groups, hasDepot);
        renderMap(groups, hasDepot);
    };

    // Haversine para el cálculo de desvío
    function haversine(lat1, lng1, lat2, lng2) {
        var R    = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a    = Math.sin(dLat/2)*Math.sin(dLat/2) +
                   Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
                   Math.sin(dLng/2)*Math.sin(dLng/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    // ── Actualizar marcadores admin según progreso de conductores ──────────────
    function updateAdminMarkers(data) {
        if (!map || !markers.length || !window.wdgGroups) return;

        // Construir mapa de order_id → estado completado
        var doneOrders = {};
        $.each(data, function(token, g) {
            if (!g.progress || !g.orders) return;
            g.orders.forEach(function(o, idx) {
                if (g.progress[String(idx)]) doneOrders[o.id] = true;
            });
        });

        // Recorrer todos los grupos y sus marcadores
        var markerIdx = 0;
        if (!window.wdgGroups) return;
        window.wdgGroups.forEach(function(g) {
            g.orders.forEach(function(o) {
                var m = markers[markerIdx];
                if (!m) { markerIdx++; return; }
                var isDone = !!doneOrders[o.id];
                var color  = GROUP_COLORS[window.wdgGroups.indexOf(g) % GROUP_COLORS.length];
                m.setIcon({
                    path:        google.maps.SymbolPath.CIRCLE,
                    fillColor:   isDone ? '#9ca3af' : color,
                    fillOpacity: isDone ? .40 : .95,
                    strokeColor: '#fff',
                    strokeWeight: 1.5,
                    scale:        isDone ? 10 : 13
                });
                m.setZIndex(isDone ? 5 : 50);
                markerIdx++;
            });
        });
    }


    // ══ GESTIÓN DE REPARTIDORES ════════════════════════════════════════════════

    var wdgDrivers = []; // cache local

    function loadDrivers(callback) {
        $.post(wdgData.ajaxUrl, { action:'wdg_get_drivers', nonce:wdgData.nonce }, function(res) {
            if (res.success) {
                wdgDrivers = res.data;
                if (callback) callback(wdgDrivers);
            }
        });
    }

    function licenciaBadge(vence) {
        if (!vence) return '';
        var hoy   = new Date(); hoy.setHours(0,0,0,0);
        var fecha = new Date(vence);
        var dias  = Math.round((fecha - hoy) / 86400000);
        if (dias < 0)   return '<span class="wdg-lic-badge expired">⛔ Licencia vencida</span>';
        if (dias <= 30) return '<span class="wdg-lic-badge warning">⚠️ Vence en '+dias+' días</span>';
        return '<span class="wdg-lic-badge ok">✅ Licencia vigente</span>';
    }

    function renderDriversList(drivers) {
        if (!drivers.length) {
            $('#wdgDriversList').html(
                '<div class="wdg-plans-empty">'+
                '<div style="font-size:36px;margin-bottom:8px">👤</div>'+
                '<div style="font-size:15px;font-weight:500;margin-bottom:4px">Sin repartidores registrados</div>'+
                '<div style="font-size:13px;color:#94a3b8">Agrega los repartidores del equipo para asignarlos en las rutas.</div>'+
                '</div>'
            );
            return;
        }
        var html = '<div class="wdg-drivers-grid">';
        drivers.forEach(function(d) {
            var badge = d.activo
                ? '<span class="wdg-driver-badge active">Activo</span>'
                : '<span class="wdg-driver-badge inactive">Inactivo</span>';
            var licBadge = licenciaBadge(d.licencia_vence);
            html += '<div class="wdg-driver-card' + (d.activo ? '' : ' inactive') + '" id="dcard-'+d.id+'">';
            html += '<div class="wdg-driver-card-header">';
            html += '<div class="wdg-driver-avatar">'+escHtml((d.alias||d.nombre).charAt(0).toUpperCase())+'</div>';
            html += '<div class="wdg-driver-info"><div class="wdg-driver-name">'+escHtml(d.nombre)+'</div>';
            html += '<div class="wdg-driver-alias">'+escHtml(d.alias||'')+'</div></div>';
            html += badge + '</div>';
            if (licBadge) html += '<div style="padding:6px 0 2px">'+licBadge+'</div>';
            html += '<div class="wdg-driver-attrs">';
            if (d.rut)            html += '<div>🪪 '+escHtml(d.rut)+'</div>';
            if (d.telefono)       html += '<div>📞 '+escHtml(d.telefono)+'</div>';
            if (d.licencia_tipo)  html += '<div>🪪 Licencia '+escHtml(d.licencia_tipo)+(d.licencia_vence?' · vence '+escHtml(d.licencia_vence):'')+'</div>';
            html += '</div>';
            html += '<div class="wdg-driver-actions">';
            html += '<button class="button wdg-edit-driver" data-id="'+escHtml(d.id)+'">✏️ Editar</button>';
            html += '<button class="button wdg-toggle-driver" data-id="'+escHtml(d.id)+'" data-activo="'+(d.activo?'1':'0')+'">'+
                    (d.activo ? '⏸ Desactivar' : '▶ Activar')+'</button>';
            html += '<button class="button wdg-delete-driver" data-id="'+escHtml(d.id)+'" data-nombre="'+escHtml(d.nombre)+'">🗑</button>';
            html += '</div></div>';
        });
        html += '</div>';
        $('#wdgDriversList').html(html);
    }

    window.wdgNewDriver = function() {
        $('#driverEditId').val('');
        $('#driverNombre, #driverAlias, #driverRut, #driverTelefono, #driverEmail, #driverLicenciaVence').val('');
        $('#driverLicenciaTipo').val('');
        $('#driverActivo').prop('checked', true);
        $('#wdgDriverFormTitle').text('➕ Nuevo repartidor');
        $('#wdgDriverForm').show();
        $('#driverNombre').focus();
    };

    window.wdgEditDriver = function(id) {
        var d = wdgDrivers.find(function(x){ return x.id === id; });
        if (!d) return;
        $('#driverEditId').val(d.id);
        $('#driverNombre').val(d.nombre||'');
        $('#driverAlias').val(d.alias||'');
        $('#driverRut').val(d.rut||'');
        $('#driverTelefono').val(d.telefono||'');
        $('#driverEmail').val(d.email||'');
        $('#driverLicenciaTipo').val(d.licencia_tipo||'');
        $('#driverLicenciaVence').val(d.licencia_vence||'');
        $('#driverActivo').prop('checked', !!d.activo);
        $('#wdgDriverFormTitle').text('✏️ Editar repartidor');
        $('#wdgDriverForm').show();
        $('#wdgDriverForm')[0].scrollIntoView({behavior:'smooth'});
    };

    window.wdgCancelDriver = function() { $('#wdgDriverForm').hide(); };

    window.wdgSaveDriver = function() {
        var nombre = $('#driverNombre').val().trim();
        if (!nombre) { alert('El nombre es obligatorio'); return; }
        $.post(wdgData.ajaxUrl, {
action:    'wdg_save_driver',
nonce:     wdgData.nonce,
id:        $('#driverEditId').val(),
nombre:    nombre,
alias:     $('#driverAlias').val().trim(),
telefono:  $('#driverTelefono').val().trim(),
email:     $('#driverEmail').val().trim(),
vehiculo:  ($('#driverVehiculo').val() || '').trim(),
zona:      ($('#driverZona').val() || '').trim(),
capacidad: parseInt($('#driverCapacidad').val()) || 35,
activo:    $('#driverActivo').is(':checked') ? '1':'0',
        }, function(res) {
if (!res.success) { alert('Error: '+res.data); return; }
            $('#wdgDriverForm').hide();
            loadDrivers(renderDriversList);
        });
    };

    window.wdgToggleDriver = function(id, activo) {
        var d = wdgDrivers.find(function(x){ return x.id === id; });
        if (!d) return;
        $.post(wdgData.ajaxUrl, {
            action: 'wdg_save_driver', nonce: wdgData.nonce,
            id: d.id, nombre: d.nombre, alias: d.alias||'',
            rut: d.rut||'', telefono: d.telefono||'', email: d.email||'',
            licencia_tipo: d.licencia_tipo||'', licencia_vence: d.licencia_vence||'',
            activo: activo ? '1':'0',
        }, function(res) {
            if (res.success) loadDrivers(renderDriversList);
        });
    };

    window.wdgDeleteDriver = function(id, nombre) {
        if (!confirm('¿Eliminar a '+nombre+'? Esta acción no se puede deshacer.')) return;
        $.post(wdgData.ajaxUrl, {
            action: 'wdg_delete_driver', nonce: wdgData.nonce, id: id
        }, function(res) {
if (res.success) { loadDrivers(renderDriversList); }
        });
    };


    // ══ ANÁLISIS ══════════════════════════════════════════════════════════════

    var wdgAnalyticsData = [];

    function initAnalyticsFilters() {
        // Fechas por defecto: último mes
        var today = new Date();
        var from  = new Date(today); from.setDate(from.getDate() - 30);
        var fmt   = function(d){ return d.toISOString().split('T')[0]; };
        $('#anDateFrom').val(fmt(from));
        $('#anDateTo').val(fmt(today));

        // Poblar select de repartidores
        var opts = '<option value="">Todos</option>';
        wdgDrivers.forEach(function(d){
            opts += '<option value="'+escHtml(d.id)+'">'+escHtml(d.nombre)+'</option>';
        });
        $('#anDriver').html(opts);
    }

    window.wdgRunAnalytics = function() {
        $('#wdgAnalyticsTable').html('<p style="color:#94a3b8;padding:16px">Cargando...</p>');
        $('#wdgAnalyticsKpis').hide();

        $.post(wdgData.ajaxUrl, {
            action:    'wdg_query_events',
            nonce:     wdgData.nonce,
            date_from: $('#anDateFrom').val(),
            date_to:   $('#anDateTo').val(),
            driver_id: $('#anDriver').val(),
            status:    $('#anStatus').val(),
            product:   $('#anProduct').val(),
            order_id:  $('#anOrderId').val().trim(),
        }, function(res) {
            if (!res.success) { $('#wdgAnalyticsTable').html('<p style="color:#ef4444">Error: '+res.data+'</p>'); return; }
            wdgAnalyticsData = res.data.rows;
            renderAnalyticsKpis(res.data.kpis);
            renderAnalyticsTable(res.data.rows);
        }).fail(function(){
            $('#wdgAnalyticsTable').html('<p style="color:#ef4444">Error de conexión</p>');
        });
    };

    function renderAnalyticsKpis(k) {
        var rate_color = k.rate >= 90 ? '#15803d' : (k.rate >= 70 ? '#d97706' : '#dc2626');
        var html = ''
            + statBox(k.total,       'Total asignados', '')
            + statBox(k.delivered,   'Entregados',      'ok')
            + statBox(k.partial,     'Parciales',       k.partial > 0 ? 'warn' : '')
            + statBox(k.not_visited, 'No visitados',    k.not_visited > 0 ? 'warn' : '')
            + '<div class="wdg-stat-box"><div class="wdg-stat-label">Tasa entrega</div>'
            + '<div class="wdg-stat-value" style="color:'+rate_color+'">'+k.rate+'%</div></div>';
        $('#wdgAnalyticsKpis').html(html).show();
    }

    function renderAnalyticsTable(rows) {
        if (!rows || !rows.length) {
            $('#wdgAnalyticsTable').html('<div class="wdg-plans-empty">📭 Sin resultados para los filtros seleccionados.</div>');
            return;
        }

        var statusLabel = {
            'delivered':   '<span class="wdg-an-badge delivered">✅ Entregado</span>',
            'partial':     '<span class="wdg-an-badge partial">⚠️ Parcial</span>',
            'not_visited': '<span class="wdg-an-badge not-visited">⛔ No visitado</span>',
            'assigned':    '<span class="wdg-an-badge assigned">📋 Asignado</span>',
        };

        var html = '<div class="wdg-an-table-wrap"><table class="wdg-an-table">'
            + '<thead><tr><th>Fecha</th><th>Plan</th><th>Ruta</th><th>Repartidor</th><th>Vehículo</th><th>Pedido</th>'
            + '<th>Cliente</th><th>Comuna</th><th>Productos</th>'
            + '<th>Estado</th><th>Recibido por</th><th>Nota</th><th>Foto</th></tr></thead><tbody>';

        rows.forEach(function(r) {
            var prods = '';
            try {
                var items = JSON.parse(r.products || '[]');
                prods = items.map(function(it){
                    var qty = parseInt(it.qty||1);
                    var qcls = qty > 1 ? 'style="color:#dc2626;font-weight:700"' : '';
                    return '<span>'+escHtml(it.name)+' <span '+qcls+'>x'+qty+'</span></span>';
                }).join('<br>');
            } catch(e) { prods = escHtml(r.products||''); }

            var foto = r.photo_url
                ? '<a href="'+escHtml(r.photo_url)+'" target="_blank" style="color:#2271b1">📷 Ver</a>'
                : '';

            var statusBadge = statusLabel[r.status] || ('<span class="wdg-an-badge">'+escHtml(r.status)+'</span>');
            html += '<tr id="wdg-event-row-'+r.id+'">'
                + '<td>'+escHtml(r.route_date)+'</td>'
                + '<td><span class="wdg-plan-id-small" title="'+escHtml(r.plan_name)+'">'+escHtml(r.plan_id)+'</span></td>'
                + '<td><strong>'+escHtml(r.group_name||'')+'</strong></td>'
                + '<td>'+escHtml(r.driver_name)+'</td>'
                + '<td style="font-size:12px">'+escHtml(r.vehicle||'')+'</td>'
                + '<td><a href="'+wdgData.adminUrl+'post.php?post='+r.order_id+'&action=edit" target="_blank">#'+r.order_id+'</a></td>'
                + '<td>'+escHtml(r.customer)+'</td>'
                + '<td>'+escHtml(r.city)+'</td>'
                + '<td style="font-size:12px">'+prods+'</td>'
                + '<td class="wdg-event-status-cell">'
                    + '<span id="wdg-event-status-'+r.id+'">'+statusBadge+'</span>'
                    + ' <button class="button button-small wdg-refresh-status" data-event-id="'+r.id+'" data-order-id="'+r.order_id+'" title="Actualizar desde WooCommerce">🔄</button>'
                + '</td>'
                + '<td style="font-size:12px">'+escHtml(r.recipient||'')+'</td>'
                + '<td style="font-size:12px;color:#64748b">'+escHtml(r.partial_note||'')+'</td>'
                + '<td>'+foto+'</td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        html += '<div style="font-size:12px;color:#94a3b8;margin-top:8px">'+rows.length+' resultados (máx. 500)</div>';
        $('#wdgAnalyticsTable').html(html);
    }

    // ── Actualizar estado desde WooCommerce ──────────────────────────────────
    var statusLabelMap = {
        'delivered':   '<span class="wdg-an-badge delivered">✅ Entregado</span>',
        'partial':     '<span class="wdg-an-badge partial">⚠️ Parcial</span>',
        'not_visited': '<span class="wdg-an-badge not-visited">⛔ No visitado</span>',
        'assigned':    '<span class="wdg-an-badge assigned">📋 Asignado</span>',
    };

    $(document).on('click', '.wdg-refresh-status', function() {
        var $btn    = $(this).prop('disabled', true).text('⏳');
        var eventId = $(this).data('event-id');
        var orderId = $(this).data('order-id');
        $.post(wdgData.ajaxUrl, {
            action:   'wdg_refresh_event_status',
            nonce:    wdgData.nonce,
            event_id: eventId,
            order_id: orderId,
        }, function(res) {
            $btn.prop('disabled', false).text('🔄');
            if (!res.success) { alert('Error: ' + res.data); return; }
            if (!res.data.updated) { alert(res.data.alert); return; }
            $('#wdg-event-status-' + eventId).html(statusLabelMap[res.data.new_status] || res.data.new_status);
        }).fail(function() {
            $btn.prop('disabled', false).text('🔄');
            alert('Error de conexión');
        });
    });

    window.wdgExportAnalytics = function() {
        if (!wdgAnalyticsData.length) { alert('Primero realiza una búsqueda.'); return; }

var headers = ['Fecha','Plan','Repartidor','Pedido','Cliente','Dirección','Comuna','Productos','Estado','Nota parcial','Foto','Receptor'];
        var rows = [headers];

wdgAnalyticsData.forEach(function(r) {
var prods = '';
try {
prods = JSON.parse(r.products||'[]').map(function(it){ return it.name+' x'+it.qty; }).join(' | ');
            } catch(e){}
            rows.push([
                r.route_date, r.plan_name, r.group_name||'', r.driver_name, r.vehicle||'', '#'+r.order_id,
                r.customer, r.address, r.city, prods,
                r.status, r.partial_note||'', r.photo_url||'', r.recipient||''
            ]);
        });

        var csv  = rows.map(function(r){ return r.map(function(c){ return '"'+String(c||'').replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
        var blob = new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'});
        var a    = document.createElement('a');
        a.href   = URL.createObjectURL(blob);
        a.download = 'analisis-entregas-'+$('#anDateFrom').val()+'_'+$('#anDateTo').val()+'.csv';
        a.click();
    };


    // ══ GESTIÓN DE VEHÍCULOS ══════════════════════════════════════════════════

    var wdgVehicles = [];

    function loadVehicles(callback) {
        $.post(wdgData.ajaxUrl, { action:'wdg_get_vehicles', nonce:wdgData.nonce }, function(res) {
            if (res.success) { wdgVehicles = res.data; if (callback) callback(wdgVehicles); }
        });
    }

    function renderVehiclesList(vehicles) {
        if (!vehicles.length) {
            $('#wdgVehiclesList').html(
                '<div class="wdg-plans-empty">'+
                '<div style="font-size:36px;margin-bottom:8px">🚗</div>'+
                '<div style="font-size:15px;font-weight:500;margin-bottom:4px">Sin vehículos registrados</div>'+
                '<div style="font-size:13px;color:#94a3b8">Agrega los vehículos de la flota para asignarlos en las rutas.</div>'+
                '</div>'
            );
            return;
        }
        var html = '<div class="wdg-drivers-grid">';
        vehicles.forEach(function(v) {
            var badge = v.activo
                ? '<span class="wdg-driver-badge active">Disponible</span>'
                : '<span class="wdg-driver-badge inactive">No disponible</span>';
            var icon = {Moto:'🏍️',Auto:'🚗',Furgón:'🚐',Camioneta:'🛻',Camión:'🚛'}[v.tipo] || '🚗';
            html += '<div class="wdg-driver-card'+(v.activo?'':' inactive')+'">';
            html += '<div class="wdg-driver-card-header">';
            html += '<div class="wdg-driver-avatar" style="font-size:20px;background:var(--color-background-secondary)">'+icon+'</div>';
            html += '<div class="wdg-driver-info"><div class="wdg-driver-name">'+escHtml(v.patente)+'</div>';
            html += '<div class="wdg-driver-alias">'+escHtml(v.tipo)+(v.modelo?' · '+escHtml(v.modelo):'')+' </div></div>';
            html += badge + '</div>';
            html += '<div class="wdg-driver-attrs">';
            if (v.capacidad) html += '<div>📦 '+escHtml(v.capacidad)+'</div>';
            html += '</div>';
            html += '<div class="wdg-driver-actions">';
            html += '<button class="button wdg-edit-vehicle" data-id="'+escHtml(v.id)+'">✏️ Editar</button>';
            html += '<button class="button wdg-toggle-vehicle" data-id="'+escHtml(v.id)+'" data-activo="'+(v.activo?'1':'0')+'">'+
                    (v.activo?'⏸ Desactivar':'▶ Activar')+'</button>';
            html += '<button class="button wdg-delete-vehicle" data-id="'+escHtml(v.id)+'" data-patente="'+escHtml(v.patente)+'">🗑</button>';
            html += '</div></div>';
        });
        html += '</div>';
        $('#wdgVehiclesList').html(html);
    }

    window.wdgNewVehicle = function() {
        $('#vehicleEditId').val('');
        $('#vehiclePatente, #vehicleModelo, #vehicleCapacidad').val('');
        $('#vehicleTipo').val('Auto');
        $('#vehicleActivo').prop('checked', true);
        $('#wdgVehicleFormTitle').text('➕ Nuevo vehículo');
        $('#wdgVehicleForm').show();
        $('#vehiclePatente').focus();
    };
    window.wdgCancelVehicle = function() { $('#wdgVehicleForm').hide(); };

    window.wdgSaveVehicle = function() {
        var patente = $('#vehiclePatente').val().trim().toUpperCase();
        if (!patente) { alert('La patente es obligatoria'); return; }
        $.post(wdgData.ajaxUrl, {
            action:    'wdg_save_vehicle', nonce: wdgData.nonce,
            id:        $('#vehicleEditId').val(),
            patente:   patente,
            tipo:      $('#vehicleTipo').val(),
            modelo:    $('#vehicleModelo').val().trim(),
            capacidad: $('#vehicleCapacidad').val().trim(),
            activo:    $('#vehicleActivo').is(':checked') ? '1':'0',
        }, function(res) {
            if (!res.success) { alert('Error: '+res.data); return; }
            $('#wdgVehicleForm').hide();
            loadVehicles(renderVehiclesList);
        });
    };

    window.wdgEditVehicle = function(id) {
        var v = wdgVehicles.find(function(x){ return x.id===id; });
        if (!v) return;
        $('#vehicleEditId').val(v.id);
        $('#vehiclePatente').val(v.patente||'');
        $('#vehicleTipo').val(v.tipo||'Auto');
        $('#vehicleModelo').val(v.modelo||'');
        $('#vehicleCapacidad').val(v.capacidad||'');
        $('#vehicleActivo').prop('checked', !!v.activo);
        $('#wdgVehicleFormTitle').text('✏️ Editar vehículo');
        $('#wdgVehicleForm').show();
        $('#wdgVehicleForm')[0].scrollIntoView({behavior:'smooth'});
    };

    $(document).on('click', '.wdg-edit-vehicle',   function(){ wdgEditVehicle($(this).data('id')); });
    $(document).on('click', '.wdg-toggle-vehicle', function() {
        var v = wdgVehicles.find(function(x){ return x.id===$(this).data('id'); }.bind(this));
        if (!v) return;
        $.post(wdgData.ajaxUrl, {
            action:'wdg_save_vehicle', nonce:wdgData.nonce,
            id:v.id, patente:v.patente, tipo:v.tipo, modelo:v.modelo||'',
            capacidad:v.capacidad||'', activo:$(this).data('activo')==='0'?'1':'0',
        }, function(res){ if(res.success) loadVehicles(renderVehiclesList); });
    });
    $(document).on('click', '.wdg-delete-vehicle', function() {
        if (!confirm('¿Eliminar vehículo '+$(this).data('patente')+'?')) return;
        $.post(wdgData.ajaxUrl, { action:'wdg_delete_vehicle', nonce:wdgData.nonce, id:$(this).data('id') },
            function(res){ if(res.success) loadVehicles(renderVehiclesList); });
    });

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
});
