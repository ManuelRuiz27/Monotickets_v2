# Guía de instalación de Monotickets API

Este documento describe los pasos recomendados para poner en marcha el entorno de Monotickets API en dos escenarios comunes:

1. **Equipos con Windows 11 utilizando Docker Desktop**
2. **Servidores con Ubuntu Server (22.04 LTS o superior)**

En ambos casos se asume que se utilizará Docker como motor de ejecución de servicios para simplificar la configuración de dependencias.

---

## 1. Windows 11 + Docker Desktop

### 1.1. Requisitos previos

- Windows 11 Pro/Enterprise con virtualización habilitada (BIOS/UEFI -> Intel VT-x / AMD-V).
- [Docker Desktop para Windows](https://www.docker.com/products/docker-desktop/), versión 4.28 o superior.
- [WSL 2](https://learn.microsoft.com/windows/wsl/) instalado y configurado como backend de Docker (Docker Desktop lo ofrece durante la instalación).
- [Git para Windows](https://git-scm.com/download/win).
- (Opcional) Un editor como Visual Studio Code con la extensión *Dev Containers*.

### 1.2. Configuración inicial

1. **Instalar Docker Desktop** y reiniciar si se solicita.
2. Abrir Docker Desktop y asegurarse de que el *Engine* está en ejecución.
3. Habilitar el uso de WSL 2 en Docker Desktop (`Settings > General > Use the WSL 2 based engine`).
4. Clonar el repositorio en una terminal de PowerShell o Git Bash:
   ```powershell
   git clone https://github.com/monotickets/Monotickets_v2.git
   cd Monotickets_v2
   ```
5. Copiar los archivos de ejemplo de entorno:
   ```powershell
   copy .env.example .env
   copy docker-compose.example.yml docker-compose.override.yml  # si existe
   ```
6. Ajustar las variables necesarias en `.env` (por ejemplo, credenciales de base de datos, claves JWT, correo SMTP). Para generar la clave de la aplicación se puede usar:
   ```powershell
   php -r "echo bin2hex(random_bytes(32));"
   ```
   e introducir el resultado en `APP_KEY`.
   Genera también una clave simétrica en base64 para proteger el fingerprint de dispositivos:
   ```powershell
   php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));"
   ```
   Usa el mismo valor en `FINGERPRINT_ENCRYPTION_KEY` dentro de `.env` y en `VITE_FINGERPRINT_ENCRYPTION_KEY` dentro de `frontend/.env`.

### 1.3. Puesta en marcha con Docker

1. Construir las imágenes y levantar los servicios:
   ```powershell
   docker compose up --build -d
   ```
2. Instalar dependencias de PHP dentro del contenedor `app`:
   ```powershell
   docker compose exec app composer install
   ```
3. Ejecutar migraciones y *seeders* iniciales:
   ```powershell
   docker compose exec app php artisan migrate --seed
   ```
4. Verificar que los contenedores están activos:
   ```powershell
   docker compose ps
   ```
5. Las migraciones incluyen las tablas para colas (`jobs` y `failed_jobs`). Tras sembrar la base deberías poder revisar la API en `http://localhost:8000`.

### 1.5. Poner en marcha el frontend

1. Copiar el archivo de entorno de ejemplo del frontend:
   ```powershell
   copy frontend\.env.example frontend\.env
   ```
   Ajusta `VITE_FINGERPRINT_ENCRYPTION_KEY` con la misma clave que configuraste en el backend y, si es necesario, `VITE_API_URL` (por defecto apunta a `http://localhost:8000/api`).
2. Instalar dependencias y arrancar Vite en modo desarrollo:
   ```powershell
   cd frontend
   npm install
   npm run dev
   ```
   El panel quedará disponible en `http://localhost:5173` (o el puerto que Vite indique en consola).
3. Credenciales de demo generadas por el seeder:
   - `superadmin@demo.test` / `DemoPassword123!`
   - `organizer1@demo.test` / `DemoPassword123!`
   - `organizer2@demo.test` / `DemoPassword123!`
   - `hostess@demo.test` / `DemoPassword123!`
   - `owner@demo.test` / `DemoPassword123!`
   Puedes usar cualquiera para iniciar sesión en el frontend.

4. (Opcional) Para detener el servidor de Vite, usa `Ctrl + C` en la terminal donde esté ejecutándose.

### 1.4. Tareas de desarrollo comunes

- Ejecutar pruebas:
  ```powershell
  docker compose exec app php artisan test
  ```
- Revisar logs de la aplicación:
  ```powershell
  docker compose logs -f app
  ```
- Detener el entorno:
  ```powershell
  docker compose down
  ```

---

## 2. Ubuntu Server (22.04 LTS+)

### 2.1. Requisitos previos

- Usuario con privilegios `sudo`.
- Acceso a internet para descargar paquetes.

### 2.2. Instalación de Docker Engine y Docker Compose

1. Actualizar paquetes:
   ```bash
   sudo apt update && sudo apt upgrade -y
   ```
2. Instalar dependencias necesarias:
   ```bash
   sudo apt install -y ca-certificates curl gnupg lsb-release
   ```
3. Añadir la clave GPG oficial de Docker y el repositorio estable:
   ```bash
   sudo install -m 0755 -d /etc/apt/keyrings
   curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
   echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
   sudo apt update
   ```
4. Instalar Docker Engine, CLI y Compose Plugin:
   ```bash
   sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
   ```
5. Añadir al usuario actual al grupo `docker` (opcional pero recomendado):
   ```bash
   sudo usermod -aG docker $USER
   newgrp docker
   ```

### 2.3. Clonar y preparar el proyecto

1. Clonar el repositorio:
   ```bash
   git clone https://github.com/monotickets/Monotickets_v2.git
   cd Monotickets_v2
   ```
2. Copiar archivos de entorno:
   ```bash
   cp .env.example .env
   cp docker-compose.example.yml docker-compose.override.yml  # si aplica
   ```
3. Configurar variables en `.env` (APP_KEY, configuración de base de datos, JWT, correo). Generar APP_KEY:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
   Generar la clave simétrica para el fingerprint (en base64) y reutilizarla en la variable `VITE_FINGERPRINT_ENCRYPTION_KEY` del frontend:
   ```bash
   php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));"
   ```

### 2.4. Despliegue con Docker Compose

1. Construir e iniciar servicios en segundo plano:
   ```bash
   docker compose up --build -d
   ```
2. Instalar dependencias de PHP:
   ```bash
   docker compose exec app composer install
   ```
3. Ejecutar migraciones y *seeders* (las tablas `jobs` y `failed_jobs` se crean automáticamente):
   ```bash
   docker compose exec app php artisan migrate --seed
   ```
4. Revisar estado de los contenedores:
   ```bash
   docker compose ps
   ```
5. Consultar logs:
   ```bash
   docker compose logs -f app
   ```

### 2.5. Operaciones adicionales

- Ejecutar pruebas automatizadas:
  ```bash
  docker compose exec app php artisan test
  ```
- Detener los contenedores:
  ```bash
  docker compose down
  ```

### 2.5. Frontend (Vite)

1. Copiar el archivo de entorno de ejemplo y editarlo según sea necesario:
   ```bash
   cp frontend/.env.example frontend/.env
   ```
2. Instalar dependencias y lanzar el servidor de desarrollo:
   ```bash
   cd frontend
   npm install
   npm run dev
   ```
3. El panel quedará disponible en `http://localhost:5173`. Usa las credenciales de demo listadas en la sección 1.5.

4. Para generar el build de producción:
   ```bash
   npm run build
   npm run preview # opcional, para validar el build
   ```

## Tareas programadas y mantenimiento

Las siguientes tareas automatizan procesos de facturación y cumplimiento. En entornos con Docker puedes ejecutarlas manualmente o habilitar el *scheduler* de Laravel.

- **Cerrar periodos de facturación:**
  ```bash
  docker compose exec app php artisan billing:close-periods
  ```
  Lanza trabajos en segundo plano con reintentos exponenciales para generar facturas y registrar la auditoría correspondiente.

- **Anonimizar tenants cancelados:**
  ```bash
  docker compose exec app php artisan tenants:anonymize-canceled
  ```
  Programa la anonimización de datos sensibles una vez superado el periodo de retención configurado (`TENANCY_ANONYMIZE_AFTER_DAYS`).

- **Ejecutar el scheduler en modo continuo:**
  ```bash
  docker compose exec app php artisan schedule:work
  ```
  Esto garantiza que los cierres mensuales y la anonimización diaria se ejecuten automáticamente dentro del contenedor.

---

## Notas finales

- Ajuste los puertos publicados en `docker-compose.yml` si los valores por defecto entran en conflicto con otros servicios.
- Para entornos productivos se recomienda configurar HTTPS mediante un *reverse proxy* (por ejemplo, Nginx + Certbot) y servicios de monitorización.
- Mantenga actualizadas las imágenes y dependencias ejecutando periódicamente `docker compose pull` y `composer update` (previa validación en entornos de prueba).
