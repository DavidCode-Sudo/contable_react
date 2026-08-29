<?php
/**
 * Configuración centralizada de assets locales
 * Este archivo centraliza todas las rutas de frameworks y librerías locales
 */

// Configuración de rutas base
$assets_base = BASE_URL . 'assets/';

// Configuración de frameworks
$assets_config = [
    'bootstrap' => [
        'css' => $assets_base . 'css/bootstrap/bootstrap.min.css',
        'js' => $assets_base . 'js/bootstrap.bundle.min.js'
    ],
    'fontawesome' => [
        'css' => $assets_base . 'css/fontawesome/all.min.css'
    ],
    'jquery' => [
        'js' => $assets_base . 'js/jquery-3.6.0.min.js'
    ],
    'select2' => [
        'css' => $assets_base . 'css/select2/select2.min.css',
        'js' => $assets_base . 'js/select2.min.js',
        'themes' => [
            'bootstrap4' => $assets_base . 'css/select2/select2-bootstrap4.min.css',
            'bootstrap5' => $assets_base . 'css/select2/select2-bootstrap5.min.css'
        ]
    ],
    'chartjs' => [
        'js' => $assets_base . 'js/chart.min.js'
    ],
    'fonts' => [
        'google' => $assets_base . 'fonts/google/fonts.css',
        'poppins' => $assets_base . 'fonts/google/poppins.css',
        'inter' => $assets_base . 'fonts/google/inter.css',
        'jetbrains' => $assets_base . 'fonts/google/jetbrains-mono.css'
    ]
];

/**
 * Función para obtener la URL de un asset
 */
function getAsset($framework, $type = null) {
    global $assets_config;
    
    if ($type) {
        return $assets_config[$framework][$type] ?? '';
    }
    
    return $assets_config[$framework] ?? [];
}

/**
 * Función para incluir Bootstrap
 */
function includeBootstrap($include_js = true) {
    global $assets_config;
    
    echo '<link href="' . $assets_config['bootstrap']['css'] . '" rel="stylesheet">' . "\n";
    if ($include_js) {
        echo '<script src="' . $assets_config['bootstrap']['js'] . '"></script>' . "\n";
    }
}

/**
 * Función para incluir Font Awesome
 */
function includeFontAwesome() {
    global $assets_config;
    echo '<link href="' . $assets_config['fontawesome']['css'] . '" rel="stylesheet">' . "\n";
}

/**
 * Función para incluir jQuery
 */
function includeJQuery() {
    global $assets_config;
    echo '<script src="' . $assets_config['jquery']['js'] . '"></script>' . "\n";
}

/**
 * Función para incluir Select2
 */
function includeSelect2($theme = 'bootstrap4') {
    global $assets_config;
    
    echo '<link href="' . $assets_config['select2']['css'] . '" rel="stylesheet">' . "\n";
    echo '<link href="' . $assets_config['select2']['themes'][$theme] . '" rel="stylesheet">' . "\n";
    echo '<script src="' . $assets_config['jquery']['js'] . '"></script>' . "\n";
    echo '<script src="' . $assets_config['select2']['js'] . '"></script>' . "\n";
}

/**
 * Función para incluir Chart.js
 */
function includeChartJS() {
    global $assets_config;
    echo '<script src="' . $assets_config['chartjs']['js'] . '"></script>' . "\n";
}

/**
 * Función para incluir fuentes de Google
 */
function includeGoogleFonts() {
    global $assets_config;
    echo '<link href="' . $assets_config['fonts']['google'] . '" rel="stylesheet">' . "\n";
}
?>
