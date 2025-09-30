<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monotickets · Documentación API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        :root {
            color-scheme: light dark;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #0f172a;
            color: #e2e8f0;
        }

        body {
            margin: 0;
            background: linear-gradient(180deg, rgba(15, 23, 42, 1) 0%, rgba(30, 41, 59, 1) 40%, rgba(15, 23, 42, 1) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(148, 163, 184, 0.15);
        }

        h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        .doc-actions {
            display: inline-flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .doc-toggle {
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(30, 41, 59, 0.8);
            color: #e2e8f0;
            padding: 0.45rem 0.9rem;
            border-radius: 999px;
            cursor: pointer;
            font-size: 0.95rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }

        .doc-toggle[aria-pressed="true"],
        .doc-toggle:focus-visible {
            background: #38bdf8;
            color: #0f172a;
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.35);
            outline: none;
        }

        main {
            flex: 1;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
        }

        .docs-panel {
            display: none;
            padding-bottom: 3rem;
        }

        .docs-panel.is-active {
            display: block;
        }

        footer {
            text-align: center;
            padding: 1.5rem;
            font-size: 0.875rem;
            color: rgba(226, 232, 240, 0.7);
            border-top: 1px solid rgba(148, 163, 184, 0.15);
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(12px);
        }

        .swagger-ui, .swagger-ui section.models {
            background-color: transparent !important;
        }

        .swagger-ui .topbar, .swagger-ui .topbar-wrapper {
            display: none;
        }

        @media (max-width: 720px) {
            header {
                flex-direction: column;
                align-items: flex-start;
            }

            .doc-actions {
                width: 100%;
                justify-content: stretch;
            }

            .doc-toggle {
                flex: 1 1 auto;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<header>
    <h1>Monotickets · Documentación API</h1>
    <div class="doc-actions" role="group" aria-label="Selección de visor">
        <button class="doc-toggle" type="button" data-doc-target="swagger" aria-pressed="true">Swagger UI</button>
        <button class="doc-toggle" type="button" data-doc-target="redoc" aria-pressed="false">Redoc</button>
        <a class="doc-toggle" href="{{ $schemaUrl }}" target="_blank" rel="noopener">Descargar OpenAPI</a>
    </div>
</header>
<main>
    <div id="swagger-container" class="docs-panel is-active"></div>
    <div id="redoc-container" class="docs-panel"></div>
</main>
<footer>
    Contrato actualizado automáticamente desde <code>docs/api/openapi_monotickets.yaml</code>.
</footer>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.min.js" integrity="sha256-2o6Yw3mqBPink8GYirl+d8tm1zxnHRjkmFvaLcjg1eU=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/redoc@next/bundles/redoc.standalone.js" integrity="sha256-5+oGeT6ijLJrVN6a8GN28AL5OHnqd7qV671CyMfCVxY=" crossorigin="anonymous"></script>
<script>
    const schemaUrl = @json($schemaUrl);
    const toggleButtons = document.querySelectorAll('button.doc-toggle');
    const panels = {
        swagger: document.getElementById('swagger-container'),
        redoc: document.getElementById('redoc-container')
    };

    toggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.dataset.docTarget;

            if (!target) {
                return;
            }

            toggleButtons.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));

            Object.entries(panels).forEach(([key, panel]) => {
                panel.classList.toggle('is-active', key === target);
            });
        });
    });

    window.SwaggerUIBundle({
        url: schemaUrl,
        dom_id: '#swagger-container',
        deepLinking: true,
        presets: [
            window.SwaggerUIBundle.presets.apis,
            window.SwaggerUIBundle.SwaggerUIStandalonePreset
        ],
        layout: 'BaseLayout'
    });

    window.Redoc.init(schemaUrl, {
        hideDownloadButton: true,
        expandResponses: '200,207',
        theme: {
            colors: { primary: { main: '#38bdf8' } },
            sidebar: { backgroundColor: '#0f172a' }
        }
    }, document.getElementById('redoc-container'));
</script>
</body>
</html>
