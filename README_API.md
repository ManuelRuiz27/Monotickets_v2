# Fase 2 · Guía de uso de la API

Esta guía resume los endpoints de autenticación y gestión de usuarios documentados en `docs/api/openapi_monotickets.yaml`. Utiliza los mismos códigos de respuesta y estructuras JSON expuestas en el contrato OpenAPI.

## Autenticación (`/auth`)

### Iniciar sesión

```http
POST /auth/login HTTP/1.1
Host: api.monotickets.app
Content-Type: application/json
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3

{
  "email": "admin@monotickets.app",
  "password": "p4ssw0rdSeguro"
}
```

**Respuesta 200**

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_expires_in": 3600,
  "session_id": "01HZYME6EBF1G01PZ8C6E6CBR3"
}
```

### Cerrar sesión

```http
POST /auth/logout HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
```

**Respuesta 200**

```json
{
  "message": "Logged out successfully."
}
```

### Refrescar token

```http
POST /auth/refresh HTTP/1.1
Host: api.monotickets.app
Content-Type: application/json

{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
}
```

**Respuesta 200** igual que al iniciar sesión.

### Recuperar contraseña

```http
POST /auth/forgot-password HTTP/1.1
Host: api.monotickets.app
Content-Type: application/json

{
  "email": "user@monotickets.app"
}
```

**Respuesta 200**

```json
{
  "message": "If the email exists, a reset link has been sent."
}
```

### Restablecer contraseña

```http
POST /auth/reset-password HTTP/1.1
Host: api.monotickets.app
Content-Type: application/json

{
  "email": "user@monotickets.app",
  "token": "5c8f5646c0d44a789f1f7e4c8fe31234abcd1234abcd1234abcd1234abcd1234",
  "password": "NuevaContraseñaSegura1!",
  "password_confirmation": "NuevaContraseñaSegura1!"
}
```

**Respuesta 200**

```json
{
  "message": "Password has been reset successfully."
}
```

## Usuarios (`/users`)

Todos los endpoints requieren `Authorization: Bearer <token>` y `X-Tenant-ID` (para usuarios no superadmin).

### Listar usuarios

```http
GET /users?role=organizer&per_page=10 HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
```

**Respuesta 200**

```json
{
  "data": [
    {
      "id": "01HZYME6EBF1G01PZ8C6E6CBR3",
      "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3",
      "name": "Ana Pérez",
      "email": "ana.perez@monotickets.app",
      "phone": "+5491122334455",
      "is_active": true,
      "roles": [
        {
          "id": "01HZYME7ABF1G01PZ8C6E6CBR4",
          "code": "organizer",
          "name": "Organizador",
          "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3"
        }
      ],
      "created_at": "2024-05-06T15:22:31Z",
      "updated_at": "2024-05-06T15:22:31Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 10,
    "total": 1,
    "total_pages": 1
  }
}
```

### Crear usuario

```http
POST /users HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
Content-Type: application/json

{
  "name": "Nuevo Operador",
  "email": "operador@monotickets.app",
  "phone": "+5491100000000",
  "password": "ContraseñaUltraSegura1!",
  "roles": ["hostess"],
  "is_active": true
}
```

**Respuesta 201**

```json
{
  "data": {
    "id": "01HZYME8CBF1G01PZ8C6E6CBR5",
    "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3",
    "name": "Nuevo Operador",
    "email": "operador@monotickets.app",
    "phone": "+5491100000000",
    "is_active": true,
    "roles": [
      {
        "id": "01HZYME7ABF1G01PZ8C6E6CBR4",
        "code": "hostess",
        "name": "Hostess",
        "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3"
      }
    ],
    "created_at": "2024-05-06T15:30:00Z",
    "updated_at": "2024-05-06T15:30:00Z"
  }
}
```

## Cambios de compatibilidad

- Los identificadores de ruta ahora utilizan `snake_case` (por ejemplo, `event_id`, `venue_id`, `invoice_id`). Actualiza cualquier integración que construya URLs manualmente.
- Los endpoints de facturación para cerrar y pagar facturas (`/billing/invoices/close`, `/billing/invoices/{invoice_id}/pay`) ahora requieren el método `PATCH`.
- La expiración de los tokens de restablecimiento de contraseña es configurable mediante la variable de entorno `PASSWORD_RESET_EXPIRATION_MINUTES` (por defecto 60 minutos).

### Ver usuario

```http
GET /users/01HZYME6EBF1G01PZ8C6E6CBR3 HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
```

**Respuesta 200** igual que en la sección anterior (`data` con el usuario).

### Actualizar usuario

```http
PATCH /users/01HZYME6EBF1G01PZ8C6E6CBR3 HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
Content-Type: application/json

{
  "roles": ["organizer"],
  "is_active": false
}
```

**Respuesta 200**

```json
{
  "data": {
    "id": "01HZYME6EBF1G01PZ8C6E6CBR3",
    "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3",
    "name": "Ana Pérez",
    "email": "ana.perez@monotickets.app",
    "phone": "+5491122334455",
    "is_active": false,
    "roles": [
      {
        "id": "01HZYME7ABF1G01PZ8C6E6CBR4",
        "code": "organizer",
        "name": "Organizador",
        "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3"
      }
    ],
    "created_at": "2024-05-06T15:22:31Z",
    "updated_at": "2024-05-06T15:45:12Z"
  }
}
```

### Eliminar usuario

```http
DELETE /users/01HZYME6EBF1G01PZ8C6E6CBR3 HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
```

**Respuesta 204** sin cuerpo.

## Analytics y ocupación

### Panel administrativo `/admin/analytics`

Devuelve tarjetas con métricas resumidas por evento. Permite filtrar por `tenant_id` y por rango de fechas (`from`, `to`). Cada tarjeta incluye series horarias (`attendance`) y un resumen con el porcentaje de ocupación (`overview.occupancy_rate`).

```http
GET /admin/analytics?from=2024-07-01&to=2024-07-02 HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
```

**Respuesta 200 (fragmento)**

```json
{
  "data": [
    {
      "event": {
        "id": "01J0ABCDXYZ4567890MNOPQ12",
        "tenant_id": "01HZYME6EBF1G01PZ8C6E6CBR3",
        "name": "Fiesta de lanzamiento",
        "start_at": "2024-07-01T18:00:00Z",
        "end_at": "2024-07-02T01:00:00Z",
        "timezone": "UTC",
        "status": "published"
      },
      "overview": {
        "invited": 120,
        "confirmed": 95,
        "attendances": 80,
        "duplicates": 4,
        "unique_attendees": 78,
        "occupancy_rate": 0.78
      },
      "attendance": [
        {"hour": "2024-07-01T19:00:00Z", "valid": 15, "duplicate": 1, "unique": 15}
      ]
    }
  ],
  "meta": {
    "tenants": [
      {"id": "01HZYME6EBF1G01PZ8C6E6CBR3", "name": "Demo Tenant", "slug": "demo-tenant"}
    ]
  }
}
```

### Analytics por evento `/events/{event_id}/analytics`

Entrega datasets paginados: serie horaria (`data.hourly`), totales por checkpoint (con `totals` y nombres de checkpoints), duplicados y errores. Usa paginación mediante parámetros `*_page` y `*_per_page`.

```http
GET /events/01J0ABCDXYZ4567890MNOPQ12/analytics?hour_per_page=12&duplicates_per_page=5 HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
X-Tenant-ID: 01HZYME6EBF1G01PZ8C6E6CBR3
```

**Respuesta 200 (fragmento)**

```json
{
  "data": {
    "hourly": {
      "data": [
        {"hour": "2024-07-01T19:00:00Z", "valid": 25, "duplicate": 2, "unique": 24}
      ],
      "meta": {"page": 1, "per_page": 12, "total": 24, "total_pages": 2}
    },
    "checkpoints": {
      "data": [
        {"checkpoint_id": "01J0CHKPT1234567890ABCDE", "name": "Acceso VIP", "valid": 40, "duplicate": 1, "invalid": 0}
      ],
      "meta": {"page": 1, "per_page": 10, "total": 3, "total_pages": 1},
      "totals": {"valid": 80, "duplicate": 4, "invalid": 2}
    }
  }
}
```

## Sincronización de escaneos offline

### POST `/scans/sync`

Permite enviar lotes deduplicados por `qr_code` + `scanned_at`. Devuelve un `status` HTTP `207` con el detalle por índice y un resumen agregado. Los resultados incluyen `ignored` cuando se omiten duplicados dentro del lote.

```http
POST /scans/sync HTTP/1.1
Host: api.monotickets.app
Authorization: Bearer <ACCESS_TOKEN>
Content-Type: application/json

{
  "scans": [
    {"qr_code": "MT-AAA-1111", "scanned_at": "2024-07-01T20:00:00Z"},
    {"qr_code": "MT-AAA-1111", "scanned_at": "2024-07-01T20:00:00Z"},
    {"qr_code": "UNKNOWN", "scanned_at": "2024-07-01T20:05:00Z"}
  ]
}
```

**Respuesta 207 (fragmento)**

```json
{
  "data": [
    {"index": 0, "result": "valid", "ticket": {"id": "01TICKET123"}},
    {"index": 1, "result": "ignored", "reason": "duplicate_payload", "deduplicated_with": 0},
    {"index": 2, "result": "invalid", "reason": "qr_not_found", "message": "The QR code could not be resolved."}
  ],
  "meta": {
    "summary": {"valid": 1, "duplicate": 1, "errors": 1, "deduplicated": 1},
    "total_scans": 3,
    "processed_scans": 2
  }
}
```

## Health check

`GET /health` ejecuta comprobaciones contra base de datos, Redis y la cola configurada. Responde `200` cuando todos los servicios están en estado `ok`, o `503` con `status: degraded` cuando alguno falla.

```http
GET /health HTTP/1.1
Host: api.monotickets.app
```

```json
{
  "status": "ok",
  "timestamp": "2024-07-01T21:00:00Z",
  "checks": {
    "database": {"status": "ok"},
    "redis": {"status": "ok"},
    "queue": {"status": "ok"}
  }
}
```

## Errores comunes

- **401 UNAUTHORIZED**
  ```json
  {
    "error": {
      "code": "UNAUTHORIZED",
      "message": "Invalid token provided."
    }
  }
  ```
- **422 VALIDATION_ERROR**
  ```json
  {
    "error": {
      "code": "VALIDATION_ERROR",
      "message": "The given data was invalid.",
      "details": {
        "email": ["The email field must be a valid email address."],
        "password": ["The password field must be at least 12 characters."]
      }
    }
  }
  ```
