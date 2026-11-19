# Site Mode Manager (WordPress)

Plugin para WordPress que permite poner el sitio en distintos modos (mantenimiento, suspendido, próximamente, etc.) y controlar su desactivación mediante una contraseña configurada al inicio.

El repositorio incluye dos variantes:

- **MU-plugin** (`wp-content/mu-plugins/site-mode-manager.php`): se carga automáticamente desde `wp-content/mu-plugins/` y no depende del nombre del archivo mientras mantenga extensión `.php`.
- **Plugin normal** (`site-mode-manager/`): instalable desde *Plugins → Añadir nuevo → Subir plugin* o copiando la carpeta a `wp-content/plugins/`.

## Características

- **Modos de sitio:**
  - **Desactivado (normal):** el sitio funciona de forma habitual.
  - **Mantenimiento:** muestra una página de mantenimiento con `HTTP 503` y cabecera `Retry-After` configurable.
  - **Suspendido:** bloqueo estricto con `HTTP 503` y mensaje genérico. No aplica bypass por rol; solo permite acceso por IPs autorizadas o mediante desbloqueo por contraseña.
  - **Próximamente:** página "coming soon" con `HTTP 200`.
  - **Solo administradores:** solo usuarios con capacidad de administración pueden ver el frontend (`403` para el resto).
  - **Solo lectura:** el sitio es visible, pero se restringen acciones de escritura (comentarios/pings cerrados, XML-RPC deshabilitado, envíos/comentarios bloqueados y escrituras REST bloqueadas para no administradores; *best-effort*).
  - **Desarrollo (lista blanca de IPs):** solo las IPs permitidas (y opcionalmente administradores) pueden acceder; el resto ve un bloqueo `403`.
- **Contraseña de control:**
  - Se configura en el primer uso desde el panel de administración.
  - Se almacena como hash (`password_hash`), nunca en texto plano.
  - Se requiere para **desactivar el modo** (volver a "Desactivado") y para cambiar la contraseña.
- **Desbloqueo de emergencia:** URL de rescate que funciona aunque el frontend esté bloqueado.
- **Interfaz de administración:** página en **Ajustes → Modos del sitio**.
- **WP-CLI:** comandos para consultar estado, desbloquear y cambiar de modo.
- **Protecciones:** nonces, comprobación de capacidades (`manage_options`) y límite de intentos con bloqueo temporal en el desbloqueo.

## Requisitos

- WordPress (con acceso al sistema de archivos).
- PHP compatible con `password_hash` / `password_verify` (recomendado PHP 7.4+).

## Instalación

### Opción A: MU-plugin

1. Copia `wp-content/mu-plugins/site-mode-manager.php` a tu instalación en:
   ```text
   wp-content/mu-plugins/site-mode-manager.php
   ```
2. Si la carpeta `wp-content/mu-plugins/` no existe, créala.
3. El plugin se carga automáticamente (no se activa/desactiva desde el listado de Plugins).

### Opción B: Plugin normal

**Desde un ZIP (recomendado):**

1. Genera el ZIP con los scripts incluidos (ver [Generar ZIP del plugin normal](#generar-zip-del-plugin-normal)) o usa `site-mode-manager.zip` si ya lo tienes.
2. En WordPress ve a *Plugins → Añadir nuevo → Subir plugin*, selecciona el ZIP e instálalo.
3. Activa **Site Mode Manager** desde *Plugins*.

**Manual (FTP/administrador de archivos):**

1. Copia la carpeta `site-mode-manager/` a:
   ```text
   wp-content/plugins/site-mode-manager/
   ```
2. Activa el plugin desde *Plugins* en el escritorio de WordPress.

## Generar ZIP del plugin normal

Desde la raíz del repositorio:

- **Linux/macOS/Git Bash/WSL:**
  ```sh
  sh build-plugin-zip.sh
  ```
- **Windows (PowerShell):**
  ```powershell
  .\build-plugin-zip.ps1
  ```

El script genera `site-mode-manager.zip` con la estructura correcta para el instalador de WordPress (`site-mode-manager/site-mode-manager.php` dentro del ZIP).

## Configuración inicial

1. Inicia sesión como administrador.
2. Ve a **Ajustes → Modos del sitio** (`/wp-admin/options-general.php?page=obsm-site-modes`).
3. Define la **contraseña inicial** (mínimo 6 caracteres). Hasta configurarla no se podrán activar modos.
4. Desde esa pantalla puedes:
   - Seleccionar el modo activo.
   - Definir un mensaje personalizado (Mantenimiento, Próximamente, Solo administradores, Desarrollo).
   - Configurar `Retry-After` en segundos (para Mantenimiento).
   - Activar/desactivar el *bypass* para administradores autenticados (donde aplique).
   - Gestionar la lista de IPs permitidas (una por línea), usada en **Suspendido** y **Desarrollo**.
   - Cambiar la contraseña (requiere la contraseña actual).

Para desactivar un modo activo (volver a "Desactivado") o cambiar la contraseña, es obligatorio introducir la contraseña actual.

## Modos y comportamiento

| Modo | Estado HTTP | Bypass admin* | Acceso por IP | Notas |
|---|---:|:---:|:---:|---|
| Desactivado | — | — | — | Sitio normal |
| Mantenimiento | 503 + `Retry-After` | Opcional | No | Mensaje personalizable |
| Suspendido | 503 | No (estricto) | Sí | Mensaje genérico; solo IPs permitidas o desbloqueo |
| Próximamente | 200 | Opcional | No | Adecuado para preventa/SEO |
| Solo administradores | 403 | Opcional | No | Solo capacidad `manage_options` |
| Solo lectura | 200 (vista) | Escrituras permitidas a admin | No | Restringe escrituras (best-effort) |
| Desarrollo (IP whitelist) | 403 | Opcional | Sí | Acceso limitado por IP (y admin si se habilita) |

\* El bypass para administradores autenticados es configurable y aplica a Mantenimiento, Próximamente, Solo administradores y Desarrollo. **Suspendido** es estricto: no hace bypass por rol.

## Desbloqueo de emergencia

Si el sitio queda inaccesible, puedes desactivar el modo introduciendo la contraseña en:

```text
https://tu-dominio.com/wp-login.php?action=obsm_unlock
```

Al validar la contraseña correctamente, el modo se establece en **Desactivado**.

## WP-CLI

```bash
# Ver estado actual
wp obsm status

# Desactivar el modo
wp obsm unlock
# o
wp obsm off

# Establecer un modo
wp obsm set --mode=off|maintenance|suspended|coming_soon|admins_only|readonly|dev
```

## Diferencias entre MU-plugin y plugin normal

- **MU-plugin:** no se activa desde *Plugins* y resiste el renombrado del archivo (mientras siga en `wp-content/mu-plugins/` con extensión `.php`). No sobrevive si se cambia la extensión, se elimina el archivo o se renombra/elimina la carpeta.
- **Plugin normal:** se puede activar/desactivar desde *Plugins* e instalar por ZIP. Si se renombra la carpeta o el archivo principal, WordPress pierde la referencia de activación.

La configuración se guarda en la base de datos (opción de WordPress), independiente del nombre del archivo.

## Consideraciones y solución de problemas

- **Caché:** si una página se sirve desde caché (plugin, hosting/CDN como Cloudflare, Varnish, etc.), el código PHP no se ejecuta y el modo no podrá interceptarla. Purga la caché al cambiar de modo y prueba en ventana de incógnito.
- **Archivos estáticos en la raíz:** un `index.html` en la raíz puede servirse para `/` antes que `index.php`, evitando que WordPress se ejecute.
- **Detección de IP:** se utiliza `REMOTE_ADDR` y, si está presente, la primera IP de `X-Forwarded-For`.
- **Modo "Solo lectura":** protección a nivel de WordPress (*best-effort*); flujos específicos de terceros pueden requerir ajustes.
- **Modo "Suspendido":** puede restringir el acceso al escritorio si tu IP no está en la lista blanca; usa la URL de desbloqueo o WP-CLI para recuperar el acceso.

## Estructura del repositorio

```text
site-mode-manager/                 # Versión plugin normal
  site-mode-manager.php
wp-content/
  mu-plugins/
    site-mode-manager.php          # Versión MU-plugin
build-plugin-zip.ps1               # Genera ZIP (PowerShell)
build-plugin-zip.sh                # Genera ZIP (sh/bash)
README.md
```

## Autor

Hektor Peña — [https://hektor.mx](https://hektor.mx)

## Licencia

Este proyecto se distribuye bajo licencia **GPL-2.0-or-later**. Consulta el archivo [`LICENSE`](LICENSE) para más detalles.
