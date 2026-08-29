<?php
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

$baseUrl = rtrim(BASE_URL, '/') . '/';
$userName = $_SESSION['usuario_nombre'] ?? 'Usuario';
$userRoles = [];

if (isset($_SESSION['usuario_roles']) && is_array($_SESSION['usuario_roles'])) {
    foreach ($_SESSION['usuario_roles'] as $role) {
        if (is_array($role) && isset($role['nombre'])) {
            $userRoles[] = ucfirst((string) $role['nombre']);
        } elseif (is_string($role)) {
            $userRoles[] = ucfirst($role);
        }
    }
}

$initials = function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        if (mb_strlen($initials) === 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'U';
};

$appContext = [
    'baseUrl' => $baseUrl,
    'user' => [
        'name' => $userName,
        'initials' => $initials($userName),
        'roles' => $userRoles,
    ],
];

$viteDevServer = getenv('VITE_DEV_SERVER') ?: null;
$viteEntry = 'src/main.tsx';
$manifestPath = __DIR__ . '/../frontend/dist/manifest.json';

$assets = [
    'entry' => null,
    'preload' => [],
    'css' => [],
];

if ($viteDevServer) {
    $viteDevServer = rtrim($viteDevServer, '/');
    $assets['entry'] = $viteDevServer . '/' . ltrim($viteEntry, '/');
} elseif (is_file($manifestPath)) {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($manifest)) {
        $assets = resolveViteAssets($viteEntry, $manifest, $baseUrl . 'frontend/dist/');
    }
}

function resolveViteAssets(string $entry, array $manifest, string $publicPath): array
{
    $stack = [$entry];
    $visited = [];
    $entryFile = null;
    $preloads = [];
    $css = [];

    while ($stack) {
        $current = array_pop($stack);
        if (isset($visited[$current]) || !isset($manifest[$current])) {
            continue;
        }

        $visited[$current] = true;
        $chunk = $manifest[$current];

        $filePath = $publicPath . ($chunk['file'] ?? '');
        if ($current === $entry) {
            $entryFile = $filePath;
        } elseif (!empty($filePath)) {
            $preloads[] = $filePath;
        }

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
            foreach ($chunk['css'] as $cssFile) {
                $css[] = $publicPath . $cssFile;
            }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
            foreach ($chunk['imports'] as $import) {
                if (!isset($visited[$import])) {
                    $stack[] = $import;
                }
            }
        }
    }

    return [
        'entry' => $entryFile,
        'preload' => array_values(array_unique(array_filter($preloads))),
        'css' => array_values(array_unique(array_filter($css))),
    ];
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Contable Pro</title>
    <meta name="description" content="Panel administrativo del Sistema Contable Pro">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>frontend/dist/vite.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        (function () {
            try {
                const stored = localStorage.getItem('contable:theme')
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
                const theme = stored === 'dark' || (!stored && prefersDark) ? 'dark' : 'light'
                document.documentElement.dataset.theme = theme
                if (theme === 'dark') {
                    document.documentElement.classList.add('dark')
                }
            } catch (error) {
                document.documentElement.dataset.theme = 'light'
            }
        })();
    </script>

    <style>
        :root, html, body {
            height: 100%;
        }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #f5f6f8;
            color: #1f2937;
        }
        html.dark body {
            background-color: #0f172a;
            color: #e2e8f0;
        }
        #root {
            min-height: 100%;
        }
    </style>

    <?php if ($viteDevServer): ?>
        <script type="module" src="<?php echo htmlspecialchars($viteDevServer . '/@vite/client', ENT_QUOTES); ?>"></script>
    <?php endif; ?>

    <?php foreach ($assets['preload'] as $module): ?>
        <link rel="modulepreload" href="<?php echo htmlspecialchars($module, ENT_QUOTES); ?>">
    <?php endforeach; ?>

    <?php foreach ($assets['css'] as $cssFile): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile, ENT_QUOTES); ?>">
    <?php endforeach; ?>

    <script>
        window.__APP_CONTEXT__ = <?php echo json_encode($appContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
</head>
<body>
    <noscript>
        <div style="padding:1rem 1.5rem;font-family:system-ui;background:#fee2e2;color:#991b1b;">
            Necesitas habilitar JavaScript para utilizar el Sistema Contable Pro.
          </div>
    </noscript>
    <div id="root"></div>

    <?php if ($assets['entry']): ?>
        <script type="module" src="<?php echo htmlspecialchars($assets['entry'], ENT_QUOTES); ?>"></script>
    <?php else: ?>
        <div style="padding:1rem 1.5rem;color:#991b1b;background:#fee2e2;">
            No se encontró el bundle del frontend. Ejecuta <code>npm run build</code> dentro de <code>frontend/</code>.
                </div>
                <?php endif; ?>

