<?php
/**
 * Plugin Name: WooCommerce Delivery Groups
 * Description: Agrupa pedidos por cercanía geográfica (K-Means++) y optimiza rutas de reparto (TSP). Considera bodega como punto de inicio y retorno.
 * Version:     2.20.0
 * Author:      Webpremium Chile
 * Text Domain: woo-delivery-groups
 */

defined( 'ABSPATH' ) || exit;

class Woo_Delivery_Groups {

    const SLUG        = 'woo-delivery-groups';
    const VERSION     = '2.20.0';
    const OPT_API_KEY = 'wga_google_maps_api_key';
    const OPT_DEPOT       = 'wdg_depot';       // array: address, lat, lng
    const OPT_SEND_EMAIL  = 'wdg_send_photo_email'; // 1 = enviar, 0 = no enviar

    // Bounding box Santiago
    const LAT_MIN = -33.70;
    const LAT_MAX = -33.28;
    const LNG_MIN = -71.00;
    const LNG_MAX = -70.45;

    private function get_api_key() {
        $key = get_option( self::OPT_API_KEY, '' );
        if ( empty($key) ) $key = get_option( 'aafw_map_api_key', '' );
        return trim($key);
    }

    private function get_depot() {
        return get_option( self::OPT_DEPOT, array(
            'address' => 'Alfonso Vial 1049, Maipú, Santiago, Chile',
            'lat'     => -33.5147,
            'lng'     => -70.7680,
        ));
    }

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'admin_init', array( $this, 'maybe_cleanup_photos' ) );
        add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        // ── Admin AJAX ────────────────────────────────────────────────────────
        add_action( 'wp_ajax_wdg_get_orders',   array( $this, 'ajax_get_orders' ) );
        add_action( 'wp_ajax_wdg_cluster',      array( $this, 'ajax_cluster' ) );
        add_action( 'wp_ajax_wdg_save_depot',   array( $this, 'ajax_save_depot' ) );
        add_action( 'wp_ajax_wdg_save_token',   array( $this, 'ajax_save_token' ) );
        add_action( 'wp_ajax_wdg_get_progress', array( $this, 'ajax_get_progress' ) );
        add_action( 'wp_ajax_wdg_save_plan',    array( $this, 'ajax_save_plan' ) );
        add_action( 'wp_ajax_wdg_get_plans',    array( $this, 'ajax_get_plans' ) );
        add_action( 'wp_ajax_wdg_load_plan',    array( $this, 'ajax_load_plan' ) );
        add_action( 'wp_ajax_wdg_delete_plan',  array( $this, 'ajax_delete_plan' ) );
        add_action( 'wp_ajax_wdg_new_orders',   array( $this, 'ajax_new_orders' ) );
        add_action( 'wp_ajax_wdg_append_orders',array( $this, 'ajax_append_orders' ) );
        add_action( 'wp_ajax_wdg_reassign_orders', array( $this, 'ajax_reassign_orders' ) );
        add_action( 'wp_ajax_wdg_remove_order',     array( $this, 'ajax_remove_order' ) );
        add_action( 'wp_ajax_wdg_remove_orders',    array( $this, 'ajax_remove_orders' ) );
        add_action( 'wp_ajax_wdg_get_log',          array( $this, 'ajax_get_log' ) );
        add_action( 'wp_ajax_wdg_query_events',     array( $this, 'ajax_query_events' ) );
        add_action( 'wp_ajax_wdg_save_driver',      array( $this, 'ajax_save_driver' ) );
        add_action( 'wp_ajax_wdg_get_drivers',      array( $this, 'ajax_get_drivers' ) );
        add_action( 'wp_ajax_wdg_delete_driver',    array( $this, 'ajax_delete_driver' ) );
        add_action( 'wp_ajax_wdg_clear_log',        array( $this, 'ajax_clear_log' ) );
        add_action( 'wp_ajax_wdg_refresh_event_status', array( $this, 'ajax_refresh_event_status' ) );
        add_action( 'wp_ajax_wdg_save_config',      array( $this, 'ajax_save_config' ) );
        add_action( 'wp_ajax_wdg_save_api_key',     array( $this, 'ajax_save_api_key' ) );
        add_action( 'wp_ajax_wdg_test_api_key',     array( $this, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_wdg_get_vehicles',     array( $this, 'ajax_get_vehicles' ) );
        add_action( 'wp_ajax_wdg_save_vehicle',     array( $this, 'ajax_save_vehicle' ) );
        add_action( 'wp_ajax_wdg_delete_vehicle',   array( $this, 'ajax_delete_vehicle' ) );
        // ── Frontend AJAX (sin login — conductores) ────────────────────────
        add_action( 'wp_ajax_nopriv_wdg_sync_progress',  array( $this, 'ajax_sync_progress' ) );
        add_action( 'wp_ajax_nopriv_wdg_complete_order',  array( $this, 'ajax_complete_order' ) );
        add_action( 'wp_ajax_nopriv_wdg_upload_photo',    array( $this, 'ajax_upload_photo' ) );
        add_action( 'wp_ajax_nopriv_wdg_partial_order',   array( $this, 'ajax_partial_order' ) );
        add_action( 'wp_ajax_nopriv_wdg_finish_route',    array( $this, 'ajax_finish_route' ) );
        add_action( 'init',                     array( $this, 'handle_mobile_route' ) );
    }

    // ── Menú ──────────────────────────────────────────────────────────────────

    public function add_menu() {
        add_submenu_page(
            'woocommerce', 'Grupos de Reparto', 'Grupos de Reparto',
            'manage_woocommerce', self::SLUG, array( $this, 'render_page' )
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, self::SLUG ) === false ) return;

        $ts = self::VERSION . '-' . time();
        wp_enqueue_style(  'wdg-style',  plugin_dir_url(__FILE__) . 'assets/css/admin.css', array(), $ts );
        wp_enqueue_script( 'wdg-script', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery'), $ts, true );

        $depot = $this->get_depot();

        wp_localize_script( 'wdg-script', 'wdgData', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wdg_nonce'),
            'apiKey'   => $this->get_api_key(),
            'adminUrl' => admin_url(),
            'version'  => self::VERSION,
            'depot'    => $depot,
            'bounds'   => array(
                'latMin' => self::LAT_MIN, 'latMax' => self::LAT_MAX,
                'lngMin' => self::LNG_MIN, 'lngMax' => self::LNG_MAX,
            ),
        ));
    }

    // ── Página ────────────────────────────────────────────────────────────────

    public function render_page() {
        $api_key = $this->get_api_key();
        $depot   = $this->get_depot();
        ?>
        <div class="wrap wdg-wrap">
            <h1>🚚 Grupos de Reparto — Santiago
                <span class="wdg-version-badge">v<?php echo self::VERSION; ?></span>
            </h1>

            <!-- Pestañas -->
            <nav class="wdg-tabs">
                <a href="#" class="wdg-tab wdg-tab--active" data-tab="plans">📋 Planificaciones</a>
                <a href="#" class="wdg-tab" data-tab="new">➕ Nueva planificación</a>
                <a href="#" class="wdg-tab" data-tab="drivers">👥 Repartidores</a>
                <a href="#" class="wdg-tab" data-tab="vehicles">🚗 Vehículos</a>
                <a href="#" class="wdg-tab" data-tab="analytics">📊 Análisis</a>
                <a href="#" class="wdg-tab" data-tab="log">🪲 Log de actividad</a>
                <a href="#" class="wdg-tab" data-tab="config">⚙️ Configuración</a>
            </nav>

            <!-- ══ TAB: Planificaciones guardadas ══ -->
            <div class="wdg-tab-content" id="wdg-tab-plans">
                <div class="wdg-plans-header">
                    <p class="wdg-sub">Planificaciones guardadas. Haz clic en una para recargar grupos, progreso y tokens.</p>
                    <button class="button button-primary" onclick="wdgNewPlan()">➕ Nueva planificación</button>
                </div>
                <div id="wdgPlansList"></div>
            </div>

            <!-- ══ TAB: Nueva planificación ══ -->
            <div class="wdg-tab-content" id="wdg-tab-new" style="display:none">
            <p class="wdg-sub">Configura, genera grupos y guarda la planificación para seguimiento en tiempo real.</p>

            <?php if ( empty($api_key) ) : ?>
                <div class="notice notice-error"><p>⚠️ No se encontró API Key de Google Maps. Configúrala en la pestaña <strong>⚙️ Configuración</strong> de este plugin.</p></div>
            <?php endif; ?>

            <div class="wdg-layout">
                <div class="wdg-panel">

                    <!-- ── Bodega ── -->
                    <div class="wdg-card wdg-card--depot">
                        <h2>🏭 Dirección de Bodega</h2>
                        <p class="wdg-card-desc">Punto de inicio y retorno de todos los repartidores. También se usa para optimizar la formación de grupos.</p>
                        <div class="wdg-depot-row">
                            <input type="text" id="wdgDepotAddress"
                                value="<?php echo esc_attr($depot['address']); ?>"
                                placeholder="Ej: Av. Américo Vespucio 1234, Maipú, Santiago"
                                class="regular-text">
                            <button id="btnSaveDepot" class="button button-primary">💾 Guardar</button>
                        </div>
                        <div id="wdgDepotStatus" class="wdg-depot-status">
                            <?php if ( !empty($depot['lat']) ) : ?>
                                <span class="wdg-depot-ok">✅ Bodega configurada:
                                    <a href="https://www.google.com/maps?q=<?php echo esc_attr($depot['lat']); ?>,<?php echo esc_attr($depot['lng']); ?>" target="_blank">
                                        <?php echo esc_html($depot['address']); ?>
                                    </a>
                                    <code><?php echo round($depot['lat'],6); ?>, <?php echo round($depot['lng'],6); ?></code>
                                </span>
                            <?php else : ?>
                                <span class="wdg-depot-warn">⚠️ Sin bodega configurada — las rutas no tendrán punto de inicio/retorno.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ── Filtros ── -->
                    <div class="wdg-card">
                        <h2>⚙️ Configuración</h2>
                        <div class="wdg-field">
                            <label>Estado de pedido</label>
                            <select id="wdgStatus">
                                <?php
                                $all_statuses = wc_get_order_statuses();
                                $default      = 'wc-en-ruta';
                                foreach ( $all_statuses as $slug => $label ) :
                                    $selected = selected( $slug, $default, false );
                                ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php echo $selected; ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <hr class="wdg-divider">
                        <div class="wdg-field">
                            <label>Máx. pedidos por repartidor <span class="wdg-hint">N</span></label>
                            <input type="number" id="wdgMaxPerGroup" value="35" min="1" max="100">
                        </div>
                        <!-- Los nombres vienen del listbox en el panel de repartidores -->
                        <input type="hidden" id="wdgNames" value="">
                        <hr class="wdg-divider">
                        <button id="btnSearch" class="button button-primary button-large" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                            🔍 Buscar pedidos
                        </button>
                        <div id="wdgSpinner" class="wdg-spinner" style="display:none">⏳ Procesando…</div>
                    </div>

                    <!-- ── Stats ── -->
                    <div class="wdg-card" id="wdgStatsCard" style="display:none">
                        <h2>📊 Estadísticas</h2>
                        <div id="wdgStats"></div>
                    </div>

                    <!-- ── Panel de repartidores ── -->
                    <div class="wdg-card" id="wdgRepartidoresCard" style="display:none">
                        <h2>👥 Repartidores</h2>
                        <div id="wdgRepartidoresList"></div>
                        <div class="wdg-quota-summary" id="wdgQuotaSummary"></div>
                        <button id="btnProcess" class="button button-primary button-large" style="width:100%;margin-top:12px" disabled>
                            🚀 Generar rutas
                        </button>
                    </div>

                    <!-- ── Grupos ── -->
                    <div class="wdg-card" id="wdgGroupsCard" style="display:none">
                        <h2>📋 Grupos generados</h2>
                        <div id="wdgGroupsList"></div>
                    </div>

                    <!-- ── Guardar planificación ── -->
                    <div class="wdg-card" id="wdgSavePlanCard" style="display:none">
                        <h2>💾 Guardar planificación</h2>
                        <div class="wdg-save-row">
                            <input type="text" id="wdgPlanName"
                                placeholder="Ej: Reparto 22/03 tarde"
                                style="flex:1;min-width:180px">
                            <button id="btnSavePlan" class="button button-primary" onclick="wdgSavePlan()">💾 Guardar</button>
                        </div>
                        <div id="wdgSavePlanStatus" style="font-size:12px;margin-top:6px"></div>
                    </div>

                    <!-- ── Añadir pedidos nuevos a un plan guardado ── -->
                    <div class="wdg-card" id="wdgAddOrdersCard" style="display:none">
                        <h2>➕ Añadir pedidos nuevos</h2>
                        <p class="wdg-sub" style="margin:0 0 10px">
                            Detecta pedidos no asignados del estado elegido y los asigna a la ruta más cercana, reoptimizando el recorrido. El enlace del conductor se mantiene.
                        </p>
                        <div class="wdg-field" style="margin:0 0 10px">
                            <label>Estado de pedido a buscar</label>
                            <select id="wdgNewStatus">
                                <?php
                                foreach ( wc_get_order_statuses() as $slug => $label ) :
                                    $selected = selected( $slug, 'wc-en-ruta', false );
                                ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php echo $selected; ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button id="btnDetectNew" class="button button-primary" onclick="wdgDetectNewOrders()">🔍 Buscar pedidos nuevos</button>
                        <div id="wdgAddOrdersStatus" style="font-size:12px;margin-top:8px"></div>
                        <div id="wdgNewOrdersPanel" style="display:none;margin-top:12px">
                            <div id="wdgNewOrdersList"></div>
                            <button id="btnConfirmAppend" class="button button-primary button-large" style="width:100%;margin-top:12px" onclick="wdgConfirmAppend()">✅ Confirmar y reoptimizar</button>
                        </div>
                    </div>

                    <!-- ── Progreso en tiempo real ── -->
                    <div class="wdg-card" id="wdgProgressCard" style="display:none">
                        <h2>📡 Progreso en tiempo real
                            <button id="btnRefreshProgress" class="button button-small" style="margin-left:8px;font-size:11px">↻ Actualizar</button>
                            <span id="wdgAutoRefreshBadge" style="font-size:10px;color:#64748b;margin-left:6px">Auto-actualiza cada 30s</span>
                        </h2>
                        <div id="wdgProgressList"></div>
                    </div>

                </div><!-- /.wdg-panel -->

                <!-- ── Mapa ── -->
                <div class="wdg-map-wrap">
                    <div id="wdgMap"></div>
                    <div class="wdg-map-legend" id="wdgLegend" style="display:none"></div>
                </div>

            </div>
            </div><!-- /#wdg-tab-new -->

            <!-- ══ TAB: Vehículos ══ -->
            <div class="wdg-tab-content" id="wdg-tab-vehicles" style="display:none">
                <div class="wdg-drivers-header">
                    <p class="wdg-sub">Gestiona la flota de vehículos. Se asignan por planificación.</p>
                    <button class="button button-primary" onclick="wdgNewVehicle()">➕ Agregar vehículo</button>
                </div>
                <div id="wdgVehiclesList"></div>
                <div id="wdgVehicleForm" style="display:none" class="wdg-card wdg-driver-form">
                    <h2 id="wdgVehicleFormTitle">➕ Nuevo vehículo</h2>
                    <input type="hidden" id="vehicleEditId">
                    <div class="wdg-driver-fields">
                        <div class="wdg-field">
                            <label>Patente *</label>
                            <input type="text" id="vehiclePatente" placeholder="Ej: ABCD12" style="text-transform:uppercase">
                        </div>
                        <div class="wdg-field">
                            <label>Tipo</label>
                            <select id="vehicleTipo">
                                <option value="Moto">Moto</option>
                                <option value="Auto">Auto</option>
                                <option value="Furgón">Furgón</option>
                                <option value="Camioneta">Camioneta</option>
                                <option value="Camión">Camión</option>
                            </select>
                        </div>
                        <div class="wdg-field">
                            <label>Marca / Modelo</label>
                            <input type="text" id="vehicleModelo" placeholder="Ej: Yamaha YBR / Chevrolet Sail">
                        </div>
                        <div class="wdg-field">
                            <label>Capacidad <span class="wdg-hint">bultos/kg</span></label>
                            <input type="text" id="vehicleCapacidad" placeholder="Ej: 30 bultos">
                        </div>
                        <div class="wdg-field">
                            <label>Estado</label>
                            <label class="wdg-toggle-label">
                                <input type="checkbox" id="vehicleActivo" checked>
                                <span>Vehículo disponible</span>
                            </label>
                        </div>
                    </div>
                    <div class="wdg-driver-form-actions">
                        <button class="button" onclick="wdgCancelVehicle()">Cancelar</button>
                        <button class="button button-primary" onclick="wdgSaveVehicle()">💾 Guardar</button>
                    </div>
                </div>
            </div><!-- /#wdg-tab-vehicles -->

            <!-- ══ TAB: Repartidores ══ -->
            <div class="wdg-tab-content" id="wdg-tab-drivers" style="display:none">
                <div class="wdg-drivers-header">
                    <p class="wdg-sub">Gestiona los repartidores del equipo. Estos aparecerán en la generación de rutas.</p>
                    <button class="button button-primary" onclick="wdgNewDriver()">➕ Agregar repartidor</button>
                </div>
                <div id="wdgDriversList"></div>

                <!-- Formulario inline -->
                <div id="wdgDriverForm" style="display:none" class="wdg-card wdg-driver-form">
                    <h2 id="wdgDriverFormTitle">➕ Nuevo repartidor</h2>
                    <input type="hidden" id="driverEditId">
                    <div class="wdg-driver-fields">
                        <div class="wdg-field">
                            <label>Nombre completo *</label>
                            <input type="text" id="driverNombre" placeholder="Ej: Juan Pérez">
                        </div>
                        <div class="wdg-field">
                            <label>Alias (aparece en la ruta)</label>
                            <input type="text" id="driverAlias" placeholder="Ej: Juan">
                        </div>
                        <div class="wdg-field">
                            <label>RUT</label>
                            <input type="text" id="driverRut" placeholder="Ej: 12345678-9">
                        </div>
                        <div class="wdg-field">
                            <label>Teléfono</label>
                            <input type="text" id="driverTelefono" placeholder="+56912345678">
                        </div>
                        <div class="wdg-field">
                            <label>Email</label>
                            <input type="email" id="driverEmail" placeholder="juan@ejemplo.cl">
                        </div>
                        <div class="wdg-field">
                            <label>Tipo de licencia</label>
                            <select id="driverLicenciaTipo">
                                <option value="">Sin licencia</option>
                                <option value="A1">A1 — Motos hasta 200cc</option>
                                <option value="A2">A2 — Motos sobre 200cc</option>
                                <option value="B">B — Autos y furgones</option>
                                <option value="C">C — Camiones hasta 3.5t</option>
                                <option value="D">D — Transporte pasajeros</option>
                                <option value="F">F — Vehículos especiales</option>
                            </select>
                        </div>
                        <div class="wdg-field">
                            <label>Vencimiento licencia</label>
                            <input type="date" id="driverLicenciaVence">
                        </div>
                        <div class="wdg-field">
                            <label>Estado</label>
                            <label class="wdg-toggle-label">
                                <input type="checkbox" id="driverActivo" checked>
                                <span>Repartidor activo</span>
                            </label>
                        </div>
                    </div>
                    <div class="wdg-driver-form-actions">
                        <button class="button" onclick="wdgCancelDriver()">Cancelar</button>
                        <button class="button button-primary" onclick="wdgSaveDriver()">💾 Guardar</button>
                    </div>
                </div>
            </div><!-- /#wdg-tab-drivers -->

            <!-- ══ TAB: Análisis ══ -->
            <div class="wdg-tab-content" id="wdg-tab-analytics" style="display:none">
                <div class="wdg-analytics-toolbar">
                    <p class="wdg-sub">Análisis histórico de entregas por ruta, repartidor y producto.</p>
                </div>

                <!-- Filtros -->
                <div class="wdg-card wdg-analytics-filters">
                    <div class="wdg-filter-row">
                        <div class="wdg-field">
                            <label>Desde</label>
                            <input type="date" id="anDateFrom">
                        </div>
                        <div class="wdg-field">
                            <label>Hasta</label>
                            <input type="date" id="anDateTo">
                        </div>
                        <div class="wdg-field">
                            <label>Repartidor</label>
                            <select id="anDriver">
                                <option value="">Todos</option>
                            </select>
                        </div>
                        <div class="wdg-field">
                            <label>Estado</label>
                            <select id="anStatus">
                                <option value="">Todos</option>
                                <option value="delivered">✅ Entregado</option>
                                <option value="partial">⚠️ Parcial</option>
                                <option value="not_visited">⛔ No visitado</option>
                                <option value="assigned">📋 Asignado</option>
                            </select>
                        </div>
                        <div class="wdg-field">
                            <label>Producto</label>
                            <input type="text" id="anProduct" placeholder="Nombre del producto...">
                        </div>
                        <div class="wdg-field">
                            <label>ID Pedido</label>
                            <input type="text" id="anOrderId" placeholder="Ej: 1107440" style="width:120px">
                        </div>
                        <div class="wdg-field" style="align-self:flex-end">
                            <button class="button button-primary" onclick="wdgRunAnalytics()">🔍 Buscar</button>
                            <button class="button" onclick="wdgExportAnalytics()" style="margin-left:6px">📥 CSV</button>
                        </div>
                    </div>
                </div>

                <!-- KPIs -->
                <div id="wdgAnalyticsKpis" style="display:none" class="wdg-kpi-row"></div>

                <!-- Tabla -->
                <div id="wdgAnalyticsTable"></div>
            </div><!-- /#wdg-tab-analytics -->

            <!-- ══ TAB: Log de actividad ══ -->
            <div class="wdg-tab-content" id="wdg-tab-log" style="display:none">
                <div class="wdg-log-toolbar">
                    <p class="wdg-sub">Registro de actividad de conductores — llamadas a completar pedidos y sincronización de progreso.</p>
                    <div style="display:flex;gap:8px">
                        <button class="button" onclick="wdgLoadLog()">↻ Actualizar</button>
                        <button class="button" style="color:#b91c1c" onclick="wdgClearLog()">🗑 Limpiar log</button>
                    </div>
                </div>
                <div id="wdgLogPanel" class="wdg-log-panel-full">
                    <span style="color:#94a3b8;font-size:13px">Selecciona esta pestaña para cargar el log.</span>
                </div>
            </div><!-- /#wdg-tab-log -->

            <!-- ══ TAB: Configuración ══ -->
            <div class="wdg-tab-content" id="wdg-tab-config" style="display:none">
                <div class="wdg-card wdg-config-section">
                    <h2>🔑 Google Maps API Key</h2>
                    <p class="wdg-card-desc">Necesitas los servicios <strong>Geocoding API</strong> y <strong>Maps JavaScript API</strong> habilitados en tu proyecto de Google Cloud.</p>
                </div>

                <div class="wdg-card wdg-config-section">
                    <h2>📧 Notificaciones al cliente</h2>
                    <p class="wdg-card-desc">Controla si se envía un correo automático al cliente cuando el repartidor sube la foto de entrega.</p>
                    <div class="wdg-config-toggle-row">
                        <label class="wdg-toggle-label">
                            <input type="checkbox" id="wdgSendPhotoEmail" <?php echo get_option( self::OPT_SEND_EMAIL, '1' ) === '1' ? 'checked' : ''; ?>>
                            <span>Enviar correo con foto al cliente</span>
                        </label>
                        <button id="btnSaveConfig" class="button button-primary">💾 Guardar</button>
                    </div>
                    <div id="wdgConfigStatus" class="wdg-config-status"></div>
                    <div class="wdg-config-api-row">
                        <input type="text" id="wdgConfigApiKey" class="regular-text"
                            value="<?php echo esc_attr( $this->get_api_key() ); ?>"
                            placeholder="AIza...">
                        <button id="btnSaveApiKey" class="button button-primary">💾 Guardar API Key</button>
                        <button id="btnTestApiKey" class="button">🔍 Probar</button>
                    </div>
                    <div id="wdgApiKeyStatus" class="wdg-config-status">
                        <?php if ( $this->get_api_key() ) : ?>
                            <span class="wdg-depot-ok">✅ API Key activa: <code><?php echo esc_html( substr($this->get_api_key(), 0, 8) . '...' . substr($this->get_api_key(), -4) ); ?></code></span>
                        <?php else : ?>
                            <span class="wdg-depot-warn">⚠️ Sin API Key configurada.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /#wdg-tab-config -->

            <!-- ── Modal mover pedido (siempre en DOM) ── -->
            <div id="wdg-move-overlay">
                <div id="wdg-move-box">
                    <h3>🔀 Mover pedido a otra ruta</h3>
                    <p class="wdg-move-addr" id="wdg-move-addr"></p>
                    <div class="wdg-move-groups" id="wdg-move-groups"></div>
                    <div class="wdg-move-actions">
                        <button class="button" onclick="wdgCloseMoveModal()">Cancelar</button>
                        <button class="button button-primary" id="wdg-move-confirm" onclick="wdgConfirmMove()" disabled>Mover →</button>
                    </div>
                </div>
            </div>

            <!-- ── Barra de reasignación masiva (fija, abajo) ── -->
            <div id="wdg-reassign-bar" style="display:none">
                <span id="wdg-reassign-count">0 seleccionados</span>
                <label style="margin:0 6px 0 14px">Mover a:</label>
                <select id="wdg-reassign-target"></select>
                <button class="button button-primary" onclick="wdgDoReassign('single')">Mover a esta ruta</button>
                <button class="button" onclick="wdgDoReassign('auto')" title="Asignar cada uno a la ruta más cercana">📍 Auto: más cercana</button>
                <button class="button" onclick="wdgDoRemoveSelected()" title="Quitar los seleccionados de su ruta" style="color:#b91c1c">🗑 Quitar de ruta</button>
                <button class="button" onclick="wdgToggleReassign(false)">Salir</button>
            </div>

        </div><!-- /.wrap -->
        <?php
    }

    // ── AJAX: guardar bodega ──────────────────────────────────────────────────

    public function ajax_save_depot() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $address = sanitize_text_field( $_POST['address'] ?? '' );
        $api_key = $this->get_api_key();

        if ( empty($address) ) { wp_send_json_error('Dirección vacía'); }
        if ( empty($api_key) ) { wp_send_json_error('API Key no configurada'); }

        // Geocodificar la bodega
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(array(
            'address'  => $address,
            'key'      => $api_key,
            'region'   => 'cl',
            'language' => 'es',
        ));

        $response = wp_remote_get( $url, array('timeout' => 10) );
        if ( is_wp_error($response) ) { wp_send_json_error($response->get_error_message()); }

        $body = json_decode( wp_remote_retrieve_body($response), true );

        if ( isset($body['status']) && $body['status'] === 'OK' && !empty($body['results'][0]['geometry']['location']) ) {
            $loc   = $body['results'][0]['geometry']['location'];
            $depot = array(
                'address' => $body['results'][0]['formatted_address'],
                'lat'     => $loc['lat'],
                'lng'     => $loc['lng'],
            );
            update_option( self::OPT_DEPOT, $depot );
            wp_send_json_success( $depot );
        } else {
            wp_send_json_error( 'Google: ' . ($body['status'] ?? 'Sin resultado') );
        }
    }

    // ── AJAX: obtener pedidos ─────────────────────────────────────────────────

    public function ajax_get_orders() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to']   ?? '' );
        $status    = sanitize_text_field( $_POST['status']    ?? 'any' );

        $exclude_delivered = sanitize_text_field( $_POST['exclude_delivered'] ?? '0' );

        $data = $this->fetch_orders( $date_from, $date_to, $status, $exclude_delivered );
        wp_send_json_success( $data );
    }

    // ── Consulta de pedidos geocodificados (reutilizable) ─────────────────────
    private function fetch_orders( $date_from, $date_to, $status, $exclude_delivered = '0' ) {
        $args = array(
            'limit'   => 1000,
            'orderby' => 'date',
            'order'   => 'DESC',
        );
        // Filtro de fecha opcional: sin rango, trae todos los pedidos del estado
        if ( $date_from && $date_to ) {
            $args['date_created'] = $date_from . '...' . $date_to . ' 23:59:59';
        }
        if ( $status !== 'any' ) $args['status'] = str_replace('wc-', '', $status);

        $orders  = wc_get_orders($args);
        $result  = array();
        $skipped = 0;

        foreach ( $orders as $order ) {
            $lat = floatval( $order->get_meta('_billing_address_lat') );
            $lng = floatval( $order->get_meta('_billing_address_lng') );

            if ( empty($lat) || empty($lng) ) { $skipped++; continue; }
            if ( $lat < self::LAT_MIN || $lat > self::LAT_MAX ||
                 $lng < self::LNG_MIN || $lng > self::LNG_MAX ) { $skipped++; continue; }

            // Filtrar pedidos ya entregados si se solicitó
            if ( $exclude_delivered === '1' && $order->get_meta('_wdg_delivered') === '1' ) {
                $skipped++;
                continue;
            }

            $result[] = array(
                'id'             => $order->get_id(),
                'lat'            => $lat,
                'lng'            => $lng,
                'address'        => $order->get_billing_address_1() ?: $order->get_shipping_address_1(),
                'customer'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'phone'          => $order->get_billing_phone(),
                'email'          => $order->get_billing_email(),
                'note'           => $order->get_customer_note(),
                'address_2'      => $order->get_billing_address_2() ?: $order->get_shipping_address_2(),
                'city'           => $order->get_billing_city() ?: $order->get_shipping_city(),
                'total'          => $order->get_formatted_order_total(),
                'delivered'      => $order->get_meta('_wdg_delivered') === '1',
                'delivered_date' => $order->get_meta('_wdg_delivered_date') ?: '',
                'delivered_by'   => $order->get_meta('_wdg_delivered_by') ?: '',
                'items'          => array_map( function($item) {
                    $product  = $item->get_product();
                    $thumb    = $product ? get_the_post_thumbnail_url($product->get_id(), 'thumbnail') : '';
                    return array(
                        'name'  => $item->get_name(),
                        'qty'   => $item->get_quantity(),
                        'thumb' => $thumb ?: '',
                    );
                }, array_values($order->get_items()) ),
            );
        }

        return array('orders' => $result, 'skipped' => $skipped);
    }

    // ── AJAX: clustering + TSP ────────────────────────────────────────────────

    public function ajax_cluster() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $orders      = json_decode( stripslashes($_POST['orders'] ?? '[]'), true );
        $maxima      = json_decode( stripslashes($_POST['maxima'] ?? '[]'), true );

        if ( empty($orders) ) { wp_send_json_error('Sin pedidos'); }
        if ( empty($maxima) || ! is_array($maxima) ) { wp_send_json_error('Cuotas requeridas'); }

        $maxima        = array_values( array_map('intval', $maxima) );
        $k             = count($maxima);
        $max_per_group = max($maxima);
        $k             = max(1, min(10, $k));
        $depot = $this->get_depot();
        $has_depot = !empty($depot['lat']) && !empty($depot['lng']);

        // ── K-Means++ con influencia de bodega ────────────────────────────────
        // Si hay bodega, añadimos su distancia como factor de peso en la
        // inicialización: los centroides se distribuyen considerando que
        // todos los grupos deben ser alcanzables desde la misma bodega.

        $best         = null;
        $best_inertia = PHP_FLOAT_MAX;

        for ( $attempt = 0; $attempt < 8; $attempt++ ) {
            $centroids = $this->kmeans_plus_plus_init( $orders, $k, $depot, $has_depot );
            $labels    = $this->capacitated_kmeans( $orders, $centroids, $k, $maxima );
            $inertia   = $this->calc_inertia( $orders, $this->calc_centroids($orders, $labels, $k), $labels );

            if ( $inertia < $best_inertia ) {
                $best_inertia = $inertia;
                $best         = array( 'labels' => $labels, 'centroids' => $this->calc_centroids($orders, $labels, $k) );
            }
        }

        $groups = $best['labels'];

        // ── Armar grupos y aplicar TSP con bodega ─────────────────────────────
        $result    = array();

        for ( $g = 0; $g < $k; $g++ ) {
            $group_orders = array();
            foreach ( $groups as $idx => $gid ) {
                if ( $gid === $g ) $group_orders[] = $orders[$idx];
            }

            $center_lat = 0; $center_lng = 0;
            if ( count($group_orders) > 0 ) {
                foreach ( $group_orders as $o ) { $center_lat += $o['lat']; $center_lng += $o['lng']; }
                $center_lat /= count($group_orders);
                $center_lng /= count($group_orders);
            }

            // TSP con bodega como inicio y fin
            $route_km     = 0;
            $depot_to_first_km = 0;
            $last_to_depot_km  = 0;

            if ( count($group_orders) >= 2 ) {
                $group_orders = $this->tsp_optimize( $group_orders, $depot );
                $metrics      = $this->route_metrics( $group_orders, $depot );
                $route_km          = $metrics['route_km'];
                $depot_to_first_km = $metrics['depot_to_first_km'];
                $last_to_depot_km  = $metrics['last_to_depot_km'];
            }

            $result[] = array(
                'group_id'          => $g,
                'name'              => 'R' . ($g+1),
                'count'             => count($group_orders),
                'center'            => array('lat' => $center_lat, 'lng' => $center_lng),
                'route_km'          => $route_km,
                'depot_to_first_km' => $depot_to_first_km,
                'last_to_depot_km'  => $last_to_depot_km,
                'orders'            => $group_orders,
            );
        }

        wp_send_json_success( array('groups' => $result, 'inertia' => $best_inertia, 'has_depot' => $has_depot) );
    }

    // ── Capacitated K-Means ───────────────────────────────────────────────────
    // Restricciones de capacidad aplicadas DURANTE la asignación.
    // Evita el rebalanceo post-hoc que distorsiona los grupos.

    private function capacitated_kmeans( $points, $centroids, $k, $maxima ) {
        $n        = count($points);
        $max_iter = 100;

        for ( $iter = 0; $iter < $max_iter; $iter++ ) {

            // 1. Calcular distancias de cada punto a cada centroide
            $distances = array();
            for ( $i = 0; $i < $n; $i++ ) {
                $row = array();
                for ( $g = 0; $g < $k; $g++ ) {
                    $row[$g] = $this->haversine(
                        $points[$i]['lat'], $points[$i]['lng'],
                        $centroids[$g]['lat'], $centroids[$g]['lng']
                    );
                }
                $distances[$i] = $row;
            }

            // 2. Ordenar puntos por "ventaja" descendente
            // Ventaja = diferencia entre 2° y 1° centroide más cercano
            // Los puntos con mayor ventaja se asignan primero (más seguros)
            $priority = array();
            for ( $i = 0; $i < $n; $i++ ) {
                $vals = $distances[$i];
                asort($vals);
                $vals = array_values($vals);
                $priority[$i] = isset($vals[1]) ? $vals[1] - $vals[0] : 0;
            }
            arsort($priority);

            // 3. Asignar con capacidad
            $labels = array_fill(0, $n, 0);
            $sizes  = array_fill(0, $k, 0);

            foreach ( array_keys($priority) as $i ) {
                $drow = $distances[$i];
                asort($drow);
                $assigned = false;
                foreach ( array_keys($drow) as $g ) {
                    $cap = isset($maxima[$g]) ? $maxima[$g] : PHP_INT_MAX;
                    if ( $sizes[$g] < $cap ) {
                        $labels[$i] = $g;
                        $sizes[$g]++;
                        $assigned = true;
                        break;
                    }
                }
                if ( ! $assigned ) {
                    // Fallback: asignar al menos lleno
                    $min_g = 0;
                    for ( $g = 1; $g < $k; $g++ ) {
                        if ( $sizes[$g] < $sizes[$min_g] ) $min_g = $g;
                    }
                    $labels[$i] = $min_g;
                    $sizes[$min_g]++;
                }
            }

            // 4. Actualizar centroides
            $new_centroids = $this->calc_centroids( $points, $labels, $k );

            // 5. Verificar convergencia
            $converged = true;
            for ( $g = 0; $g < $k; $g++ ) {
                if ( abs($new_centroids[$g]['lat'] - $centroids[$g]['lat']) > 0.0001 ||
                     abs($new_centroids[$g]['lng'] - $centroids[$g]['lng']) > 0.0001 ) {
                    $converged = false;
                    break;
                }
            }
            $centroids = $new_centroids;
            if ( $converged ) break;
        }

        return $labels;
    }

    // ── K-Means++ con ponderación de bodega ───────────────────────────────────

    private function kmeans_plus_plus_init( $points, $k, $depot, $has_depot ) {
        $centroids = array();

        if ( $has_depot && count($points) >= $k ) {
            // Primer centroide: el punto más cercano a la bodega
            $min_d = PHP_FLOAT_MAX;
            $best  = 0;
            foreach ( $points as $i => $p ) {
                $d = $this->haversine( $depot['lat'], $depot['lng'], $p['lat'], $p['lng'] );
                if ( $d < $min_d ) { $min_d = $d; $best = $i; }
            }
            $centroids[] = $points[$best];
        } else {
            $centroids[] = $points[ array_rand($points) ];
        }

        for ( $c = 1; $c < $k; $c++ ) {
            $distances = array();
            $total     = 0;

            foreach ( $points as $p ) {
                $min_d = PHP_FLOAT_MAX;
                foreach ( $centroids as $centroid ) {
                    $d = $this->haversine( $p['lat'], $p['lng'], $centroid['lat'], $centroid['lng'] );
                    if ( $d < $min_d ) $min_d = $d;
                }
                $dist2       = $min_d * $min_d;
                $distances[] = $dist2;
                $total      += $dist2;
            }

            if ( $total == 0 ) { $centroids[] = $points[ array_rand($points) ]; continue; }

            $rand   = (mt_rand() / mt_getrandmax()) * $total;
            $cumul  = 0;
            $chosen = count($points) - 1;
            foreach ( $distances as $i => $d ) {
                $cumul += $d;
                if ( $cumul >= $rand ) { $chosen = $i; break; }
            }
            $centroids[] = $points[$chosen];
        }

        return $centroids;
    }

    // ── K-Means iterativo ─────────────────────────────────────────────────────

    private function kmeans_iterate( $points, $centroids, $k, $max_iter = 100 ) {
        $labels = array_fill(0, count($points), 0);

        for ( $iter = 0; $iter < $max_iter; $iter++ ) {
            $changed = false;
            foreach ( $points as $i => $p ) {
                $min_d = PHP_FLOAT_MAX; $best = 0;
                foreach ( $centroids as $c => $centroid ) {
                    $d = $this->haversine( $p['lat'], $p['lng'], $centroid['lat'], $centroid['lng'] );
                    if ( $d < $min_d ) { $min_d = $d; $best = $c; }
                }
                if ( $labels[$i] !== $best ) { $labels[$i] = $best; $changed = true; }
            }
            if ( !$changed ) break;

            $sums = array_fill(0, $k, array('lat'=>0,'lng'=>0,'count'=>0));
            foreach ( $points as $i => $p ) {
                $g = $labels[$i];
                $sums[$g]['lat']   += $p['lat'];
                $sums[$g]['lng']   += $p['lng'];
                $sums[$g]['count'] += 1;
            }
            foreach ( $sums as $g => $s ) {
                if ( $s['count'] > 0 )
                    $centroids[$g] = array('lat' => $s['lat']/$s['count'], 'lng' => $s['lng']/$s['count']);
            }
        }
        return array('labels' => $labels, 'centroids' => $centroids);
    }

    // ── Rebalanceo ────────────────────────────────────────────────────────────

    private function rebalance( $points, $labels, $k, $max_per_group, $maxima = array() ) {
        $groups    = $labels;
        $sizes     = array_fill(0, $k, 0);
        foreach ( $groups as $g ) $sizes[$g]++;
        $centroids = $this->calc_centroids( $points, $groups, $k );

        for ( $pass = 0; $pass < 50; $pass++ ) {
            $overflow = false;
            for ( $g = 0; $g < $k; $g++ ) {
                $group_max = isset($maxima[$g]) ? $maxima[$g] : $max_per_group;
                if ( $sizes[$g] <= $group_max ) continue;
                $overflow   = true;
                $candidates = array();
                for ( $h = 0; $h < $k; $h++ ) {
                    $cand_max = isset($maxima[$h]) ? $maxima[$h] : $max_per_group;
                    if ( $h !== $g && $sizes[$h] < $cand_max ) $candidates[] = $h;
                }
                if ( empty($candidates) ) continue;

                $excess    = $sizes[$g] - $group_max;

                // Mover los puntos más lejanos del centroide propio al grupo más cercano disponible
                $group_pts = array();
                foreach ( $points as $i => $p ) {
                    if ( $groups[$i] === $g )
                        $group_pts[$i] = $this->haversine($p['lat'],$p['lng'],$centroids[$g]['lat'],$centroids[$g]['lng']);
                }
                arsort($group_pts); // más lejanos primero

                $moved = 0;
                foreach ( $group_pts as $i => $dist ) {
                    if ( $moved >= $excess ) break;
                    $best_cand = null; $best_d = PHP_FLOAT_MAX;
                    foreach ( $candidates as $h ) {
                        $h_max = isset($maxima[$h]) ? $maxima[$h] : $max_per_group;
                        if ( $sizes[$h] >= $h_max ) continue;
                        $d = $this->haversine($points[$i]['lat'],$points[$i]['lng'],$centroids[$h]['lat'],$centroids[$h]['lng']);
                        if ( $d < $best_d ) { $best_d = $d; $best_cand = $h; }
                    }
                    if ( $best_cand !== null ) {
                        $sizes[$groups[$i]]--;
                        $groups[$i] = $best_cand;
                        $sizes[$best_cand]++;
                        $moved++;
                    }
                }
                $centroids = $this->calc_centroids($points, $groups, $k);
            }
            if ( !$overflow ) break;
        }
        return $groups;
    }

    // ── TSP: Nearest Neighbor desde bodega ────────────────────────────────────

    // ── TSP reutilizable: reordena pedidos (NN + 2-opt) ───────────────────────
    // $anchor = punto {lat,lng} desde donde arranca la ruta. Si es null, usa la
    // bodega (si existe); si no hay ni anchor ni bodega, arranca por el punto
    // más al norte (comportamiento histórico de tsp_nearest_neighbor).
    private function tsp_optimize( $orders, $depot, $anchor = null ) {
        $pts = array_values( $orders );
        $n   = count( $pts );
        if ( $n < 2 ) return $pts;

        $has_depot = ! empty($depot['lat']) && ! empty($depot['lng']);
        if ( empty($anchor['lat']) ) {
            $anchor = $has_depot ? $depot : null;
        }

        if ( $anchor && ! empty($anchor['lat']) ) {
            $nn_route = $this->tsp_nearest_neighbor_depot( $pts, $anchor );
        } else {
            $nn_route = $this->tsp_nearest_neighbor( $pts );
        }
        $opt_route = $this->tsp_two_opt( $pts, $nn_route );

        $ordered = array();
        foreach ( $opt_route as $idx ) $ordered[] = $pts[$idx];
        return $ordered;
    }

    // ── Métricas de una ruta ya ordenada (km total + tramos de bodega) ────────
    private function route_metrics( $ordered, $depot ) {
        $pts = array_values( $ordered );
        $n   = count( $pts );
        $result = array( 'route_km' => 0, 'depot_to_first_km' => 0, 'last_to_depot_km' => 0 );
        if ( $n < 2 ) return $result;

        $between = $this->tsp_total_distance( $pts, range(0, $n - 1) );

        if ( ! empty($depot['lat']) && ! empty($depot['lng']) ) {
            $first = $pts[0];
            $last  = $pts[ $n - 1 ];
            $result['depot_to_first_km'] = round( $this->haversine($depot['lat'], $depot['lng'], $first['lat'], $first['lng']), 2 );
            $result['last_to_depot_km']  = round( $this->haversine($last['lat'], $last['lng'], $depot['lat'], $depot['lng']), 2 );
            $result['route_km']          = round( $result['depot_to_first_km'] + $between + $result['last_to_depot_km'], 2 );
        } else {
            $result['route_km'] = round( $between, 2 );
        }
        return $result;
    }

    private function tsp_nearest_neighbor_depot( $points, $depot ) {
        $n       = count($points);
        $visited = array_fill(0, $n, false);
        $route   = array();

        // Inicio: el punto más cercano a la bodega
        $min_d   = PHP_FLOAT_MAX;
        $start   = 0;
        foreach ( $points as $i => $p ) {
            $d = $this->haversine( $depot['lat'], $depot['lng'], $p['lat'], $p['lng'] );
            if ( $d < $min_d ) { $min_d = $d; $start = $i; }
        }

        $current           = $start;
        $visited[$current] = true;
        $route[]           = $current;

        for ( $step = 1; $step < $n; $step++ ) {
            $best_d   = PHP_FLOAT_MAX;
            $best_idx = -1;
            foreach ( $points as $j => $p ) {
                if ( $visited[$j] ) continue;
                $d = $this->haversine( $points[$current]['lat'], $points[$current]['lng'], $p['lat'], $p['lng'] );
                if ( $d < $best_d ) { $best_d = $d; $best_idx = $j; }
            }
            $current           = $best_idx;
            $visited[$current] = true;
            $route[]           = $current;
        }

        return $route;
    }

    // ── TSP: Nearest Neighbor sin bodega ──────────────────────────────────────

    private function tsp_nearest_neighbor( $points ) {
        $n       = count($points);
        $visited = array_fill(0, $n, false);
        $route   = array();
        $max_lat = -PHP_FLOAT_MAX; $start = 0;
        foreach ( $points as $i => $p ) {
            if ( $p['lat'] > $max_lat ) { $max_lat = $p['lat']; $start = $i; }
        }
        $current           = $start;
        $visited[$current] = true;
        $route[]           = $current;
        for ( $step = 1; $step < $n; $step++ ) {
            $best_d = PHP_FLOAT_MAX; $best_idx = -1;
            foreach ( $points as $j => $p ) {
                if ( $visited[$j] ) continue;
                $d = $this->haversine( $points[$current]['lat'], $points[$current]['lng'], $p['lat'], $p['lng'] );
                if ( $d < $best_d ) { $best_d = $d; $best_idx = $j; }
            }
            $current = $best_idx; $visited[$current] = true; $route[] = $current;
        }
        return $route;
    }

    // ── TSP: 2-opt ────────────────────────────────────────────────────────────

    private function tsp_two_opt( $points, $route ) {
        $n = count($route); $improved = true;
        while ( $improved ) {
            $improved = false;
            for ( $i = 0; $i < $n-1; $i++ ) {
                for ( $j = $i+2; $j < $n; $j++ ) {
                    if ( $i === 0 && $j === $n-1 ) continue;
                    $a = $route[$i]; $b = $route[$i+1]; $c = $route[$j]; $d = $route[($j+1)%$n];
                    $before = $this->haversine($points[$a]['lat'],$points[$a]['lng'],$points[$b]['lat'],$points[$b]['lng'])
                            + $this->haversine($points[$c]['lat'],$points[$c]['lng'],$points[$d]['lat'],$points[$d]['lng']);
                    $after  = $this->haversine($points[$a]['lat'],$points[$a]['lng'],$points[$c]['lat'],$points[$c]['lng'])
                            + $this->haversine($points[$b]['lat'],$points[$b]['lng'],$points[$d]['lat'],$points[$d]['lng']);
                    if ( $after < $before - 0.001 ) {
                        $seg = array_slice($route, $i+1, $j-$i);
                        array_splice($route, $i+1, $j-$i, array_reverse($seg));
                        $improved = true;
                    }
                }
            }
        }
        return $route;
    }

    private function tsp_total_distance( $points, $route ) {
        $total = 0; $n = count($route);
        for ( $i = 0; $i < $n-1; $i++ )
            $total += $this->haversine($points[$route[$i]]['lat'],$points[$route[$i]]['lng'],$points[$route[$i+1]]['lat'],$points[$route[$i+1]]['lng']);
        return round($total, 2);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function haversine( $lat1, $lng1, $lat2, $lng2 ) {
        $r    = 6371;
        $dlat = deg2rad($lat2-$lat1); $dlng = deg2rad($lng2-$lng1);
        $a    = sin($dlat/2)*sin($dlat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dlng/2)*sin($dlng/2);
        return $r * 2 * atan2(sqrt($a), sqrt(1-$a));
    }

    private function calc_centroids( $points, $labels, $k ) {
        $sums = array_fill(0, $k, array('lat'=>0,'lng'=>0,'count'=>0));
        foreach ( $points as $i => $p ) {
            $g = $labels[$i];
            $sums[$g]['lat'] += $p['lat']; $sums[$g]['lng'] += $p['lng']; $sums[$g]['count']++;
        }
        $centroids = array();
        foreach ( $sums as $g => $s )
            $centroids[$g] = $s['count'] > 0 ? array('lat'=>$s['lat']/$s['count'],'lng'=>$s['lng']/$s['count']) : array('lat'=>0,'lng'=>0);
        return $centroids;
    }

    private function calc_inertia( $points, $centroids, $labels ) {
        $inertia = 0;
        foreach ( $points as $i => $p ) {
            $c = $centroids[$labels[$i]];
            $d = $this->haversine($p['lat'],$p['lng'],$c['lat'],$c['lng']);
            $inertia += $d*$d;
        }
        return $inertia;
    }






    // ── Actualizar BD si la versión del schema cambió ─────────────────────────
    public function maybe_upgrade_db() {
        global $wpdb;
        // Verificar si la columna group_name existe, si no agregarla
        $table   = $this->events_table();
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
        if ( ! in_array('group_name', $columns) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN group_name VARCHAR(20) NOT NULL DEFAULT '' AFTER driver_name" );
            $this->log('OK', 'Columna group_name agregada a wp_wdg_events');
        }
        if ( ! in_array('vehicle', $columns) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN vehicle VARCHAR(60) NOT NULL DEFAULT '' AFTER group_name" );
            $this->log('OK', 'Columna vehicle agregada a wp_wdg_events');
        }
    }

    // ── Activación del plugin: crear tabla de eventos ─────────────────────────
    public function activate() {
        global $wpdb;
        $table   = $wpdb->prefix . 'wdg_events';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id       VARCHAR(20)  NOT NULL,
            plan_name     VARCHAR(100) NOT NULL,
            route_date    DATE         NOT NULL,
            driver_id     VARCHAR(20)  NOT NULL DEFAULT '',
            driver_name   VARCHAR(100) NOT NULL DEFAULT '',
            group_name    VARCHAR(20)  NOT NULL DEFAULT '',
            vehicle       VARCHAR(60)  NOT NULL DEFAULT '',
            order_id      BIGINT UNSIGNED NOT NULL,
            customer      VARCHAR(100) NOT NULL DEFAULT '',
            address       VARCHAR(200) NOT NULL DEFAULT '',
            city          VARCHAR(60)  NOT NULL DEFAULT '',
            products      TEXT,
            status        ENUM('assigned','delivered','partial','not_visited') NOT NULL DEFAULT 'assigned',
            partial_note  TEXT,
            photo_url     VARCHAR(500) NOT NULL DEFAULT '',
            recipient     VARCHAR(100) NOT NULL DEFAULT '',
            assigned_at   DATETIME     NOT NULL,
            updated_at    DATETIME     NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_plan_id   (plan_id),
            INDEX idx_order_id  (order_id),
            INDEX idx_driver_id (driver_id),
            INDEX idx_route_date(route_date),
            INDEX idx_status    (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Limpieza automática de fotos antiguas ─────────────────────────────────
    // Se ejecuta una vez por día vía admin_init + transient.
    // Elimina attachments de la Media Library con título "Entrega Pedido #*"
    // que tengan más de 2 meses de antigüedad.

    public function maybe_cleanup_photos() {
        if ( get_transient( 'wdg_photo_cleanup_ran' ) ) return;
        set_transient( 'wdg_photo_cleanup_ran', 1, DAY_IN_SECONDS );
        $this->cleanup_old_photos();
    }

    private function cleanup_old_photos() {
        global $wpdb;

        $cutoff = date( 'Y-m-d H:i:s', strtotime( '-2 months' ) );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type   = 'attachment'
               AND post_title  LIKE %s
               AND post_date   < %s",
            'Entrega Pedido #%',
            $cutoff
        ) );

        if ( empty( $ids ) ) return;

        foreach ( $ids as $id ) {
            wp_delete_attachment( (int) $id, true );
        }

        $this->log( 'INFO', 'cleanup_old_photos: eliminadas ' . count($ids) . ' fotos anteriores a ' . $cutoff );
    }

    private function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'wdg_events';
    }

    // ── Log de debug ──────────────────────────────────────────────────────────

    private function log( $level, $message, $context = array() ) {
        $entry = array(
            'time'    => date('H:i:s'),
            'level'   => $level, // OK, ERROR, WARN, INFO
            'message' => $message,
            'context' => $context,
        );
        $log = get_option('wdg_debug_log', array());
        array_unshift($log, $entry);       // más recientes primero
        $log = array_slice($log, 0, 200);  // máx 200 entradas
        update_option('wdg_debug_log', $log, false);
    }

    public function ajax_get_log() {
        check_ajax_referer('wdg_nonce', 'nonce');
        wp_send_json_success( get_option('wdg_debug_log', array()) );
    }

    public function ajax_clear_log() {
        check_ajax_referer('wdg_nonce', 'nonce');
        delete_option('wdg_debug_log');
        wp_send_json_success('Log limpiado');
    }


    // ── AJAX: conductor sube foto de entrega ──────────────────────────────────

    public function ajax_upload_photo() {
        $token    = sanitize_text_field( $_POST['token']    ?? '' );
        $order_id = intval( $_POST['order_id'] ?? 0 );

        $this->log('INFO', 'upload_photo llamado', array(
            'token'    => substr($token, 0, 6) . '…',
            'order_id' => $order_id,
        ));

        if ( empty($token) || ! $order_id ) {
            wp_send_json_error('Datos requeridos');
        }

        // Validar token
        $payload = get_option( 'wdg_route_' . $token );
        if ( empty($payload) || $payload['expiry'] < time() ) {
            $this->log('ERROR', 'upload_photo: token inválido');
            wp_send_json_error('Token inválido o expirado');
        }

        // Validar que el pedido pertenece al grupo
        $order_ids = array_map('intval', array_column( $payload['group']['orders'] ?? array(), 'id' ));
        if ( ! in_array( $order_id, $order_ids ) ) {
            wp_send_json_error('Pedido no pertenece a este grupo');
        }

        // Verificar que se subió un archivo
        if ( empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK ) {
            $this->log('ERROR', 'upload_photo: sin archivo', array('files' => $_FILES));
            wp_send_json_error('No se recibió la foto');
        }

        // Incluir funciones de WordPress para manejo de archivos
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Renombrar el archivo con un nombre descriptivo
        $ext      = pathinfo( $_FILES['photo']['name'], PATHINFO_EXTENSION ) ?: 'jpg';
        $filename = sprintf('entrega-pedido-%d-%s.%s', $order_id, date('Ymd-His'), $ext);
        $_FILES['photo']['name'] = $filename;

        // Subir a WordPress Media Library
        $upload = wp_handle_upload( $_FILES['photo'], array(
            'test_form' => false,
            'mimes'     => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'webp'         => 'image/webp',
            ),
        ));

        if ( isset($upload['error']) ) {
            $this->log('ERROR', 'upload_photo: error al subir', array('error' => $upload['error']));
            wp_send_json_error('Error al subir la foto: ' . $upload['error']);
        }

        // Crear attachment en la Media Library
        $attachment_id = wp_insert_attachment( array(
            'guid'           => $upload['url'],
            'post_mime_type' => $upload['type'],
            'post_title'     => sprintf('Entrega Pedido #%d', $order_id),
            'post_status'    => 'inherit',
        ), $upload['file'] );

        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']) );

        $recipient = sanitize_text_field( $_POST['recipient'] ?? '' );

        // Guardar en meta del pedido y agregar nota con link
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_wdg_delivery_photo_id',  $attachment_id );
            $order->update_meta_data( '_wdg_delivery_photo_url', $upload['url'] );
            if ( ! empty($recipient) ) {
                $order->update_meta_data( '_wdg_delivery_recipient', $recipient );
            }

            $note_text = sprintf(
                '📷 Foto de entrega — %s%s: <a href="%s" target="_blank">Ver foto</a>',
                esc_html( $payload['group']['name'] ?? 'Repartidor' ),
                ! empty($recipient) ? ' · Recibido por: ' . esc_html($recipient) : '',
                esc_url( $upload['url'] )
            );
            $order->add_order_note( $note_text, false, false );
            $order->save();

            // Enviar email al cliente con foto adjunta (si está habilitado en Configuración)
            $send_email = get_option( self::OPT_SEND_EMAIL, '1' );
            $to = $order->get_billing_email();
            if ( $send_email === '1' && ! empty($to) ) {
                $name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                $subject = 'Tu pedido #' . $order_id . ' ha sido entregado';
                $body    = '<p>Hola ' . esc_html($name) . ',</p>'
                         . '<p>Tu pedido <strong>#' . $order_id . '</strong> ha sido entregado exitosamente.</p>'
                         . ( ! empty($recipient) ? '<p>Recibido por: <strong>' . esc_html($recipient) . '</strong></p>' : '' )
                         . '<p>Adjuntamos una foto como comprobante de la entrega.</p>'
                         . '<p>Gracias por tu compra.</p>';
                $headers = array( 'Content-Type: text/html; charset=UTF-8' );
                $sent    = wp_mail( $to, $subject, $body, $headers, array( $upload['file'] ) );

                $this->log(
                    $sent ? 'OK' : 'WARN',
                    $sent ? 'Email enviado al cliente' : 'Error al enviar email',
                    array( 'to' => $to, 'order_id' => $order_id )
                );
            }
        }

        $this->log('OK', 'upload_photo: foto subida', array(
            'order_id'      => $order_id,
            'attachment_id' => $attachment_id,
            'url'           => $upload['url'],
        ));

        wp_send_json_success( array(
            'url'           => $upload['url'],
            'attachment_id' => $attachment_id,
        ));
    }



    // ── AJAX: conductor termina la ruta → marcar not_visited ─────────────────

    public function ajax_finish_route() {
        $token        = sanitize_text_field( $_POST['token']    ?? '' );
        $pending_json = stripslashes(        $_POST['pending']  ?? '[]' );
        $pending_ids  = json_decode( $pending_json, true );

        if ( empty($token) ) { wp_send_json_error('Token requerido'); }

        $payload = get_option( 'wdg_route_' . $token );
        if ( empty($payload) ) { wp_send_json_error('Token inválido'); }

        $plan_id = $payload['plan_id'] ?? '';
        if ( ! $plan_id ) { wp_send_json_success('Sin plan_id, nada que registrar'); }

        // Marcar pedidos pendientes como 'not_visited'
        foreach ( (array)$pending_ids as $order_id ) {
            $this->update_event_status( $plan_id, intval($order_id), 'not_visited' );
        }

        $this->log('OK', 'finish_route: not_visited marcados', array(
            'plan_id' => $plan_id,
            'count'   => count($pending_ids),
        ));

        wp_send_json_success( array('marked' => count($pending_ids)) );
    }

    // ── Insertar/actualizar eventos al generar link de conductor ─────────────

    // Escribe los metas de ruta (_wdg_route, _wdg_plan_id, _wdg_plan_name,
    // _wdg_stop_position) en cada pedido WooCommerce de un grupo.
    private function write_order_route_metas( $group, $plan_id, $plan_name ) {
        // Nombre de ruta = "Rx - Nombre repartidor" (o solo "Rx" si no hay repartidor)
        $driver     = trim( $group['driver_name'] ?? '' );
        $route_name = ( $group['name'] ?? '' ) . ( $driver !== '' ? ' - ' . $driver : '' );

        // Repartidor y vehículo del grupo
        $driver_id = $group['driver_id'] ?? '';
        $vehicle   = $group['vehicle'] ?? ''; // label "PATENTE (Tipo)"
        $patente   = $group['vehicle_patente'] ?? '';
        if ( $patente === '' && $vehicle !== '' ) {
            $patente = trim( explode( ' (', $vehicle )[0] ); // derivar de "PATENTE (Tipo)"
        }

        foreach ( ($group['orders'] ?? array()) as $stop_idx => $order ) {
            $order_id = intval($order['id'] ?? 0);
            if ( ! $order_id ) continue;
            $wc_order = wc_get_order( $order_id );
            if ( ! $wc_order ) continue;
            $wc_order->update_meta_data( '_wdg_route',         $route_name );
            $wc_order->update_meta_data( '_wdg_plan_id',       $plan_id );
            $wc_order->update_meta_data( '_wdg_plan_name',     $plan_name );
            $wc_order->update_meta_data( '_wdg_stop_position', intval($stop_idx) + 1 );
            $wc_order->update_meta_data( '_wdg_driver_id',     $driver_id );
            $wc_order->update_meta_data( '_wdg_driver_name',   $driver );
            $wc_order->update_meta_data( '_wdg_vehicle',       $vehicle );
            $wc_order->update_meta_data( '_wdg_patente',       $patente );
            $wc_order->save();
        }
    }

    // Borra los metas de ruta de un pedido (queda sin asignar).
    private function clear_order_route_metas( $order_id ) {
        $wc_order = wc_get_order( $order_id );
        if ( ! $wc_order ) return;
        foreach ( array(
            '_wdg_route', '_wdg_plan_id', '_wdg_plan_name', '_wdg_stop_position',
            '_wdg_driver_id', '_wdg_driver_name', '_wdg_vehicle', '_wdg_patente',
        ) as $meta_key ) {
            $wc_order->delete_meta_data( $meta_key );
        }
        $wc_order->save();
    }

    // Borra los metas de ruta de los pedidos de un plan (al eliminar la
    // planificación). Solo limpia el pedido si sigue perteneciendo a este plan.
    private function clear_plan_order_metas( $plan, $plan_id ) {
        foreach ( ($plan['groups'] ?? array()) as $group ) {
            foreach ( ($group['orders'] ?? array()) as $order ) {
                $order_id = intval($order['id'] ?? 0);
                if ( ! $order_id ) continue;
                $wc_order = wc_get_order( $order_id );
                if ( ! $wc_order ) continue;
                if ( (string) $wc_order->get_meta('_wdg_plan_id') !== (string) $plan_id ) continue;
                $this->clear_order_route_metas( $order_id );
            }
        }
    }

    private function insert_assigned_events( $payload ) {
        global $wpdb;
        $table      = $this->events_table();
        $plan_id    = $payload['plan_id']   ?? '';
        $plan_name  = $payload['plan_name'] ?? '';
        $group      = $payload['group']     ?? array();
        $driver_id  = $group['driver_id']   ?? '';
        $driver_name= $group['driver_name'] ?? $group['name'] ?? '';
        $vehicle    = $group['vehicle']     ?? '';
        $now        = current_time('mysql');
        $route_date = current_time('Y-m-d');

        // Guardar metas de ruta en cada pedido WooCommerce (persiste en el pedido)
        $this->write_order_route_metas( $group, $plan_id, $plan_name );

        foreach ( ($group['orders'] ?? array()) as $order ) {
            $order_id = intval($order['id'] ?? 0);
            if ( ! $order_id ) continue;

            $products_json = wp_json_encode(
                array_map(function($it){
                    return array('name' => $it['name'] ?? '', 'qty' => $it['qty'] ?? 1);
                }, $order['items'] ?? array())
            );

            // Verificar si ya existe (re-generación de link)
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE plan_id = %s AND order_id = %d",
                $plan_id, $order_id
            ));

            if ( $existing ) {
                // Actualizar driver / vehículo / grupo si cambiaron
                $wpdb->update( $table,
                    array(
                        'driver_id'   => $driver_id,
                        'driver_name' => $driver_name,
                        'group_name'  => $group['name'] ?? '',
                        'vehicle'     => $vehicle,
                        'updated_at'  => $now,
                    ),
                    array( 'id' => $existing ),
                    array('%s','%s','%s','%s','%s'), array('%d')
                );
            } else {
                $wpdb->insert( $table, array(
                    'plan_id'     => $plan_id,
                    'plan_name'   => $plan_name,
                    'route_date'  => $route_date,
                    'driver_id'   => $driver_id,
                    'driver_name' => $driver_name,
                    'group_name'  => $group['name'] ?? '',
                    'vehicle'     => $vehicle,
                    'order_id'    => $order_id,
                    'customer'    => $order['customer'] ?? '',
                    'address'     => $order['address']  ?? '',
                    'city'        => $order['city']     ?? '',
                    'products'    => $products_json,
                    'status'      => 'assigned',
                    'assigned_at' => $now,
                    'updated_at'  => $now,
                ), array('%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s') );
                if ( $wpdb->last_error ) {
                    $this->log('ERROR', 'insert_assigned_events: ' . $wpdb->last_error, array('order_id' => $order_id));
                }
            }
        }
    }

    // ── Actualizar estado de evento ────────────────────────────────────────────

    private function update_event_status( $plan_id, $order_id, $status, $extra = array() ) {
        global $wpdb;
        $data = array_merge(
            array( 'status' => $status, 'updated_at' => current_time('mysql') ),
            $extra
        );
        $wpdb->update(
            $this->events_table(),
            $data,
            array( 'plan_id' => $plan_id, 'order_id' => intval($order_id) ),
            null,
            array( '%s', '%d' )
        );
    }

    // ── AJAX: conductor marca pedido como completado en WooCommerce ───────────
    // nopriv = sin login (el conductor no está autenticado)

    public function ajax_complete_order() {
        $token    = sanitize_text_field( $_POST['token']    ?? '' );
        $order_id = intval( $_POST['order_id'] ?? 0 );

        $this->log('INFO', 'complete_order llamado', array(
            'token'    => substr($token, 0, 6) . '…',
            'order_id' => $order_id,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        ));

        if ( empty($token) || ! $order_id ) {
            $this->log('ERROR', 'Datos requeridos faltantes', array('token_empty' => empty($token), 'order_id' => $order_id));
            wp_send_json_error('Datos requeridos');
        }

        $payload = get_option( 'wdg_route_' . $token );
        if ( empty($payload) ) {
            $this->log('ERROR', 'Token no encontrado en BD', array('token' => substr($token,0,6).'…'));
            wp_send_json_error('Token inválido o expirado');
        }
        if ( $payload['expiry'] < time() ) {
            $this->log('ERROR', 'Token expirado', array(
                'expiry'  => date('d/m/Y H:i', $payload['expiry']),
                'now'     => date('d/m/Y H:i'),
            ));
            wp_send_json_error('Token expirado');
        }

        $order_ids_in_group = array_map('intval', array_column( $payload['group']['orders'] ?? array(), 'id' ));
        if ( ! in_array( $order_id, $order_ids_in_group ) ) {
            $this->log('ERROR', 'Pedido no pertenece al grupo', array(
                'order_id'       => $order_id,
                'group_order_ids' => $order_ids_in_group,
            ));
            wp_send_json_error('Pedido no pertenece a este grupo');
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->log('ERROR', 'Pedido no encontrado en WC', array('order_id' => $order_id));
            wp_send_json_error('Pedido no encontrado');
        }

        $status_before = $order->get_status();
        if ( $status_before !== 'completed' ) {
            $order->update_status(
                'completed',
                sprintf( 'Marcado como completado por conductor: %s', $payload['group']['name'] ?? 'Repartidor' )
            );
            $this->log('OK', 'Estado cambiado a completed', array(
                'order_id' => $order_id,
                'antes'    => $status_before,
                'ahora'    => 'completed',
                'grupo'    => $payload['group']['name'] ?? '',
            ));
        } else {
            $this->log('INFO', 'Pedido ya estaba completado', array('order_id' => $order_id));
        }

        $order->update_meta_data( '_wdg_delivered',      '1' );
        $order->update_meta_data( '_wdg_delivered_date', date('Y-m-d') );
        $order->update_meta_data( '_wdg_delivered_by',   $payload['group']['name'] ?? '' );
        $result = $order->save();

        $this->log('OK', 'Pedido guardado', array(
            'order_id'  => $order_id,
            'save_result' => $result,
            'status'    => $order->get_status(),
        ));

        // Actualizar evento en tabla de análisis
        $plan_id = $payload['plan_id'] ?? '';
        if ( $plan_id ) {
            $this->update_event_status( $plan_id, $order_id, 'delivered', array(
                'recipient' => sanitize_text_field( $_POST['recipient'] ?? '' ),
                'photo_url' => $order->get_meta('_wdg_delivery_photo_url') ?: '',
            ));
        }

        wp_send_json_success( array(
            'order_id' => $order_id,
            'status'   => $order->get_status(),
        ));
    }


    // ── AJAX: conductor marca pedido como parcial (no entregado en este intento) ──

    public function ajax_partial_order() {
        $token    = sanitize_text_field( $_POST['token']    ?? '' );
        $order_id = intval( $_POST['order_id'] ?? 0 );

        $this->log('INFO', 'partial_order llamado', array(
            'token'    => substr($token, 0, 6) . '…',
            'order_id' => $order_id,
        ));

        if ( empty($token) || ! $order_id ) {
            $this->log('ERROR', 'partial_order: datos faltantes');
            wp_send_json_error('Datos requeridos');
        }

        $payload = get_option( 'wdg_route_' . $token );
        if ( empty($payload) || $payload['expiry'] < time() ) {
            $this->log('ERROR', 'partial_order: token inválido o expirado');
            wp_send_json_error('Token inválido o expirado');
        }

        $order_ids_in_group = array_map('intval', array_column( $payload['group']['orders'] ?? array(), 'id' ));
        if ( ! in_array( $order_id, $order_ids_in_group ) ) {
            $this->log('ERROR', 'partial_order: pedido no pertenece al grupo', array('order_id' => $order_id));
            wp_send_json_error('Pedido no pertenece a este grupo');
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->log('ERROR', 'partial_order: pedido no encontrado', array('order_id' => $order_id));
            wp_send_json_error('Pedido no encontrado');
        }

        $status_before = $order->get_status();

        // Estado YITH personalizado para entrega parcial/pendiente
        $target_status = 'en-ruta-pendiente';

        // Verificar que el estado existe, si no usar on-hold como fallback
        $all_statuses = wc_get_order_statuses();
        if ( ! isset($all_statuses['wc-' . $target_status]) ) {
            $this->log('WARN', 'Estado en-ruta-pendiente no encontrado, usando on-hold');
            $target_status = 'on-hold';
        }

        $order->update_status(
            $target_status,
            sprintf( 'Entrega parcial/pendiente por conductor: %s', $payload['group']['name'] ?? 'Repartidor' )
        );

        $partial_note = sanitize_textarea_field( $_POST['note'] ?? '' );

        $order->update_meta_data( '_wdg_partial',      '1' );
        $order->update_meta_data( '_wdg_partial_date', date('Y-m-d') );
        $order->update_meta_data( '_wdg_partial_by',   $payload['group']['name'] ?? '' );

        // Agregar nota al pedido si se ingresó
        if ( ! empty($partial_note) ) {
            $note_text = sprintf(
                '⚠️ Entrega parcial por %s: %s',
                $payload['group']['name'] ?? 'Repartidor',
                $partial_note
            );
            $order->add_order_note( $note_text, false, false );
        } else {
            $order->add_order_note(
                sprintf( '⚠️ Entrega parcial por %s (sin nota)', $payload['group']['name'] ?? 'Repartidor' ),
                false, false
            );
        }

        $order->save();

        $this->log('OK', 'partial_order: estado cambiado', array(
            'order_id' => $order_id,
            'antes'    => $status_before,
            'ahora'    => $target_status,
        ));

        // Actualizar evento en tabla de análisis
        $plan_id = $payload['plan_id'] ?? '';
        if ( $plan_id ) {
            $this->update_event_status( $plan_id, $order_id, 'partial', array(
                'partial_note' => sanitize_textarea_field( $_POST['note']      ?? '' ),
                'recipient'    => sanitize_text_field(     $_POST['recipient'] ?? '' ),
            ));
        }

        wp_send_json_success( array(
            'order_id' => $order_id,
            'status'   => $order->get_status(),
        ));
    }

    // ── AJAX: conductor sincroniza progreso al servidor ───────────────────────
    // nopriv = sin login (el conductor no está autenticado)

    public function ajax_sync_progress() {
        $token    = sanitize_text_field( $_POST['token']    ?? '' );
        $done_raw = sanitize_text_field( $_POST['done']     ?? '{}' );

        if ( empty($token) ) { wp_send_json_error('Token requerido'); }

        $payload = get_option( 'wdg_route_' . $token );
        if ( empty($payload) || $payload['expiry'] < time() ) {
            wp_send_json_error('Token inválido o expirado');
        }

        $done = json_decode( stripslashes($done_raw), true );
        if ( ! is_array($done) ) { wp_send_json_error('Datos inválidos'); }

        // Guardar progreso en el token
        $payload['progress']    = $done;
        $payload['last_update'] = time();
        update_option( 'wdg_route_' . $token, $payload );

        // Guardar entrega en meta del pedido WooCommerce (persiste entre días)
        $orders = $payload['group']['orders'] ?? array();
        foreach ( $done as $idx => $is_done ) {
            if ( ! $is_done ) continue;
            $order_id = intval( $orders[ intval($idx) ]['id'] ?? 0 );
            if ( ! $order_id ) continue;
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            // Solo marcar si no estaba ya marcado
            if ( ! $order->get_meta('_wdg_delivered') ) {
                $order->update_meta_data( '_wdg_delivered',      '1' );
                $order->update_meta_data( '_wdg_delivered_date', date('Y-m-d') );
                $order->update_meta_data( '_wdg_delivered_by',   $payload['group']['name'] ?? '' );
                $order->save();
            }
        }

        // Actualizar resumen de progreso en el índice (evita re-leer tokens al listar)
        $total_orders = count( $payload['group']['orders'] ?? array() );
        $n_done       = count( array_filter($done) );
        $index        = get_option('wdg_plans_index', array());

        // Buscar el plan que contiene este token
        foreach ( $index as $pid => &$meta ) {
            $plan = get_option( $this->get_plan_key($pid) );
            if ( ! $plan ) continue;
            foreach ( ($plan['tokens'] ?? array()) as $gname => $t ) {
                if ( $t === $token ) {
                    // Actualizar progreso del grupo en el índice
                    if ( ! isset($meta['progress_by_group']) ) {
                        $meta['progress_by_group'] = array();
                    }
                    $meta['progress_by_group'][$gname] = $n_done;
                    // Recalcular total done
                    $meta['done'] = array_sum($meta['progress_by_group']);
                    $meta['pct']  = $meta['total'] > 0
                        ? round($meta['done'] / $meta['total'] * 100) : 0;
                    break 2;
                }
            }
        }
        update_option('wdg_plans_index', $index);

        wp_send_json_success( array('saved' => count($done), 'progress' => $payload['progress'] ?? array()) );
    }

    // ── AJAX: admin obtiene progreso de todos los tokens activos ──────────────

    public function ajax_get_progress() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $tokens_raw = sanitize_text_field( $_POST['tokens'] ?? '' );
        $tokens     = json_decode( stripslashes($tokens_raw), true );

        if ( empty($tokens) || ! is_array($tokens) ) {
            wp_send_json_error('Sin tokens');
        }

        $result = array();
        global $wpdb;
        foreach ( $tokens as $token ) {
            $token   = sanitize_text_field($token);
            $payload = get_option( 'wdg_route_' . $token );
            if ( empty($payload) ) continue;

            $orders   = $payload['group']['orders'] ?? [];
            $total    = count( $orders );
            $last_upd = isset($payload['last_update'])
                ? date( 'H:i', $payload['last_update'] )
                : null;

            // Recalcular progreso desde estado real WC para reflejar cambios manuales
            $done = [];
            if ( ! empty($orders) ) {
                $order_ids = array_map( 'intval', array_column( $orders, 'id' ) );
                $ids_in    = implode( ',', $order_ids );
                $statuses  = $wpdb->get_results(
                    "SELECT ID, post_status FROM {$wpdb->posts}
                     WHERE ID IN ({$ids_in}) AND post_type = 'shop_order'"
                );
                $status_map = [];
                foreach ( $statuses as $row ) {
                    $status_map[ intval($row->ID) ] = $row->post_status;
                }
                foreach ( $orders as $idx => $o ) {
                    $oid = intval($o['id']);
                    $st  = $status_map[$oid] ?? '';
                    // Completado = entregado; en-ruta-pendiente = parcial (también cuenta como hecho)
                    $done[$idx] = in_array( $st, ['wc-completed', 'wc-en-ruta-pendiente'] );
                }
            }
            $n_done = count( array_filter($done) );

            $result[$token] = array(
                'name'        => $payload['group']['name']  ?? 'Repartidor',
                'total'       => $total,
                'done'        => $n_done,
                'pct'         => $total > 0 ? round($n_done / $total * 100) : 0,
                'progress'    => $done,
                'last_update' => $last_upd,
                'orders'      => $orders,
            );
        }

        wp_send_json_success($result);
    }



    // ══════════════════════════════════════════════════════════════════════════
    // META BOX EN PEDIDO: Coordenadas de entrega
    // ══════════════════════════════════════════════════════════════════════════

    public function register_order_meta_box() {
        // Soportar tanto el editor clásico como HPOS (High-Performance Order Storage)
        $screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
        foreach ( $screens as $screen ) {
            add_meta_box(
                'wdg_coordinates',
                '📍 Coordenadas de entrega',
                array( $this, 'render_order_meta_box' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_order_meta_box( $post_or_order ) {
        // Compatibilidad con HPOS y editor clásico
        if ( $post_or_order instanceof WC_Order ) {
            $order = $post_or_order;
        } else {
            $order = wc_get_order( $post_or_order->ID );
        }
        if ( ! $order ) return;

        $lat      = $order->get_meta('_billing_address_lat');
        $lng      = $order->get_meta('_billing_address_lng');
        $s_lat    = $order->get_meta('_shipping_address_lat');
        $s_lng    = $order->get_meta('_shipping_address_lng');
        $delivered     = $order->get_meta('_wdg_delivered');
        $delivered_by  = $order->get_meta('_wdg_delivered_by');
        $delivered_date= $order->get_meta('_wdg_delivered_date');

        $wdg_route     = $order->get_meta('_wdg_route');
        $wdg_plan_name = $order->get_meta('_wdg_plan_name');
        $wdg_stop_pos  = $order->get_meta('_wdg_stop_position');

        $api_key = $this->get_api_key();
        ?>
        <div style="font-size:12px">

        <?php if ( $wdg_route || $wdg_plan_name ) : ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px;margin:0 0 10px;color:#1e3a8a">
                <?php if ( $wdg_route ) : ?>
                <p style="margin:0 0 2px;font-weight:600">📦 <?php echo esc_html($wdg_route); ?>
                    <?php if ( $wdg_stop_pos ) : ?>&nbsp;·&nbsp; Parada #<?php echo esc_html($wdg_stop_pos); ?><?php endif; ?>
                </p>
                <?php endif; ?>
                <?php if ( $wdg_plan_name ) : ?>
                <p style="margin:0;font-size:11px">📋 <?php echo esc_html($wdg_plan_name); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $lat && $lng ) : ?>
            <p style="margin:0 0 6px"><strong>Facturación</strong></p>
            <p style="margin:0 0 2px;font-family:monospace;color:#374151">
                <?php echo esc_html(round(floatval($lat), 6)); ?>,
                <?php echo esc_html(round(floatval($lng), 6)); ?>
            </p>
            <p style="margin:0 0 10px">
                <a href="https://www.google.com/maps?q=<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>"
                   target="_blank" style="color:#2271b1">📍 Ver en Google Maps</a>
            </p>

            <?php if ( $api_key ) : ?>
            <div style="margin-bottom:10px;border-radius:6px;overflow:hidden;border:1px solid #ddd">
                <img src="https://maps.googleapis.com/maps/api/staticmap?center=<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>&zoom=15&size=270x160&markers=color:red%7C<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>&key=<?php echo esc_attr($api_key); ?>"
                     width="100%" alt="Mapa" style="display:block">
            </div>
            <?php endif; ?>

        <?php elseif ( $s_lat && $s_lng ) : ?>
            <p style="margin:0 0 6px"><strong>Envío</strong></p>
            <p style="margin:0 0 2px;font-family:monospace;color:#374151">
                <?php echo esc_html(round(floatval($s_lat), 6)); ?>,
                <?php echo esc_html(round(floatval($s_lng), 6)); ?>
            </p>
            <p style="margin:0 0 10px">
                <a href="https://www.google.com/maps?q=<?php echo esc_attr($s_lat); ?>,<?php echo esc_attr($s_lng); ?>"
                   target="_blank" style="color:#2271b1">📍 Ver en Google Maps</a>
            </p>
        <?php else : ?>
            <p style="color:#ef4444;margin:0 0 8px">
                ⚠️ Sin coordenadas geocodificadas
            </p>
            <p style="color:#6b7280;margin:0 0 10px;font-size:11px">
                Usa <strong>WooCommerce → Geocodificador</strong> para obtener las coordenadas de este pedido.
            </p>
        <?php endif; ?>

        <?php if ( $delivered ) : ?>
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:8px 0">
            <p style="margin:0 0 4px"><strong>Estado de reparto</strong></p>
            <p style="margin:0;color:#15803d;font-weight:600">✅ Entregado</p>
            <?php if ( $delivered_date ) : ?>
            <p style="margin:2px 0 0;color:#6b7280;font-size:11px">
                Fecha: <?php echo esc_html($delivered_date); ?>
                <?php if ( $delivered_by ) : ?>
                &nbsp;·&nbsp; Repartidor: <?php echo esc_html($delivered_by); ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        // Campos editables para corregir coordenadas manualmente
        wp_nonce_field('wdg_save_coords_' . $order->get_id(), 'wdg_coords_nonce');
        ?>
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:10px 0 8px">
        <p style="margin:0 0 4px;font-weight:600">Editar coordenadas</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
            <div>
                <label style="font-size:11px;color:#6b7280">Latitud</label>
                <input type="text" name="wdg_lat" value="<?php echo esc_attr($lat ?: $s_lat); ?>"
                       style="width:100%;font-family:monospace;font-size:12px">
            </div>
            <div>
                <label style="font-size:11px;color:#6b7280">Longitud</label>
                <input type="text" name="wdg_lng" value="<?php echo esc_attr($lng ?: $s_lng); ?>"
                       style="width:100%;font-family:monospace;font-size:12px">
            </div>
        </div>
        <p style="margin:4px 0 0;font-size:10px;color:#9ca3af">Guarda el pedido para actualizar las coordenadas</p>
        </div>
        <?php
    }

    public function save_order_meta_box( $order_id ) {
        if ( ! isset($_POST['wdg_coords_nonce']) ) return;
        if ( ! wp_verify_nonce($_POST['wdg_coords_nonce'], 'wdg_save_coords_' . $order_id) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $lat = sanitize_text_field( $_POST['wdg_lat'] ?? '' );
        $lng = sanitize_text_field( $_POST['wdg_lng'] ?? '' );

        if ( is_numeric($lat) && is_numeric($lng) ) {
            $order->update_meta_data('_billing_address_lat',  $lat);
            $order->update_meta_data('_billing_address_lng',  $lng);
            $order->update_meta_data('_shipping_address_lat', $lat);
            $order->update_meta_data('_shipping_address_lng', $lng);
            $order->save();
        }
    }



    // ══════════════════════════════════════════════════════════════════════════
    // ANÁLISIS DE EVENTOS
    // ══════════════════════════════════════════════════════════════════════════

    public function ajax_query_events() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        global $wpdb;
        $table = $this->events_table();

        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to']   ?? '' );
        $driver_id = sanitize_text_field( $_POST['driver_id'] ?? '' );
        $status    = sanitize_text_field( $_POST['status']    ?? '' );
        $product   = sanitize_text_field( $_POST['product']   ?? '' );
        $order_id  = intval( $_POST['order_id'] ?? 0 );

        // Construir WHERE
        $where  = array('1=1');
        $params = array();

        if ( $date_from ) { $where[] = 'route_date >= %s'; $params[] = $date_from; }
        if ( $date_to   ) { $where[] = 'route_date <= %s'; $params[] = $date_to; }
        if ( $driver_id ) { $where[] = 'driver_id = %s';   $params[] = $driver_id; }
        if ( $status    ) { $where[] = 'status = %s';      $params[] = $status; }
        if ( $product   ) { $where[] = 'products LIKE %s'; $params[] = '%' . $wpdb->esc_like($product) . '%'; }
        if ( $order_id  ) { $where[] = 'order_id = %d';   $params[] = $order_id; }

        $where_sql = implode( ' AND ', $where );

        // Resultados (máx 500 filas)
        if ( empty($params) ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY route_date DESC, id DESC LIMIT 500" );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY route_date DESC, id DESC LIMIT 500",
                ...$params
            ));
        }

        // KPIs
        $kpis = array(
            'total'       => 0,
            'delivered'   => 0,
            'partial'     => 0,
            'not_visited' => 0,
            'assigned'    => 0,
        );
        foreach ( $rows as $row ) {
            $kpis['total']++;
            if ( isset($kpis[$row->status]) ) $kpis[$row->status]++;
        }
        $kpis['rate'] = $kpis['total'] > 0
            ? round($kpis['delivered'] / $kpis['total'] * 100, 1) : 0;

        wp_send_json_success( array(
            'rows' => $rows,
            'kpis' => $kpis,
        ));
    }


    // ══════════════════════════════════════════════════════════════════════════
    // ACTUALIZAR ESTADO DE EVENTO DESDE WOOCOMMERCE
    // ══════════════════════════════════════════════════════════════════════════

    public function ajax_refresh_event_status() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $event_id = intval( $_POST['event_id'] ?? 0 );
        $order_id = intval( $_POST['order_id'] ?? 0 );

        if ( ! $event_id || ! $order_id ) { wp_send_json_error('Datos inválidos'); }

        $order = wc_get_order( $order_id );
        if ( ! $order ) { wp_send_json_error('Pedido no encontrado'); }

        $wc_status = $order->get_status(); // sin prefijo 'wc-'

        // Mapear estado WC → estado wdg_events
        $map = array(
            'completed'        => 'delivered',
            'en-ruta-pendiente'=> 'partial',
            'en-ruta'          => 'assigned',
        );

        if ( ! isset($map[$wc_status]) ) {
            // Estado no mapeado — devolver alerta con el nombre real
            $labels = wc_get_order_statuses();
            $label  = $labels['wc-'.$wc_status] ?? $wc_status;
            wp_send_json_success( array(
                'updated' => false,
                'alert'   => 'El pedido está en estado: ' . $label,
            ));
            return;
        }

        $new_status = $map[$wc_status];

        global $wpdb;
        $table = $this->events_table();
        $wpdb->update(
            $table,
            array( 'status' => $new_status ),
            array( 'id' => $event_id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'updated'    => true,
            'new_status' => $new_status,
        ));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CONFIGURACIÓN — API KEY
    // ══════════════════════════════════════════════════════════════════════════

    public function ajax_save_config() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) { wp_send_json_error('Sin permiso'); }
        $send_email = isset($_POST['send_photo_email']) ? '1' : '0';
        update_option( self::OPT_SEND_EMAIL, $send_email );
        wp_send_json_success( array( 'send_photo_email' => $send_email ) );
    }

    public function ajax_save_api_key() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) { wp_send_json_error('Sin permiso'); }
        $key = sanitize_text_field( $_POST['api_key'] ?? '' );
        update_option( self::OPT_API_KEY, $key );
        wp_send_json_success( array( 'api_key' => $key ) );
    }

    public function ajax_test_api_key() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) { wp_send_json_error('Sin permiso'); }
        $key = sanitize_text_field( $_POST['api_key'] ?? '' );
        if ( empty($key) ) { wp_send_json_error('Ingresa una API Key primero'); }

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query( array(
            'address' => 'Santiago, Chile',
            'key'     => $key,
        ) );
        $response = wp_remote_get( $url, array( 'timeout' => 8 ) );
        if ( is_wp_error($response) ) { wp_send_json_error('Error de conexión: ' . $response->get_error_message()); }

        $body = json_decode( wp_remote_retrieve_body($response), true );
        $status = $body['status'] ?? 'UNKNOWN';
        if ( $status === 'OK' ) {
            wp_send_json_success( array( 'message' => '✅ API Key válida y operativa' ) );
        } else {
            $msg = $body['error_message'] ?? $status;
            wp_send_json_error( '❌ ' . $msg );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GESTIÓN DE VEHÍCULOS
    // ══════════════════════════════════════════════════════════════════════════

    private function get_vehicles_key() { return 'wdg_vehicles'; }

    public function ajax_get_vehicles() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        $vehicles = get_option( $this->get_vehicles_key(), array() );
        wp_send_json_success( array_values($vehicles) );
    }

    public function ajax_save_vehicle() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        $id        = sanitize_text_field( $_POST['id']         ?? '' );
        $patente   = strtoupper( sanitize_text_field( $_POST['patente']   ?? '' ) );
        $tipo      = sanitize_text_field( $_POST['tipo']       ?? 'Auto' );
        $modelo    = sanitize_text_field( $_POST['modelo']     ?? '' );
        $capacidad = sanitize_text_field( $_POST['capacidad']  ?? '' );
        $activo    = rest_sanitize_boolean( $_POST['activo']   ?? true );

        if ( empty($patente) ) { wp_send_json_error('La patente es obligatoria'); }

        $vehicles = get_option( $this->get_vehicles_key(), array() );
        if ( empty($id) ) {
            $id = 'veh_' . substr( md5( uniqid('wdg_veh', true) ), 0, 8 );
        }
        $vehicles[$id] = array(
            'id'         => $id,
            'patente'    => $patente,
            'tipo'       => $tipo,
            'modelo'     => $modelo,
            'capacidad'  => $capacidad,
            'activo'     => (bool) $activo,
            'created_at' => isset($vehicles[$id]['created_at']) ? $vehicles[$id]['created_at'] : time(),
        );
        update_option( $this->get_vehicles_key(), $vehicles );
        wp_send_json_success( $vehicles[$id] );
    }

    public function ajax_delete_vehicle() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        $id       = sanitize_text_field( $_POST['id'] ?? '' );
        $vehicles = get_option( $this->get_vehicles_key(), array() );
        if ( isset($vehicles[$id]) ) {
            unset( $vehicles[$id] );
            update_option( $this->get_vehicles_key(), $vehicles );
        }
        wp_send_json_success( array('deleted' => $id) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GESTIÓN DE REPARTIDORES
    // ══════════════════════════════════════════════════════════════════════════

    private function get_drivers_key() { return 'wdg_drivers'; }

    public function ajax_get_drivers() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        $drivers = get_option( $this->get_drivers_key(), array() );
        wp_send_json_success( array_values($drivers) );
    }

    public function ajax_save_driver() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $id              = sanitize_text_field( $_POST['id']               ?? '' );
        $nombre          = sanitize_text_field( $_POST['nombre']           ?? '' );
        $alias           = sanitize_text_field( $_POST['alias']            ?? '' );
        $rut             = sanitize_text_field( $_POST['rut']              ?? '' );
        $telefono        = sanitize_text_field( $_POST['telefono']         ?? '' );
        $email           = sanitize_email(      $_POST['email']            ?? '' );
        $licencia_tipo   = sanitize_text_field( $_POST['licencia_tipo']    ?? '' );
        $licencia_vence  = sanitize_text_field( $_POST['licencia_vence']   ?? '' );
        $activo          = rest_sanitize_boolean( $_POST['activo']         ?? true );

        if ( empty($nombre) ) { wp_send_json_error('Nombre requerido'); }

        $drivers = get_option( $this->get_drivers_key(), array() );

        if ( empty($id) ) {
            $id = 'drv_' . substr( md5( uniqid('wdg_drv', true) ), 0, 8 );
        }

        $drivers[$id] = array(
            'id'             => $id,
            'nombre'         => $nombre,
            'alias'          => $alias ?: $nombre,
            'rut'            => $rut,
            'telefono'       => $telefono,
            'email'          => $email,
            'licencia_tipo'  => $licencia_tipo,
            'licencia_vence' => $licencia_vence,
            'activo'         => (bool) $activo,
            'created_at'     => isset($drivers[$id]['created_at']) ? $drivers[$id]['created_at'] : time(),
        );

        update_option( $this->get_drivers_key(), $drivers );
        wp_send_json_success( $drivers[$id] );
    }

    public function ajax_delete_driver() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );
        $id      = sanitize_text_field( $_POST['id'] ?? '' );
        $drivers = get_option( $this->get_drivers_key(), array() );
        if ( isset($drivers[$id]) ) {
            unset( $drivers[$id] );
            update_option( $this->get_drivers_key(), $drivers );
        }
        wp_send_json_success( array('deleted' => $id) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GESTIÓN DE PLANIFICACIONES
    // ══════════════════════════════════════════════════════════════════════════

    private function get_plan_key( $plan_id ) {
        return 'wdg_plan_' . sanitize_key( $plan_id );
    }

    // ── Guardar planificación ─────────────────────────────────────────────────
    public function ajax_save_plan() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id   = sanitize_text_field( $_POST['plan_id'] ?? '' );
        $plan_name = sanitize_text_field( $_POST['plan_name'] ?? '' );
        $config    = json_decode( stripslashes( $_POST['config']   ?? '{}' ), true );
        $groups    = json_decode( stripslashes( $_POST['groups']   ?? '[]' ), true );
        $tokens    = json_decode( stripslashes( $_POST['tokens']   ?? '{}' ), true );

        if ( empty($plan_name) ) { wp_send_json_error('Nombre requerido'); }
        if ( empty($groups)    ) { wp_send_json_error('Sin grupos para guardar'); }

        // Generar ID si es nuevo
        if ( empty($plan_id) ) {
            $plan_id = 'p' . substr( md5( uniqid('wdg', true) ), 0, 8 );
        }

        $plan = array(
            'id'         => $plan_id,
            'name'       => $plan_name,
            'created_at' => time(),
            'config'     => $config,
            'groups'     => $groups,
            'tokens'     => $tokens,
            'depot'      => $this->get_depot(),
        );

        update_option( $this->get_plan_key($plan_id), $plan, false );

        // Actualizar índice de planes
        $index   = get_option('wdg_plans_index', array());
        $index[$plan_id] = array(
            'id'         => $plan_id,
            'name'       => $plan_name,
            'created_at' => $plan['created_at'],
            'groups'     => count($groups),
            'total'      => array_sum( array_column($groups, 'count') ),
            'done'       => $index[$plan_id]['done'] ?? 0,
            'pct'        => $index[$plan_id]['pct']  ?? 0,
            'config'     => $config,
            'progress_by_group' => $index[$plan_id]['progress_by_group'] ?? array(),
        );
        update_option('wdg_plans_index', $index);

        // Etiquetar los pedidos del plan con los metas de ruta (sin regenerar links)
        foreach ( $groups as $group ) {
            $this->write_order_route_metas( $group, $plan_id, $plan_name );
        }

        wp_send_json_success( array('plan_id' => $plan_id, 'name' => $plan_name) );
    }

    // ── Listar planificaciones ─────────────────────────────────────────────────
    public function ajax_get_plans() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        // ── Una sola consulta a la BD — el índice ya tiene todo lo necesario ──
        $index = get_option('wdg_plans_index', array());

        // Ordenar por fecha descendente
        uasort($index, function($a, $b) {
            return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
        });

        $result = array();
        foreach ( $index as $pid => $meta ) {
            // Verificar que el plan existe (limpieza lazy)
            // Solo si hay menos de 50 planes para no penalizar en bulk
            if ( count($index) < 50 && ! get_option( $this->get_plan_key($pid) ) ) {
                continue;
            }
            $meta['date_label'] = isset($meta['created_at'])
                ? date('d/m/Y H:i', $meta['created_at']) : '';
            $result[] = $meta;
        }

        wp_send_json_success( $result );
    }

    // ── Cargar planificación completa ─────────────────────────────────────────
    public function ajax_load_plan() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        if ( empty($plan_id) ) { wp_send_json_error('ID requerido'); }

        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( empty($plan) ) { wp_send_json_error('Plan no encontrado'); }

        // Obtener progreso actualizado de cada token.
        // activeTokens en JS se guarda indexado por número (0,1,2...).
        // Fallback 1: buscar por nombre de grupo.
        // Fallback 2: escanear wdg_route_* por plan_id + group name
        //             (cubre el caso donde el token se generó después del guardado
        //              y hubo una race condition en el auto-save de JS).

        global $wpdb;
        $all_route_options = null; // lazy-load si se necesita

        foreach ( $plan['groups'] as $gidx => &$group ) {
            $gname = $group['name'];
            $token = $plan['tokens'][$gidx] ?? $plan['tokens'][$gname] ?? '';

            // Fallback: escanear tokens en DB para este plan y grupo
            if ( empty($token) ) {
                if ( $all_route_options === null ) {
                    $all_route_options = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                            'wdg_route_%'
                        )
                    );
                }
                foreach ( $all_route_options as $row ) {
                    $data = maybe_unserialize( $row->option_value );
                    if ( ! is_array($data) ) continue;
                    if ( ( $data['plan_id'] ?? '' ) !== $plan['id'] ) continue;
                    if ( ( $data['group']['name'] ?? '' ) !== $gname ) continue;
                    if ( isset($data['expiry']) && $data['expiry'] < time() ) continue;
                    $token = $data['token'] ?? '';
                    // Persistir el token en el plan para no volver a escanear
                    $plan['tokens'][$gidx] = $token;
                    update_option( $this->get_plan_key($plan['id']), $plan, false );
                    break;
                }
            }

            $progress = array();
            if ( $token ) {
                $token_data = get_option( 'wdg_route_' . $token );
                $progress   = $token_data['progress'] ?? array();
            }
            $group['progress'] = $progress;
            $group['token']    = $token;
        }

        wp_send_json_success( $plan );
    }

    // ── Eliminar planificación ────────────────────────────────────────────────
    public function ajax_delete_plan() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        if ( empty($plan_id) ) { wp_send_json_error('ID requerido'); }

        // Limpiar los metas de ruta de los pedidos antes de borrar el plan
        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( is_array($plan) ) {
            $this->clear_plan_order_metas( $plan, $plan_id );
        }

        delete_option( $this->get_plan_key($plan_id) );

        $index = get_option('wdg_plans_index', array());
        unset($index[$plan_id]);
        update_option('wdg_plans_index', $index);

        wp_send_json_success( array('deleted' => $plan_id) );
    }

    // ── Detectar pedidos nuevos no asignados a un plan ────────────────────────
    // Trae todos los pedidos del estado indicado (seleccionable; por defecto el
    // del plan) que aún no estén asignados a ningún grupo del plan.
    public function ajax_new_orders() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        if ( empty($plan_id) ) { wp_send_json_error('ID de plan requerido'); }

        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( empty($plan) ) { wp_send_json_error('Plan no encontrado'); }

        $config = $plan['config'] ?? array();
        // Estado seleccionado en la UI; si no viene, usar el del plan
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        if ( $status === '' ) $status = $config['status'] ?? 'any';

        // Sin rango de fechas: todos los pedidos del estado que no estén ya asignados
        $data = $this->fetch_orders( '', '', $status, '0' );

        // IDs ya asignados en cualquier grupo del plan
        $assigned = array();
        foreach ( ($plan['groups'] ?? array()) as $g ) {
            foreach ( ($g['orders'] ?? array()) as $o ) {
                $assigned[ intval($o['id']) ] = true;
            }
        }

        $new_orders = array();
        foreach ( $data['orders'] as $o ) {
            if ( ! isset($assigned[ intval($o['id']) ]) ) {
                $new_orders[] = $o;
            }
        }

        wp_send_json_success( array(
            'orders'  => $new_orders,
            'count'   => count($new_orders),
            'skipped' => $data['skipped'],
        ) );
    }

    // ── Añadir pedidos nuevos a un plan y reoptimizar las rutas afectadas ──────
    public function ajax_append_orders() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        $incoming = json_decode( stripslashes($_POST['orders'] ?? '[]'), true );

        if ( empty($plan_id) ) { wp_send_json_error('ID de plan requerido'); }
        if ( empty($incoming) || ! is_array($incoming) ) { wp_send_json_error('Sin pedidos para añadir'); }

        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( empty($plan) ) { wp_send_json_error('Plan no encontrado'); }

        $depot = $plan['depot'] ?? $this->get_depot();

        // Agrupar pedidos nuevos por ruta destino (group_idx)
        $by_group = array();
        foreach ( $incoming as $o ) {
            $gi = intval( $o['group_idx'] ?? -1 );
            if ( ! isset($plan['groups'][$gi]) ) continue;
            unset( $o['group_idx'] );
            $by_group[$gi][] = $o;
        }
        if ( empty($by_group) ) { wp_send_json_error('Ningún pedido se asignó a una ruta válida'); }

        $added_total = 0;
        $affected    = array();

        foreach ( $by_group as $gi => $adds ) {
            $group    = &$plan['groups'][$gi];
            $existing = $group['orders'] ?? array();

            // Evitar duplicados (pedido ya presente en la ruta)
            $present = array();
            foreach ( $existing as $o ) $present[ intval($o['id']) ] = true;
            $to_add = array();
            foreach ( $adds as $a ) {
                if ( ! isset($present[ intval($a['id']) ]) ) $to_add[] = $a;
            }
            if ( empty($to_add) ) continue;

            // Estado de entrega actual (id → progreso) para conservarlo tras reordenar
            $token    = $plan['tokens'][$gi] ?? $plan['tokens'][$group['name']] ?? '';
            $id_state = $this->group_delivery_state( $existing, $token );

            // Repartidor / vehículo de la ruta: el token es la fuente fiable
            $tok_data    = $token ? get_option( 'wdg_route_' . $token ) : null;
            $tok_group   = is_array($tok_data) ? ($tok_data['group'] ?? array()) : array();
            $driver_id   = $group['driver_id']   ?? $tok_group['driver_id']   ?? '';
            $driver_name = $group['driver_name'] ?? $tok_group['driver_name'] ?? '';
            $vehicle     = $tok_group['vehicle'] ?? $group['vehicle'] ?? '';

            // Lista completa deseada: existentes + nuevos (los nuevos van como pendientes)
            $full_orders = array_merge( $existing, $to_add );

            // Reordenar (entregados fijos al frente), recalcular métricas y refrescar el token
            $this->rebuild_group( $group, $token, $full_orders, $id_state, $depot );

            // Registrar eventos 'assigned' para los pedidos nuevos
            $this->insert_assigned_events( array(
                'plan_id'   => $plan_id,
                'plan_name' => $plan['name'],
                'group'     => array(
                    'name'        => $group['name'],
                    'driver_id'   => $driver_id,
                    'driver_name' => $driver_name,
                    'vehicle'     => $vehicle,
                    'orders'      => $to_add,
                ),
            ) );

            $added_total      += count($to_add);
            $affected[ $gi ]   = count($to_add);
        }
        unset( $group );

        if ( $added_total === 0 ) { wp_send_json_error('Los pedidos seleccionados ya estaban en sus rutas'); }

        // Persistir el plan
        update_option( $this->get_plan_key($plan_id), $plan, false );

        // Actualizar el índice (total y porcentaje)
        $index = get_option('wdg_plans_index', array());
        if ( isset($index[$plan_id]) ) {
            $index[$plan_id]['total'] = array_sum( array_column($plan['groups'], 'count') );
            $done = $index[$plan_id]['done'] ?? 0;
            $index[$plan_id]['pct'] = $index[$plan_id]['total'] > 0
                ? round( $done / $index[$plan_id]['total'] * 100 ) : 0;
            update_option('wdg_plans_index', $index);
        }

        $this->log('OK', 'append_orders: pedidos añadidos', array(
            'plan_id'  => $plan_id,
            'added'    => $added_total,
            'affected' => $affected,
        ));

        wp_send_json_success( array(
            'added'    => $added_total,
            'affected' => $affected,
            'groups'   => $plan['groups'],
        ) );
    }

    // ── Estado de entrega de una ruta: id de pedido → progreso ────────────────
    // El progreso del token está indexado por posición; lo mapeamos por id para
    // que sobreviva a los reordenamientos.
    private function group_delivery_state( $orders, $token ) {
        $progress = array();
        if ( $token ) {
            $token_data = get_option( 'wdg_route_' . $token );
            if ( is_array($token_data) ) $progress = $token_data['progress'] ?? array();
        }
        $state = array();
        foreach ( $orders as $i => $ord ) {
            if ( isset($progress[$i]) ) $state[ intval($ord['id']) ] = $progress[$i];
        }
        return $state;
    }

    // ── Reconstruir una ruta: reordena pendientes, fija entregados al frente,
    //    recalcula métricas y refresca el token in-place (mismo enlace) ─────────
    private function rebuild_group( &$group, $token, $full_orders, $id_state, $depot ) {
        // Separar entregados (fijos, en su orden actual) de pendientes (reoptimizables)
        $delivered = array();
        $pending   = array();
        foreach ( $full_orders as $ord ) {
            $oid = intval( $ord['id'] );
            if ( isset($id_state[$oid]) && $id_state[$oid] === true ) {
                $delivered[] = $ord;
            } else {
                $pending[] = $ord;
            }
        }

        // Reoptimizar pendientes anclando a la última entrega (o a la bodega)
        $anchor = null;
        if ( ! empty($delivered) ) {
            $last_done = end( $delivered );
            $anchor = array( 'lat' => $last_done['lat'], 'lng' => $last_done['lng'] );
        }
        $pending = $this->tsp_optimize( $pending, $depot, $anchor );

        $ordered = array_merge( $delivered, $pending );

        // Métricas + centro
        $metrics = $this->route_metrics( $ordered, $depot );
        $clat = 0; $clng = 0; $cnt = count($ordered);
        foreach ( $ordered as $o ) { $clat += $o['lat']; $clng += $o['lng']; }
        if ( $cnt > 0 ) { $clat /= $cnt; $clng /= $cnt; }

        $group['orders']            = $ordered;
        $group['count']             = $cnt;
        $group['route_km']          = $metrics['route_km'];
        $group['depot_to_first_km'] = $metrics['depot_to_first_km'];
        $group['last_to_depot_km']  = $metrics['last_to_depot_km'];
        $group['center']            = array( 'lat' => $clat, 'lng' => $clng );

        // Refrescar el token del conductor (mismo enlace), reindexando el progreso
        if ( $token ) {
            $token_data = get_option( 'wdg_route_' . $token );
            if ( is_array($token_data) ) {
                $new_progress = array();
                foreach ( $ordered as $ni => $ord ) {
                    $oid = intval( $ord['id'] );
                    if ( isset($id_state[$oid]) ) $new_progress[$ni] = $id_state[$oid];
                }
                $token_data['group']['orders']   = $ordered;
                $token_data['group']['count']    = $cnt;
                $token_data['group']['route_km'] = $metrics['route_km'];
                $token_data['progress']          = $new_progress;
                $token_data['expiry']            = time() + ( 72 * 3600 ); // renovar 72h
                update_option( 'wdg_route_' . $token, $token_data, false );
            }
        }
    }

    // ── Reasignar pedidos existentes entre rutas (masivo) ─────────────────────
    public function ajax_reassign_orders() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id     = sanitize_text_field( $_POST['plan_id'] ?? '' );
        $assignments = json_decode( stripslashes($_POST['assignments'] ?? '[]'), true ); // [{id, group_idx}]

        if ( empty($plan_id) ) { wp_send_json_error('ID de plan requerido'); }
        if ( empty($assignments) || ! is_array($assignments) ) { wp_send_json_error('Sin pedidos para reasignar'); }

        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( empty($plan) ) { wp_send_json_error('Plan no encontrado'); }

        $depot = $plan['depot'] ?? $this->get_depot();

        // Destino solicitado: id de pedido → índice de ruta destino
        $target = array();
        foreach ( $assignments as $a ) {
            $oid = intval( $a['id'] ?? 0 );
            $gi  = intval( $a['group_idx'] ?? -1 );
            if ( $oid && isset($plan['groups'][$gi]) ) $target[$oid] = $gi;
        }
        if ( empty($target) ) { wp_send_json_error('Asignaciones inválidas'); }

        // Inventario actual: objeto, ruta de origen, token y estado de entrega por id
        $order_obj    = array();  // id → array del pedido
        $order_home   = array();  // id → índice de ruta actual
        $group_tokens = array();  // gi → token
        $id_state     = array();  // id → progreso (true / 'visited')
        foreach ( $plan['groups'] as $gi => $g ) {
            $token = $plan['tokens'][$gi] ?? $plan['tokens'][$g['name']] ?? '';
            $group_tokens[$gi] = $token;
            $state = $this->group_delivery_state( $g['orders'] ?? array(), $token );
            foreach ( ($g['orders'] ?? array()) as $o ) {
                $oid = intval( $o['id'] );
                $order_obj[$oid]  = $o;
                $order_home[$oid] = $gi;
                if ( isset($state[$oid]) ) $id_state[$oid] = $state[$oid];
            }
        }

        // Validar movimientos: existe, cambia de ruta y no está entregado
        $moves   = array();   // id → ruta destino
        $blocked = 0;
        foreach ( $target as $oid => $to_gi ) {
            if ( ! isset($order_home[$oid]) ) continue;          // no está en el plan
            if ( $order_home[$oid] === $to_gi ) continue;        // ya está en esa ruta
            if ( isset($id_state[$oid]) && $id_state[$oid] === true ) { $blocked++; continue; } // entregado
            $moves[$oid] = $to_gi;
        }
        if ( empty($moves) ) {
            wp_send_json_error( $blocked > 0
                ? 'Los pedidos seleccionados ya están entregados y no se pueden mover'
                : 'Nada que mover (ya estaban en su ruta destino)' );
        }

        // Los pedidos movidos arrancan sin estado en su nueva ruta
        foreach ( $moves as $oid => $to_gi ) { unset( $id_state[$oid] ); }

        // Rutas afectadas (origen y destino)
        $affected_idx = array();
        foreach ( $moves as $oid => $to_gi ) {
            $affected_idx[ $order_home[$oid] ] = true;
            $affected_idx[ $to_gi ]            = true;
        }

        // Construir el nuevo conjunto de pedidos por ruta afectada
        $new_sets = array();
        foreach ( array_keys($affected_idx) as $gi ) {
            $kept = array();
            foreach ( $plan['groups'][$gi]['orders'] as $o ) {
                if ( isset($moves[ intval($o['id']) ]) ) continue; // sale de esta ruta
                $kept[] = $o;
            }
            $new_sets[$gi] = $kept;
        }
        foreach ( $moves as $oid => $to_gi ) {
            $new_sets[$to_gi][] = $order_obj[$oid];
        }

        // Reconstruir cada ruta afectada (reoptimiza + refresca token)
        foreach ( $new_sets as $gi => $set ) {
            $group = &$plan['groups'][$gi];
            $this->rebuild_group( $group, $group_tokens[$gi], $set, $id_state, $depot );
            unset( $group );
        }

        update_option( $this->get_plan_key($plan_id), $plan, false );

        $this->log('OK', 'reassign_orders: pedidos reasignados', array(
            'plan_id' => $plan_id,
            'moved'   => count($moves),
            'blocked' => $blocked,
        ));

        wp_send_json_success( array(
            'moved'   => count($moves),
            'blocked' => $blocked,
            'groups'  => $plan['groups'],
        ) );
    }

    // ── Quitar un pedido de su ruta (lo deja sin asignar y limpia sus metas) ───
    public function ajax_remove_order() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id  = sanitize_text_field( $_POST['plan_id'] ?? '' );
        $order_id = intval( $_POST['order_id'] ?? 0 );

        if ( empty($plan_id) ) { wp_send_json_error('ID de plan requerido'); }
        if ( ! $order_id )     { wp_send_json_error('ID de pedido requerido'); }

        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( empty($plan) ) { wp_send_json_error('Plan no encontrado'); }

        $depot = $plan['depot'] ?? $this->get_depot();

        // Localizar la ruta (grupo) del pedido y su token
        $home_gi = null;
        $token   = '';
        foreach ( $plan['groups'] as $gi => $g ) {
            foreach ( ($g['orders'] ?? array()) as $o ) {
                if ( intval($o['id']) === $order_id ) {
                    $home_gi = $gi;
                    $token   = $plan['tokens'][$gi] ?? $plan['tokens'][$g['name']] ?? '';
                    break 2;
                }
            }
        }
        if ( $home_gi === null ) { wp_send_json_error('El pedido no está en este plan'); }

        // Estado de entrega de la ruta de origen (los entregados que se quedan
        // permanecen fijos al reoptimizar). Se permite quitar incluso entregados.
        $state    = $this->group_delivery_state( $plan['groups'][$home_gi]['orders'] ?? array(), $token );
        $id_state = $state;
        unset( $id_state[$order_id] );

        // Quitar el pedido y reconstruir la ruta (reoptimiza + refresca token)
        $kept = array();
        foreach ( $plan['groups'][$home_gi]['orders'] as $o ) {
            if ( intval($o['id']) === $order_id ) continue;
            $kept[] = $o;
        }
        $group = &$plan['groups'][$home_gi];
        $this->rebuild_group( $group, $token, $kept, $id_state, $depot );
        unset( $group );

        update_option( $this->get_plan_key($plan_id), $plan, false );

        // Limpiar los metas de ruta del pedido (queda sin asignar)
        $this->clear_order_route_metas( $order_id );

        $this->log('OK', 'remove_order: pedido quitado de la ruta', array(
            'plan_id'  => $plan_id,
            'order_id' => $order_id,
            'group'    => $home_gi,
        ));

        wp_send_json_success( array(
            'order_id' => $order_id,
            'groups'   => $plan['groups'],
        ) );
    }

    // ── Quitar varios pedidos de sus rutas (selección por área) ───────────────
    public function ajax_remove_orders() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $plan_id = sanitize_text_field( $_POST['plan_id'] ?? '' );
        $ids_raw = json_decode( stripslashes( $_POST['order_ids'] ?? '[]' ), true );

        if ( empty($plan_id) )                        { wp_send_json_error('ID de plan requerido'); }
        if ( empty($ids_raw) || ! is_array($ids_raw) ) { wp_send_json_error('Sin pedidos para quitar'); }

        $plan = get_option( $this->get_plan_key($plan_id) );
        if ( empty($plan) ) { wp_send_json_error('Plan no encontrado'); }

        $depot  = $plan['depot'] ?? $this->get_depot();

        $remove = array();
        foreach ( $ids_raw as $id ) { $id = intval($id); if ( $id ) $remove[$id] = true; }

        // Tokens por ruta y rutas afectadas (las que contienen algún pedido a quitar)
        $tokens   = array();
        $affected = array();
        foreach ( $plan['groups'] as $gi => $g ) {
            $tokens[$gi] = $plan['tokens'][$gi] ?? $plan['tokens'][$g['name']] ?? '';
            foreach ( ($g['orders'] ?? array()) as $o ) {
                if ( isset($remove[ intval($o['id']) ]) ) { $affected[$gi] = true; break; }
            }
        }
        if ( empty($affected) ) { wp_send_json_error('Ningún pedido seleccionado está en este plan'); }

        // Quitar de cada ruta afectada, limpiar metas y reoptimizar (refresca token)
        $removed = 0;
        foreach ( array_keys($affected) as $gi ) {
            $token = $tokens[$gi];
            $state = $this->group_delivery_state( $plan['groups'][$gi]['orders'] ?? array(), $token );
            $kept  = array();
            foreach ( $plan['groups'][$gi]['orders'] as $o ) {
                $oid = intval($o['id']);
                if ( isset($remove[$oid]) ) {
                    unset( $state[$oid] );
                    $this->clear_order_route_metas( $oid );
                    $removed++;
                    continue;
                }
                $kept[] = $o;
            }
            $group = &$plan['groups'][$gi];
            $this->rebuild_group( $group, $token, $kept, $state, $depot );
            unset( $group );
        }

        update_option( $this->get_plan_key($plan_id), $plan, false );

        $this->log('OK', 'remove_orders: pedidos quitados de sus rutas', array(
            'plan_id' => $plan_id,
            'removed' => $removed,
        ));

        wp_send_json_success( array(
            'removed' => $removed,
            'groups'  => $plan['groups'],
        ) );
    }

    // ── TOKEN: generar y guardar ruta para conductor ──────────────────────────

    public function ajax_save_token() {
        check_ajax_referer( 'wdg_nonce', 'nonce' );

        $group_data = json_decode( stripslashes( $_POST['group'] ?? '{}' ), true );
        $depot      = $this->get_depot();

        if ( empty($group_data) ) { wp_send_json_error('Sin datos de grupo'); }

        // Generar token único de 10 caracteres
        $token   = substr( bin2hex( random_bytes(8) ), 0, 10 );
        $expiry  = time() + ( 72 * 3600 ); // 72 horas

        $plan_id   = sanitize_text_field( $_POST['plan_id']   ?? '' );
        $plan_name = sanitize_text_field( $_POST['plan_name'] ?? '' );

        // Inyectar plan_id en el grupo para trazabilidad
        $group_data['plan_id']     = $plan_id;
        $group_data['plan_name']   = $plan_name;
        $group_data['driver_id']   = sanitize_text_field( $_POST['driver_id']   ?? '' );
        $group_data['driver_name'] = sanitize_text_field( $_POST['driver_name'] ?? ($group_data['driver_name'] ?? '') );
        $group_data['vehicle']     = sanitize_text_field( $_POST['vehicle']     ?? '' );

        $payload = array(
            'token'      => $token,
            'expiry'     => $expiry,
            'group'      => $group_data,
            'depot'      => $depot,
            'plan_id'    => $plan_id,
            'plan_name'  => $plan_name,
            'created_at' => time(),
            'api_key'    => $this->get_api_key(),
        );

        // Guardar en options con prefijo wdg_route_
        update_option( 'wdg_route_' . $token, $payload, false );

        // Registrar eventos 'assigned' en la tabla de análisis
        $this->insert_assigned_events( $payload );

        // Limpiar tokens expirados (housekeeping)
        $this->cleanup_expired_tokens();

        $url = add_query_arg( 'wdg_ruta', $token, home_url('/') );

        wp_send_json_success( array(
            'token'   => $token,
            'url'     => $url,
            'expiry'  => date( 'd/m/Y H:i', $payload['expiry'] ),
        ));
    }

    private function cleanup_expired_tokens() {
        global $wpdb;

        // Obtener todos los tokens activos
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'wdg_route_%'
            )
        );

        foreach ( $rows as $row ) {
            $data = maybe_unserialize( $row->option_value );
            if ( is_array($data) && isset($data['expiry']) && $data['expiry'] < time() ) {
                delete_option( $row->option_name );
            }
        }
    }

    // ── ENDPOINT: página móvil del conductor ──────────────────────────────────

    public function handle_mobile_route() {
        $token = sanitize_text_field( $_GET['wdg_ruta'] ?? '' );
        if ( empty($token) ) return;

        $payload = get_option( 'wdg_route_' . $token );

        if ( empty($payload) ) {
            wp_die( '<h2>Enlace no válido</h2><p>Este enlace no existe o ha sido revocado.</p>', 'Enlace inválido', array('response' => 404) );
        }

        if ( $payload['expiry'] < time() ) {
            delete_option( 'wdg_route_' . $token );
            wp_die( '<h2>Enlace expirado</h2><p>Este enlace de reparto expiró. Solicita uno nuevo al administrador.</p>', 'Enlace expirado', array('response' => 410) );
        }

        $group   = $payload['group'];
        $depot   = $payload['depot'];
        $api_key = $payload['api_key'];
        $expiry  = date( 'd/m/Y', $payload['expiry'] ) . ' a las ' . date( 'H:i', $payload['expiry'] );

        // Serializar datos para JS
        $orders_json = json_encode( $group['orders'] ?? [] );
        $depot_json  = json_encode( $depot );
        $group_name  = esc_js( $group['name'] ?? 'Ruta' );
        $route_km    = floatval( $group['route_km'] ?? 0 );

        // Servir página HTML completa
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo $this->render_mobile_page( $group_name, $group['count'], $route_km, $expiry, $orders_json, $depot_json, $api_key, $payload );
        exit;
    }

    private function render_mobile_page( $name, $count, $route_km, $expiry, $orders_json, $depot_json, $api_key, $payload = array() ) {
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#1a73e8">
<title>Ruta <?php echo esc_html($name); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f5f5;color:#222;overflow-x:hidden}
/* ══ VISTA CONDUCTOR B-LIGHT ══════════════════════════════════════════════ */
body{background:#f8fafc}
#header{background:#1e293b;color:#fff;padding:14px 16px 12px;position:sticky;top:0;z-index:200;display:flex;align-items:flex-start;gap:10px}
#header h1{font-size:16px;font-weight:700;line-height:1.2;margin-bottom:2px}
#header .sub{font-size:11px;color:#94a3b8;display:flex;gap:8px;flex-wrap:wrap}
#header-info{flex:1}
#header-progress{height:3px;background:rgba(255,255,255,.12);border-radius:2px;margin-top:10px}
#header-progress-fill{height:100%;width:0%;background:#4ade80;border-radius:2px;transition:width .4s}
#map{width:100%;height:38vh;min-height:190px}

/* ── Parada actual ── */
#nav-panel{position:sticky;top:0;z-index:100;background:#fff;border-bottom:1px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,.08)}
#stop-header{display:flex;flex-direction:column;gap:0;padding:14px 16px 0}
#stop-badge{display:inline-flex;align-items:center;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;color:#1d4ed8;margin-bottom:8px;align-self:flex-start}
#stop-num{width:32px;height:32px;border-radius:8px;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0}
#stop-info{flex:1;min-width:0}
#stop-addr{font-size:15px;font-weight:700;color:#0f172a;line-height:1.3}
#stop-meta{font-size:11px;color:#64748b;margin-top:2px}
#stop-products-toggle{background:none;border:none;font-size:11px;color:#0ea5e9;padding:4px 0 0;cursor:pointer;text-decoration:underline;font-weight:600}
#stop-phone{font-size:12px;color:#0ea5e9;font-family:monospace;margin-top:2px;font-weight:700}

/* Cliente row en panel */
#stop-client-row{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#f8fafc;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9}
#stop-avatar{width:34px;height:34px;background:#dbeafe;color:#1d4ed8;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
#stop-client-info{flex:1;min-width:0}
#stop-client-name{font-size:13px;font-weight:600;color:#0f172a}
#stop-client-phone{font-size:11px;color:#64748b}
.scontact-btns-row{display:flex;gap:6px;margin-left:auto}
.scontact-btn-new{width:32px;height:32px;border-radius:9px;border:1px solid #e2e8f0;background:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;cursor:pointer;text-decoration:none}

/* ── Botones de acción ── */
#action-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;padding:10px 14px 12px;background:#fff}
#btn-navigate{grid-column:1/-1;height:46px;background:#0ea5e9;color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px}
#btn-navigate:active{background:#0284c7}
#btn-no-entregado{height:40px;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;border-radius:10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap}
#btn-no-entregado:active{background:#e2e8f0}
#btn-partial{height:40px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap}
#btn-partial:active{background:#ffedd5}
#partial-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px}
#photo-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px}
#photo-modal.open{display:flex}
#photo-modal-box{background:#fff;border-radius:12px;padding:20px;width:100%;max-width:360px}
#photo-modal-box h3{font-size:16px;margin:0 0 4px;color:#1a1a2e}
#photo-modal-box p{font-size:12px;color:#64748b;margin:0 0 14px}
#photo-preview{width:100%;border-radius:8px;margin-bottom:12px;display:none;max-height:220px;object-fit:cover}
.photo-modal-btns{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.photo-modal-btns button, .photo-modal-btns label{flex:1;padding:12px;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;text-align:center;min-width:100px}
#btn-take-photo{background:#1a73e8;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px}
#btn-skip-photo{background:#f1f5f9;color:#374151}
#btn-confirm-photo{background:#4caf50;color:#fff;display:none}
#btn-retake-photo{background:#f59e0b;color:#fff;display:none}
#photo-upload-input{display:none}
#photo-upload-progress{font-size:12px;color:#0369a1;margin-top:6px;text-align:center;display:none}
#partial-modal.open{display:flex}
#partial-modal-box{background:#fff;border-radius:12px;padding:20px;width:100%;max-width:340px}
#partial-modal-box h3{font-size:16px;margin:0 0 6px;color:#1a1a2e}
#partial-modal-box p{font-size:12px;color:#64748b;margin:0 0 12px}
#partial-note{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:14px;resize:vertical;min-height:80px;font-family:inherit}
#partial-note:focus{outline:none;border-color:#f59e0b}
.partial-modal-btns{display:flex;gap:8px;margin-top:12px}
.partial-modal-btns button{flex:1;padding:12px;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer}
#partial-confirm{background:#f59e0b;color:#fff}
#partial-confirm:active{background:#d97706}
#partial-cancel{background:#f1f5f9;color:#374151}
#btn-partial:active{background:#d97706}
#btn-done{height:40px;background:#16a34a;color:#fff;border:none;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}
#btn-done:active{background:#388e3c}

/* ── Estado: todas completadas ── */
#panel-done{display:none;padding:24px 16px;text-align:center;background:#fff;border-bottom:3px solid #4caf50}
#panel-done .icon{font-size:48px;margin-bottom:8px}
#panel-done h2{font-size:18px;color:#2e7d32;margin-bottom:4px}
#panel-done p{font-size:13px;color:#666}

/* ── Barra de progreso ── */
#progress-bar{background:#e3e3e3;height:4px}
#progress-fill{height:100%;background:#1a73e8;transition:width .4s}

/* ── Lista de paradas ── */
#stop-list{padding-bottom:40px}
.sitem{background:#fff;border-bottom:1px solid #eee;display:flex;flex-direction:column}
.sitem.s-active{background:#e8f0fe;border-left:4px solid #1a73e8}
.sitem.s-done{opacity:.4}
.sitem.s-visited{opacity:.75;border-left:3px solid #f97316}
.snum.visited{background:#f97316}
.sitem-body{display:flex;align-items:center;gap:10px;padding:10px 16px;cursor:pointer}
.snum{width:26px;height:26px;border-radius:50%;background:#1a73e8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;transition:background .3s}
.snum.done{background:#4caf50}
.snum.depot{background:#1a1a2e;font-size:10px}
.sinfo{flex:1;min-width:0}
.saddr{font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.smeta{font-size:11px;color:#777;margin-top:1px}
.scontact{display:flex;align-items:center;gap:8px;margin-top:4px}
.scontact-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;text-decoration:none;font-size:15px;background:#f1f5f9;transition:background .15s}
.scontact-btn:active{background:#e2e8f0}
.scontact-btn.phone{background:#e8f0fe}
.scontact-btn.whatsapp{background:#dcfce7;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%2325D366' d='M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.118 1.531 5.845L.057 23.492a.5.5 0 0 0 .614.614l5.796-1.522A11.955 11.955 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 0 1-5.006-1.368l-.36-.214-3.732.979.995-3.641-.235-.374A9.818 9.818 0 1 1 12 21.818z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:center;background-size:18px 18px}
.scontact-btn.email{background:#fef3c7}
.sorder-id{font-size:10px;font-weight:700;color:#64748b;background:#f1f5f9;border-radius:3px;padding:1px 4px;margin-right:3px}
.sitem-body{cursor:pointer}
.sitem-expand{overflow:hidden;max-height:0;transition:max-height .3s ease;background:#f8fafc;border-top:0px solid #e5e7eb;width:100%}
.sitem-expand.open{max-height:800px;border-top:1px solid #e5e7eb}
.sitem-products{padding:8px 16px 12px 52px}
.sprod-row{display:flex;align-items:center;gap:12px;padding:7px 0;border-bottom:1px solid #f1f5f9}
.sprod-row:last-child{border-bottom:none}
.sprod-thumb{width:44px;height:44px;border-radius:6px;object-fit:cover;flex-shrink:0;background:#e5e7eb}
.sprod-thumb-placeholder{width:44px;height:44px;border-radius:6px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.sprod-name{flex:1;font-size:13px;color:#374151;line-height:1.4}
.sprod-qty{font-size:13px;font-weight:700;color:#1a73e8;background:#e8f0fe;border-radius:4px;padding:3px 9px;white-space:nowrap;flex-shrink:0}
.saddr-row{display:flex;align-items:center;justify-content:space-between;gap:6px}
.sexpand-icon{font-size:11px;color:#94a3b8;flex-shrink:0;transition:transform .2s}
.sexpand-icon.open{transform:rotate(180deg)}

#footer{background:#fff;border-top:1px solid #eee;padding:8px 16px;display:flex;justify-content:space-between;font-size:11px;color:#777;position:sticky;bottom:0;z-index:100}
</style>
</head>
<body>

<div id="header">
  <div id="header-info" style="flex:1">
    <h1>Ruta <?php echo esc_html($name); ?></h1>
    <div class="sub">
      <span><?php echo intval($count); ?> paradas</span>
      <?php if($route_km > 0): ?><span>·</span><span>~<?php echo $route_km; ?> km</span><?php endif; ?>
      <?php
        $veh = $payload['group']['vehicle'] ?? '';
        if($veh): ?>
      <span>·</span><span>🚗 <?php echo esc_html($veh); ?></span>
      <?php endif; ?>
      <span>·</span><span>Vence: <?php echo esc_html($expiry); ?></span>
    </div>
    <div id="header-progress"><div id="header-progress-fill"></div></div>
  </div>
  <button id="btn-end-route" onclick="wdgEndRoute()" style="background:rgba(248,113,113,.12);color:#f87171;border:1px solid rgba(248,113,113,.3);border-radius:8px;padding:7px 11px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0">🏁 Terminar</button>
</div>

<div id="map"></div>
<div id="progress-bar"><div id="progress-fill" style="width:0%"></div></div>

<!-- Panel parada actual -->
<div id="nav-panel">
  <div id="stop-header">
    <div id="stop-badge">Parada actual</div>
    <div style="display:flex;align-items:flex-start;gap:10px">
      <div id="stop-num">1</div>
      <div id="stop-info">
        <div id="stop-addr">Cargando...</div>
        <div id="stop-meta"></div>
        <div id="stop-phone"></div>
        <button id="stop-products-toggle" onclick="wdgToggleNavProducts()" style="display:none">▼ Ver productos</button>
      </div>
    </div>
  </div>
  <div id="stop-client-row" style="display:none">
    <div id="stop-avatar">?</div>
    <div id="stop-client-info">
      <div id="stop-client-name"></div>
      <div id="stop-client-phone"></div>
    </div>
    <div class="scontact-btns-row" id="stop-contact-btns"></div>
  </div>
  <div id="action-row">
    <button id="btn-navigate" onclick="navigateNext()">▶ Navegar a esta parada</button>
    <button id="btn-no-entregado" onclick="markNoEntregadoAndNext()">🚫 No Entregado</button>
    <button id="btn-partial" onclick="markPartialAndNext()">⚠️ Parcial</button>
    <button id="btn-done" onclick="markDoneAndNext()">✅ Completar</button>
  </div>
  <div id="stop-products" style="display:none;border-top:1px solid #e5e7eb;background:#f8fafc;padding:10px 16px"></div>
</div>

<!-- Panel fin de ruta -->
<div id="panel-done">
  <div class="icon">🎉</div>
  <h2>¡Ruta completada!</h2>
  <p>Todas las paradas entregadas. Regresa a la bodega.</p>
  <?php if ( !empty(json_decode($depot_json, true)['address']) ) : ?>
  <button onclick="returnToDepot()" style="margin-top:14px;background:#1a1a2e;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:14px;font-weight:700;cursor:pointer;width:100%">
    🏭 Navegar a Bodega
  </button>
  <?php endif; ?>
</div>


<div id="stop-list"></div>

<div id="footer">
  <div>
    <div style="font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-bottom:1px">Completadas</div>
    <span id="progress-txt" style="font-size:18px;font-weight:700;color:#16a34a">0 / <?php echo intval($count); ?></span>
  </div>
  <div style="text-align:right">
    <div style="font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-bottom:1px">Km totales</div>
    <span id="km-txt" style="font-size:13px;font-weight:600;color:#0f172a"><?php echo $route_km > 0 ? '~'.$route_km.' km' : '—'; ?></span>
  </div>
</div>

<!-- Modal foto de entrega -->
<div id="photo-modal">
  <div id="photo-modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
      <h3 style="margin:0">📷 Foto de entrega</h3>
      <button onclick="wdgClosePhotoModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:0 4px;line-height:1">&times;</button>
    </div>
    <p>Toma una foto como comprobante (opcional)</p>
    <div style="margin-bottom:12px">
      <label style="font-size:12px;color:#374151;font-weight:600;display:block;margin-bottom:4px">Recibido por</label>
      <input type="text" id="photo-recipient" placeholder="Nombre de quien recibe"
        style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;font-family:inherit">
    </div>
    <img id="photo-preview" src="" alt="Preview">
    <div id="photo-upload-progress">⏳ Subiendo y enviando correo...</div>
    <div class="photo-modal-btns">
      <label id="btn-take-photo" for="photo-upload-input">📷 Tomar foto</label>
      <input type="file" id="photo-upload-input" accept="image/*" capture="environment">
      <button id="btn-retake-photo" onclick="wdgRetakePhoto()">🔄 Repetir</button>
      <button id="btn-skip-photo" onclick="wdgSkipPhoto()">Completar sin foto</button>
      <button id="btn-confirm-photo" onclick="wdgConfirmPhoto()">✅ Confirmar</button>
    </div>
  </div>
</div>

<!-- Modal No Entregado -->
<div id="no-entregado-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:12px;padding:20px;width:100%;max-width:340px">
    <h3 style="font-size:16px;margin:0 0 6px;color:#1a1a2e">🚫 No entregado</h3>
    <p style="font-size:12px;color:#64748b;margin:0 0 12px">Ingresa el motivo (opcional):</p>
    <textarea id="no-entregado-note" placeholder="Ej: Dirección no encontrada, local cerrado..."
      style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:14px;resize:vertical;min-height:70px;font-family:inherit"></textarea>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button onclick="closeNoEntregadoModal()" style="flex:1;padding:12px;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;background:#f1f5f9;color:#374151">Cancelar</button>
      <button onclick="confirmNoEntregado()" style="flex:1;padding:12px;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;background:#6b7280;color:#fff">Confirmar</button>
    </div>
  </div>
</div>

<!-- Modal nota parcial -->
<div id="partial-modal">
  <div id="partial-modal-box">
    <h3>⚠️ Entrega parcial</h3>
    <p>Ingresa el motivo o nota (opcional):</p>
    <textarea id="partial-note" placeholder="Ej: Cliente ausente, re-entregar mañana..."></textarea>
    <div class="partial-modal-btns">
      <button id="partial-cancel" onclick="closePartialModal()">Cancelar</button>
      <button id="partial-confirm" onclick="confirmPartial()">Confirmar parcial</button>
    </div>
  </div>
</div>

<script>
var ORDERS  = <?php echo $orders_json; ?>;
var DEPOT   = <?php echo $depot_json; ?>;
var API_KEY   = '<?php echo esc_js($api_key); ?>';
var WP_AJAX   = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
var ROUTE_TOKEN = '<?php echo esc_js(isset($payload['token']) ? $payload['token'] : ''); ?>';
var map, userMarker, directionsRenderers = [], markers = [];
// Progreso basado en estado REAL del pedido en WooCommerce (post_status).
// Solo se marca como "hecho" si el pedido está completado (wc-completed).
// Si fue parcial pero luego volvió a en-ruta, queda disponible nuevamente.
var serverDone = <?php
    $orders_in_group = $payload['group']['orders'] ?? [];
    $done_from_wc    = [];
    if ( ! empty($orders_in_group) ) {
        global $wpdb;
        $order_ids = array_map( 'intval', array_column( $orders_in_group, 'id' ) );
        $ids_in    = implode( ',', $order_ids );
        // Leer post_status real — solo wc-completed cuenta como entregado
        $statuses = $wpdb->get_results(
            "SELECT ID, post_status FROM {$wpdb->posts}
             WHERE ID IN ({$ids_in})
               AND post_type = 'shop_order'"
        );
        $completed_ids = [];
        foreach ( $statuses as $row ) {
            if ( $row->post_status === 'wc-completed' ) {
                $completed_ids[ intval($row->ID) ] = true;
            }
        }
        foreach ( $orders_in_group as $idx => $o ) {
            if ( isset( $completed_ids[ intval($o['id']) ] ) ) {
                $done_from_wc[ strval($idx) ] = true;
            }
        }
    }
    echo json_encode( (object) $done_from_wc );
?>;
// Fusionar con localStorage (puede tener paradas marcadas offline aún no sincronizadas)
var lsDone = {};
try { lsDone = JSON.parse(localStorage.getItem('wdg_done_<?php echo md5($orders_json); ?>') || '{}'); } catch(e){}
var done = {};
for (var _k in serverDone) { if (serverDone[_k]) done[_k] = true; }
for (var _k in lsDone)     { if (lsDone[_k])     done[_k] = true; }
// Partir desde el primer pedido no completado
var currentIdx = 0;
for (var _i = 0; _i < ORDERS.length; _i++) {
    if (!done[_i]) { currentIdx = _i; break; }
}

// ── Mapa ──────────────────────────────────────────────────────────────────────
// Ajustar top del nav-panel al alto real del header
(function() {
    var hdr = document.getElementById('header');
    var nav = document.getElementById('nav-panel');
    if (hdr && nav) {
        nav.style.top = hdr.offsetHeight + 'px';
    }
})();

window.initMap = function() {
    var center = (DEPOT && DEPOT.lat) ? {lat:+DEPOT.lat, lng:+DEPOT.lng} : {lat:-33.45,lng:-70.65};
    map = new google.maps.Map(document.getElementById('map'), {
        center: center, zoom: 12,
        disableDefaultUI: true, zoomControl: true, gestureHandling: 'greedy',
        styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]
    });

    // Marcadores numerados
    ORDERS.forEach(function(o, i) {
        var m = new google.maps.Marker({
            position: {lat:+o.lat, lng:+o.lng}, map: map,
            label: {text:String(i+1), color:'#fff', fontSize:'11px', fontWeight:'bold'},
            icon: {path:google.maps.SymbolPath.CIRCLE, fillColor:'#e63946', fillOpacity:.9, strokeColor:'#fff', strokeWeight:1.5, scale:12},
            zIndex:50
        });
        markers.push(m);
    });

    // Marcador bodega
    if (DEPOT && DEPOT.lat) {
        new google.maps.Marker({
            position:{lat:+DEPOT.lat,lng:+DEPOT.lng}, map:map,
            icon:{path:google.maps.SymbolPath.FORWARD_CLOSED_ARROW, fillColor:'#1a1a2e', fillOpacity:1, strokeColor:'#fff', strokeWeight:2, scale:6, rotation:180},
            zIndex:999
        });
    }

    drawRoute();
    startGeo();
    // Recalcular currentIdx desde done (puede haber cargado de localStorage o servidor)
    currentIdx = 0;
    for (var _j = 0; _j < ORDERS.length; _j++) {
        if (!done[_j]) { currentIdx = _j; break; }
    }
    renderList();
    updatePanel();
    initMarkerStates(); // pintar grises los ya completados (si hay progreso guardado)
};


// ── Actualizar color de marcador ─────────────────────────────────────────────
function updateMarkerState(idx) {
    if (!markers[idx]) return;
    var isDone    = done[idx] === true;
    var isVisited = done[idx] === 'visited';
    var fillColor   = isDone ? '#9ca3af' : (isVisited ? '#f97316' : '#e63946');
    var fillOpacity = isDone ? .45 : (isVisited ? .75 : .9);
    var scale       = isDone ? 10 : 12;
    var labelColor  = isDone ? '#6b7280' : '#fff';
    markers[idx].setIcon({
        path:         google.maps.SymbolPath.CIRCLE,
        fillColor:    fillColor,
        fillOpacity:  fillOpacity,
        strokeColor:  '#fff',
        strokeWeight: 1.5,
        scale:        scale
    });
    markers[idx].setLabel({
        text:       String(idx+1),
        color:      labelColor,
        fontSize:   '11px',
        fontWeight: 'bold'
    });
    markers[idx].setZIndex(isDone ? 10 : (isVisited ? 20 : 50));
}

// Pintar estado inicial de todos los marcadores (al cargar con progreso guardado)
function initMarkerStates() {
    ORDERS.forEach(function(o, i) { updateMarkerState(i); });
}

// ── Ruta directa con coordenadas existentes (sin Directions API — gratis) ────
// Ya tenemos las coords ordenadas por TSP desde WordPress.
// Dibujamos polyline directo — sin llamadas a API externas.
function drawRoute() {
    if (ORDERS.length < 2) return;

    var path = [];
    if (DEPOT && DEPOT.lat) path.push({lat:+DEPOT.lat, lng:+DEPOT.lng});
    ORDERS.forEach(function(o){ path.push({lat:+o.lat, lng:+o.lng}); });
    if (DEPOT && DEPOT.lat) path.push({lat:+DEPOT.lat, lng:+DEPOT.lng});

    new google.maps.Polyline({
        path: path,
        map: map,
        geodesic: true,
        strokeColor:   '#1a73e8',
        strokeOpacity: 0.6,
        strokeWeight:  4
    });
}

// ── Geolocalización ───────────────────────────────────────────────────────────
function startGeo() {
    if (!navigator.geolocation) return;
    navigator.geolocation.watchPosition(function(pos) {
        var ll = {lat:pos.coords.latitude, lng:pos.coords.longitude};
        if (!userMarker) {
            userMarker = new google.maps.Marker({position:ll, map:map, zIndex:1000,
                icon:{path:google.maps.SymbolPath.CIRCLE, fillColor:'#1a73e8', fillOpacity:1,
                    strokeColor:'#fff', strokeWeight:3, scale:9}});
        } else {
            userMarker.setPosition(ll);
        }
    }, null, {enableHighAccuracy:true, maximumAge:5000});
}

// ── Navegar a parada actual ───────────────────────────────────────────────────
function navigateNext() {
    var o = ORDERS[currentIdx];
    if (!o) return;
    // Abrir Google Maps en modo navegación turn-by-turn
    var url = 'https://www.google.com/maps/dir/?api=1'
        + '&destination=' + o.lat + ',' + o.lng
        + '&travelmode=driving&dir_action=navigate';
    window.open(url, '_blank');
}

// ── Marcar completada y avanzar ───────────────────────────────────────────────
function markNoEntregadoAndNext() {
    document.getElementById('no-entregado-note').value = '';
    document.getElementById('no-entregado-modal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('no-entregado-note').focus(); }, 100);
}

function closeNoEntregadoModal() {
    document.getElementById('no-entregado-modal').style.display = 'none';
}

function confirmNoEntregado() {
    var note = document.getElementById('no-entregado-note').value.trim();
    closeNoEntregadoModal();

    var o = ORDERS[currentIdx];
    if (o && o.id && ROUTE_TOKEN && WP_AJAX) {
        var fd = new FormData();
        fd.append('action',   'wdg_partial_order');
        fd.append('token',    ROUTE_TOKEN);
        fd.append('order_id', o.id);
        fd.append('note',     note ? 'No entregado: ' + note : 'No entregado');
        fetch(WP_AJAX, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(data){ console.log('[WDG] no_entregado #'+o.id+':', data); })
            .catch(function(err){ console.error('[WDG] no_entregado error:', err); });
    }

    // Marcar como visitado (para avanzar) pero NO como entregado
    done[currentIdx] = 'visited';
    saveDone();
    updateMarkerState(currentIdx);

    var found = false;
    for (var j = 0; j < ORDERS.length; j++) {
        if (!done[j]) { currentIdx = j; found = true; break; }
    }
    renderList();
    updatePanel();
    updateProgress();
}

function markPartialAndNext() {
    // Abrir modal para ingresar nota
    document.getElementById('partial-note').value = '';
    document.getElementById('partial-modal').classList.add('open');
    setTimeout(function(){ document.getElementById('partial-note').focus(); }, 100);
}

function closePartialModal() {
    document.getElementById('partial-modal').classList.remove('open');
}

var wdgPartialNote = '';

function confirmPartial() {
    wdgPartialNote = document.getElementById('partial-note').value.trim();
    closePartialModal();

    // Abrir modal de foto para el parcial
    wdgPhotoOrderIdx = currentIdx;
    wdgPhotoMode     = 'partial'; // modo parcial
    wdgResetPhotoModal();
    document.getElementById('photo-modal').classList.add('open');
}

function wdgDoPartial(orderIdx, photoUrl, recipient) {
    var o = ORDERS[orderIdx];
    if (o && o.id && ROUTE_TOKEN && WP_AJAX) {
        var fd = new FormData();
        fd.append('action',   'wdg_partial_order');
        fd.append('token',    ROUTE_TOKEN);
        fd.append('order_id', o.id);
        fd.append('note',     wdgPartialNote);
        if (photoUrl)   fd.append('photo_url',  photoUrl);
        if (recipient)  fd.append('recipient',  recipient);
        fetch(WP_AJAX, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(data){ console.log('[WDG] partial_order #'+o.id+':', data); })
            .catch(function(err){ console.error('[WDG] partial_order error:', err); });
    }

    // Marcar como visitado (para avanzar) pero NO como entregado
    done[orderIdx] = 'visited';
    saveDone();
    updateMarkerState(orderIdx);

    var found = false;
    for (var j = 0; j < ORDERS.length; j++) {
        if (!done[j]) { currentIdx = j; found = true; break; }
    }
    renderList();
    updatePanel();
    updateProgress();
}

var wdgPhotoOrderIdx = null; // índice de la parada actual al abrir el modal de foto
var wdgPhotoMode     = 'complete'; // 'complete' o 'partial'

function markDoneAndNext() {
    // Abrir modal de foto antes de completar
    wdgPhotoOrderIdx = currentIdx;
    wdgPhotoMode     = 'complete';
    wdgResetPhotoModal();
    document.getElementById('photo-modal').classList.add('open');
}

function wdgResetPhotoModal() {
    document.getElementById('photo-preview').style.display          = 'none';
    document.getElementById('photo-preview').src                    = '';
    document.getElementById('photo-upload-progress').style.display  = 'none';
    document.getElementById('photo-upload-progress').textContent    = '⏳ Subiendo y enviando correo...';
    document.getElementById('btn-take-photo').style.display         = 'flex';
    document.getElementById('btn-confirm-photo').style.display      = 'none';
    document.getElementById('btn-confirm-photo').disabled           = false;
    document.getElementById('btn-retake-photo').style.display       = 'none';
    document.getElementById('btn-skip-photo').style.display         = 'block';
    document.getElementById('photo-upload-input').value             = '';
    var recip = document.getElementById('photo-recipient');
    if (recip) recip.value = '';
}

function wdgClosePhotoModal() {
    document.getElementById('photo-modal').classList.remove('open');
    wdgResetPhotoModal();
}

function wdgSkipPhoto() {
    var recipient = document.getElementById('photo-recipient').value.trim();
    document.getElementById('photo-modal').classList.remove('open');
    if (wdgPhotoMode === 'partial') {
        wdgDoPartial(wdgPhotoOrderIdx, null, recipient);
    } else {
        wdgDoComplete(wdgPhotoOrderIdx, null, recipient);
    }
}

function wdgRetakePhoto() {
    document.getElementById('photo-preview').style.display = 'none';
    document.getElementById('btn-take-photo').style.display = 'flex';
    document.getElementById('btn-confirm-photo').style.display = 'none';
    document.getElementById('btn-retake-photo').style.display = 'none';
    document.getElementById('photo-upload-input').value = '';
    document.getElementById('photo-upload-input').click();
}

function wdgConfirmPhoto() {
    var input   = document.getElementById('photo-upload-input');
    var file    = input.files[0];
    var orderIdx = wdgPhotoOrderIdx;
    var o        = ORDERS[orderIdx];

    document.getElementById('photo-upload-progress').style.display = 'block';
    document.getElementById('btn-confirm-photo').disabled = true;

    // Comprimir con Canvas API antes de subir
    var reader = new FileReader();
    reader.onload = function(e) {
        var img    = new Image();
        img.onload = function() {
            var maxW   = 900;
            var ratio  = Math.min(maxW / img.width, maxW / img.height, 1);
            var canvas = document.createElement('canvas');
            canvas.width  = Math.round(img.width  * ratio);
            canvas.height = Math.round(img.height * ratio);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

            // Comprimir ajustando calidad dinámicamente hasta quedar bajo 200KB
            function uploadBlob(blob) {
                var fd = new FormData();
                var recipient = document.getElementById('photo-recipient').value.trim();
                fd.append('action',    'wdg_upload_photo');
                fd.append('token',     ROUTE_TOKEN);
                fd.append('order_id',  o.id);
                fd.append('recipient', recipient);
                fd.append('photo',     blob, 'entrega-' + o.id + '.jpg');

                // Timeout de 30s para conexiones lentas
                var controller = new AbortController();
                var timer = setTimeout(function(){ controller.abort(); }, 30000);

                fetch(WP_AJAX, {method:'POST', body:fd, signal:controller.signal})
                    .then(function(r){ clearTimeout(timer); return r.json(); })
                    .then(function(data){
                        console.log('[WDG] upload_photo:', data);
                        document.getElementById('photo-modal').classList.remove('open');
                        var photoUrl = data.success ? data.data.url : null;
                        if (wdgPhotoMode === 'partial') {
                            wdgDoPartial(orderIdx, photoUrl, recipient);
                        } else {
                            wdgDoComplete(orderIdx, photoUrl, recipient);
                        }
                    })
                    .catch(function(err){
                        clearTimeout(timer);
                        console.error('[WDG] upload_photo error:', err);
                        var prog = document.getElementById('photo-upload-progress');
                        if (prog) prog.textContent = '⚠️ Sin conexión — pedido marcado sin foto';
                        setTimeout(function(){
                            document.getElementById('photo-modal').classList.remove('open');
                            if (wdgPhotoMode === 'partial') {
                                wdgDoPartial(orderIdx, null, recipient);
                            } else {
                                wdgDoComplete(orderIdx, null, recipient);
                            }
                        }, 2000);
                    });
            }

            // Reducir calidad hasta que el blob quede bajo 200KB
            function compressToTarget(quality) {
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        // toBlob falló — continuar sin foto
                        document.getElementById('photo-modal').classList.remove('open');
                        if (wdgPhotoMode === 'partial') { wdgDoPartial(orderIdx, null, recipient); }
                        else { wdgDoComplete(orderIdx, null, recipient); }
                        return;
                    }
                    if (blob.size > 200 * 1024 && quality > 0.30) {
                        // Todavía muy pesada — reducir calidad y reintentar
                        compressToTarget(Math.round((quality - 0.10) * 100) / 100);
                    } else {
                        uploadBlob(blob);
                    }
                }, 'image/jpeg', quality);
            }

            compressToTarget(0.72);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

// Preview al seleccionar foto
document.addEventListener('change', function(e) {
    if (e.target.id !== 'photo-upload-input') return;
    var file = e.target.files[0];
    if (!file) return;
    var url = URL.createObjectURL(file);
    var prev = document.getElementById('photo-preview');
    prev.src = url;
    prev.style.display = 'block';
    document.getElementById('btn-take-photo').style.display = 'none';
    document.getElementById('btn-confirm-photo').style.display = 'block';
    document.getElementById('btn-retake-photo').style.display = 'block';
    document.getElementById('btn-skip-photo').style.display = 'block';
});

function wdgDoComplete(orderIdx, photoUrl, recipient) {
    var o = ORDERS[orderIdx];
    done[orderIdx] = true;
    saveDone();
    updateMarkerState(orderIdx); // gris inmediato

    // Marcar pedido como completado en WooCommerce
    if (o && o.id && ROUTE_TOKEN && WP_AJAX) {
        var fd = new FormData();
        fd.append('action',    'wdg_complete_order');
        fd.append('token',     ROUTE_TOKEN);
        fd.append('order_id',  o.id);
        if (recipient) fd.append('recipient', recipient);
        fetch(WP_AJAX, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(data){
                console.log('[WDG] complete_order #'+o.id+':', data);
            })
            .catch(function(err){
                console.error('[WDG] complete_order error:', err);
            });
    }

    // Buscar siguiente no completada
    var found = false;
    for (var j = 0; j < ORDERS.length; j++) {
        if (!done[j]) { currentIdx = j; found = true; break; }
    }

    if (!found) {
        document.getElementById('nav-panel').style.display = 'none';
        document.getElementById('panel-done').style.display = 'block';
    }

    renderList();
    updatePanel();
    updateProgress();
}

// ── Volver a bodega ───────────────────────────────────────────────────────────
function returnToDepot() {
    if (!DEPOT || !DEPOT.lat) return;
    var url = 'https://www.google.com/maps/dir/?api=1'
        + '&destination=' + DEPOT.lat + ',' + DEPOT.lng
        + '&travelmode=driving&dir_action=navigate';
    window.open(url, '_blank');
}

// ── Actualizar panel superior ─────────────────────────────────────────────────
function updatePanel() {
    var o = ORDERS[currentIdx];
    // Badge: "Parada actual" si es la secuencial, "Parada seleccionada" si se eligió manualmente
    var badge = document.getElementById('stop-badge');
    if (badge) {
        // Determinar el siguiente pendiente secuencial
        var nextSeq = -1;
        for (var _s = 0; _s < ORDERS.length; _s++) {
            if (!done[_s]) { nextSeq = _s; break; }
        }
        badge.textContent = (currentIdx === nextSeq) ? 'Parada actual' : 'Parada seleccionada';
        badge.style.background = (currentIdx === nextSeq) ? '' : '#f59e0b';
    }
    if (!o) return;
    document.getElementById('stop-num').textContent  = currentIdx + 1;
    document.getElementById('stop-addr').textContent = '#' + o.id + ' — ' + o.address + (o.city ? ', ' + o.city : '');
    var metaHtml = esc(o.customer || '');
    if (o.address_2) metaHtml += '<div style="font-size:12px;color:#1d4ed8;margin-top:2px">🏠 ' + esc(o.address_2) + '</div>';
    if (o.note)      metaHtml += '<div style="font-size:12px;color:#92400e;background:#fef3c7;border-radius:4px;padding:3px 7px;margin-top:4px">📝 ' + esc(o.note) + '</div>';
    document.getElementById('stop-meta').innerHTML = metaHtml;
    var ph = document.getElementById('stop-phone');
    var pnum = o.phone ? o.phone.replace(/[^0-9+]/g,'') : '';
    var phtml = '';
    if (o.phone) {
        phtml += '<a class="scontact-btn phone" href="tel:' + o.phone + '">📞</a>';
        phtml += '<a class="scontact-btn whatsapp" href="https://wa.me/' + pnum + '" target="_blank"></a>';
    }
    if (o.email) {
        phtml += '<a class="scontact-btn email" href="mailto:' + o.email + '">✉️</a>';
    }
    ph.innerHTML = '<div class="scontact" style="margin-top:6px">' + phtml + '</div>';

    // Centrar mapa en parada actual
    map && map.panTo({lat:+o.lat, lng:+o.lng});

    // Renderizar productos en el panel sticky
    var prodEl    = document.getElementById('stop-products');
    var toggleBtn = document.getElementById('stop-products-toggle');
    if (o.items && o.items.length) {
        var ph = '';
        o.items.forEach(function(it) {
            ph += '<div class="sprod-row">';
            ph += it.thumb
                ? '<img class="sprod-thumb" src="' + esc(it.thumb) + '" alt="">'
                : '<div class="sprod-thumb-placeholder">📦</div>';
            ph += '<span class="sprod-name">' + esc(it.name) + '</span>';
            ph += '<span class="sprod-qty">x' + esc(String(it.qty)) + '</span>';
            ph += '</div>';
        });
        prodEl.innerHTML = ph;
        prodEl.style.display = 'none'; // empieza cerrado
        toggleBtn.style.display = 'block';
        toggleBtn.textContent   = '▼ Ver productos (' + o.items.length + ')';
    } else {
        prodEl.style.display  = 'none';
        toggleBtn.style.display = 'none';
    }
}

function wdgToggleNavProducts() {
    var prodEl    = document.getElementById('stop-products');
    var toggleBtn = document.getElementById('stop-products-toggle');
    var isOpen    = prodEl.style.display !== 'none';
    prodEl.style.display  = isOpen ? 'none' : 'block';
    toggleBtn.textContent = isOpen
        ? '▼ Ver productos (' + (ORDERS[currentIdx] && ORDERS[currentIdx].items ? ORDERS[currentIdx].items.length : '') + ')'
        : '▲ Ocultar productos';
}

// ── Progreso ──────────────────────────────────────────────────────────────────
function updateProgress() {
    var n    = Object.keys(done).filter(function(k){ return done[k] === true; }).length;
    var pct  = ORDERS.length > 0 ? Math.round(n / ORDERS.length * 100) : 0;
    document.getElementById('progress-fill').style.width = pct + '%';
    document.getElementById('progress-txt').textContent  = n + ' / ' + ORDERS.length + ' completadas';
}

// ── Renderizar lista ──────────────────────────────────────────────────────────
function renderList() {
    var html = '';

    if (DEPOT && DEPOT.lat) {
        html += '<div class="sitem"><div class="snum depot">B</div>'
              + '<div class="sinfo"><div class="saddr">' + esc(DEPOT.address) + '</div>'
              + '<div class="smeta">Punto de inicio</div></div></div>';
    }

    ORDERS.forEach(function(o, i) {
        var isDone    = done[i] === true;
        var isVisited = done[i] === 'visited';
        var cls   = isDone ? ' s-done' : (isVisited ? ' s-visited' : (i === currentIdx ? ' s-active' : ''));
        var ncls  = isDone ? ' done' : (isVisited ? ' visited' : '');
        var phoneNum = o.phone ? o.phone.replace(/[^0-9+]/g,'') : '';
        var contactHtml = '<div class="scontact">';
        if (o.phone) {
            contactHtml += '<a class="scontact-btn phone" href="tel:' + esc(o.phone) + '" onclick="event.stopPropagation()">📞</a>';
            contactHtml += '<a class="scontact-btn whatsapp" href="https://wa.me/' + esc(phoneNum) + '" target="_blank" onclick="event.stopPropagation()"></a>';
        }
        if (o.email) {
            contactHtml += '<a class="scontact-btn email" href="mailto:' + esc(o.email) + '" onclick="event.stopPropagation()">✉️</a>';
        }
        contactHtml += '</div>';
        var order_id = '<span class="sorder-id">#' + esc(String(o.id)) + '</span>';
        // Productos del pedido
        var prodsHtml = '';
        if (o.items && o.items.length) {
            o.items.forEach(function(it) {
                prodsHtml += '<div class="sprod-row">';
                if (it.thumb) {
                    prodsHtml += '<img class="sprod-thumb" src="' + esc(it.thumb) + '" alt="">';
                } else {
                    prodsHtml += '<div class="sprod-thumb-placeholder">📦</div>';
                }
                prodsHtml += '<span class="sprod-name">' + esc(it.name) + '</span>';
                prodsHtml += '<span class="sprod-qty">x' + esc(String(it.qty)) + '</span>';
                prodsHtml += '</div>';
            });
        }

        html += '<div class="sitem' + cls + '" id="si-' + i + '">'
              + '<div class="sitem-body" onclick="wdgToggleStop(' + i + ')">'
              + '<div class="snum' + ncls + '" onclick="event.stopPropagation();selectStop(' + i + ')">' + (done[i] === true ? '✓' : (done[i] === 'visited' ? '!' : i+1)) + '</div>'
              + '<div class="sinfo">'
              + '<div class="saddr-row">'
              + '<div>' + order_id + ' ' + esc(o.address) + (o.city ? ', ' + esc(o.city) : '') + '</div>'
              + '<span class="sexpand-icon" id="sei-' + i + '">▼</span>'
              + '</div>'
              + '<div class="smeta">' + esc(o.customer) + '</div>'
              + contactHtml
              + '</div>'
              + '</div>'
              + (prodsHtml ? '<div class="sitem-expand" id="sexp-' + i + '"><div class="sitem-products">' + prodsHtml + '</div></div>' : '')
              + '</div>';
    });

    if (DEPOT && DEPOT.lat) {
        html += '<div class="sitem"><div class="snum depot">B</div>'
              + '<div class="sinfo"><div class="saddr">' + esc(DEPOT.address) + '</div>'
              + '<div class="smeta">Retorno a bodega</div></div></div>';
    }

    document.getElementById('stop-list').innerHTML = html;
    var el = document.getElementById('si-' + currentIdx);
    if (el) el.scrollIntoView({behavior:'smooth', block:'nearest'});
}

function selectStop(i) {
    currentIdx = i;
    updatePanel();
    renderList();
    // Scroll al panel superior para que el conductor vea la parada seleccionada
    var nav = document.getElementById('nav-panel');
    if (nav) nav.scrollIntoView({behavior:'smooth', block:'start'});
}

function wdgToggleStop(i) {
    // Seleccionar esta parada como activa en el panel superior
    if (!done[i] || done[i] === 'visited') {
        selectStop(i);
    }
    var exp  = document.getElementById('sexp-' + i);
    var icon = document.getElementById('sei-' + i);
    if (!exp) return;
    var isOpen = exp.classList.contains('open');
    // Cerrar todos primero
    document.querySelectorAll('.sitem-expand.open').forEach(function(el) { el.classList.remove('open'); });
    document.querySelectorAll('.sexpand-icon.open').forEach(function(el) { el.classList.remove('open'); });
    // Abrir este si estaba cerrado
    if (!isOpen) {
        exp.classList.add('open');
        if (icon) icon.classList.add('open');
    }
}

function saveDone() {
    try { localStorage.setItem('wdg_done_<?php echo md5($orders_json); ?>', JSON.stringify(done)); } catch(e){}
    // Sincronizar progreso al servidor
    if (ROUTE_TOKEN && WP_AJAX) {
        var fd = new FormData();
        fd.append('action', 'wdg_sync_progress');
        fd.append('token',  ROUTE_TOKEN);
        fd.append('done',   JSON.stringify(done));
        fetch(WP_AJAX, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(data){ console.log('[WDG] sync_progress:', data); })
            .catch(function(err){ console.error('[WDG] sync_progress error:', err); });
    }
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function wdgEndRoute() {
    var routeName = '<?php echo esc_js($name); ?>';
    var pending   = ORDERS.filter(function(o, i){ return !done[i] || done[i] === 'visited'; });
    var wa_number = '56952103997';

    // Notificar al servidor para marcar not_visited
    if (ROUTE_TOKEN && WP_AJAX && pending.length > 0) {
        var fd = new FormData();
        fd.append('action',  'wdg_finish_route');
        fd.append('token',   ROUTE_TOKEN);
        fd.append('pending', JSON.stringify(pending.map(function(o){ return o.id; })));
        fetch(WP_AJAX, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){ console.log('[WDG] finish_route:', d); })
            .catch(function(e){ console.error('[WDG] finish_route error:', e); });
    }

    var statusText;
    if (pending.length === 0) {
        statusText = 'Completa: Sin pedidos pendientes';
    } else {
        var ids = pending.map(function(o){ return '#' + o.id; }).join(', ');
        statusText = 'Pedidos pendientes: ' + ids;
    }

    var msg = 'Se ha terminado la ruta ' + routeName + '. ' + statusText;
    var url = 'https://wa.me/' + wa_number + '?text=' + encodeURIComponent(msg);
    window.open(url, '_blank');
}

// Restaurar progreso — done ya tiene la fusión de servidor + localStorage
(function() {
    // Persistir en localStorage la versión fusionada
    try { localStorage.setItem('wdg_done_<?php echo md5($orders_json); ?>', JSON.stringify(done)); } catch(e){}
    // Si todo completado mostrar panel final
    var nDone = Object.keys(done).filter(function(k){ return done[k]; }).length;
    if (nDone >= ORDERS.length && ORDERS.length > 0) {
        document.getElementById('nav-panel').style.display  = 'none';
        document.getElementById('panel-done').style.display = 'block';
    }
})();
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_js($api_key); ?>&callback=initMap" async defer></script>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}

new Woo_Delivery_Groups();
