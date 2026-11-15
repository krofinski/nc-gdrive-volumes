# Google Drive Volumes for Nextcloud (`gdrive_volumes`)

**Google Drive Volumes** es una aplicación para Nextcloud (v30–v35, PHP 8.2–8.5) que permite vincular una cuenta de Google Drive una sola vez y montar **múltiples subdirectorios remotos** (por ejemplo `NextCloud/Volume_1`, `NextCloud/Volume_2`) como si fueran **discos externos independientes** dentro de un **directorio local común + subdirectorio** en Nextcloud (por ejemplo `Gdrive/Volume_1`, `Gdrive/Volume_2`).

---

## Características principales

- **Vinculación única de cuenta Google Drive**:
  - **OAuth 2.0** (Client ID + Client Secret con renovación automática de `access_token`).
  - **Service Account (JSON)** mediante firma JWT RS256 nativa con OpenSSL (con soporte opcional de impersonación en Google Workspace).
  - Compatible con **Mi Unidad** y **Unidades Compartidas (Shared Drives)**.
- **Múltiples discos independientes desde subdirectorios de Drive**:
  - Cada volumen apunta a su propio subdirectorio de Google Drive (`NextCloud/Volume_1`, `NextCloud/Volume_2`, etc.) o a un `Folder ID` explícito.
  - Ruta de destino local configurada en dos partes: **Directorio común** (ej. `Gdrive`) + **Subdirectorio del volumen** (ej. `Volume_1`), generando `/Gdrive/Volume_1`, `/Gdrive/Volume_2`, etc.
  - Creación automática del directorio padre común (`/Gdrive`) en Nextcloud y creación automática opcional de subdirectorios faltantes en Google Drive.
- **Control por volumen**:
  - Modo Lectura/Escritura o Solo Lectura independiente.
  - Cuota de disco virtual configurable por volumen (ej. `50G`, `200G`, o espacio de la cuenta).
  - Exportación configurable de documentos nativos de Google Docs/Sheets/Slides (`.docx/.xlsx/.pptx`, `.pdf`, o ocultos).
  - Envío opcional a la papelera de Google Drive en lugar de borrado permanente.
- **Ámbito Administrador y Personal**:
  - En **Ajustes de Administración**: configura volúmenes globales visibles para todos los usuarios o restringidos a usuarios/grupos concretos.
  - En **Ajustes Personales** (si el admin lo permite): cada usuario puede conectar su propio Google Drive y montar sus volúmenes.
- **Rendimiento y CLI**:
  - Caché distribuida de metadatos y resolución de rutas con TTL configurable.
  - Subida resumible por bloques (`uploadType=resumable` en chunks de 5 MiB) para archivos grandes.
  - Comandos `occ` (`gdrive-volumes:list`, `gdrive-volumes:mount`, `gdrive-volumes:remove`, `gdrive-volumes:sync-cache`), compatibles con `occweb`.
  - Sin dependencias externas de Composer en producción (usa `OCP\Http\Client\IClientService` de Nextcloud).

---

## Instalación

1. Descarga o clona el repositorio en el directorio `custom_apps` de tu servidor Nextcloud con el nombre `gdrive_volumes`:

   ```bash
   cd /var/www/nextcloud/custom_apps
   git clone https://github.com/krofinski/nc-gdrive-volumes.git gdrive_volumes
   chown -R www-data:www-data gdrive_volumes
   ```

2. Activa la aplicación:

   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable gdrive_volumes
   ```

---

## Configuración en Google Cloud Console (OAuth 2.0)

1. Ve a [Google Cloud Console](https://console.cloud.google.com/) y crea o selecciona un proyecto.
2. En **APIs y servicios > Biblioteca**, habilita **Google Drive API**.
3. Configura la **Pantalla de consentimiento de OAuth** (Externa o Interna para Workspace) y añade el alcance `https://www.googleapis.com/auth/drive`.
4. En **Credenciales > Crear credenciales > ID de cliente de OAuth**:
   - Tipo de aplicación: **Aplicación web**.
   - **URI de redirección autorizado**: copia exactamente el valor mostrado en Nextcloud bajo *Ajustes > Google Drive Volumes* (normalmente `https://tu-nextcloud.tld/index.php/apps/gdrive_volumes/oauth/callback` o sin `index.php` si usas pretty URLs).
5. Introduce el `Client ID` y `Client Secret` en Nextcloud, pulsa **Save Credentials** y luego **Connect with Google Drive**.

---

## Ejemplo de uso: `NextCloud/Volume_1` y `NextCloud/Volume_2`

En **Ajustes > Google Drive Volumes**:

1. **Volumen 1**:
   - **Remote Path in Google Drive**: `NextCloud/Volume_1`
   - **Common Directory**: `Gdrive`
   - **Volume Subdirectory**: `Volume_1`
   - Resultado en Nextcloud Files: `/Gdrive/Volume_1`
2. **Volumen 2**:
   - **Remote Path in Google Drive**: `NextCloud/Volume_2`
   - **Common Directory**: `Gdrive`
   - **Volume Subdirectory**: `Volume_2`
   - Resultado en Nextcloud Files: `/Gdrive/Volume_2`

---

## Comandos OCC

```bash
# Listar todos los volúmenes configurados
php occ gdrive-volumes:list

# Montar un nuevo volumen desde CLI (compatible con OCCWeb)
php occ gdrive-volumes:mount \
  --scope=admin \
  --name="Volume 1" \
  --remote-path="NextCloud/Volume_1" \
  --local-parent="Gdrive" \
  --local-sub="Volume_1" \
  --auto-create \
  --quota=100G

# Limpiar caché, verificar carpetas remotas y crear directorios locales padre
php occ gdrive-volumes:sync-cache

# Eliminar un volumen por ID
php occ gdrive-volumes:remove <volume-id>
```

---

## Licencia

AGPL-3.0-or-later. Consulta el archivo [COPYING](COPYING).
