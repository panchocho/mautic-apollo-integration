# Apollo.io ↔ Mautic Plugin (beta skeleton)

Plugin de Mautic (type `mautic-plugin`) para sincronizar contactos y empresas con Apollo.io.

## Instalación rápida
1. Copiar carpeta `plugins/MauticApolloBundle` dentro de la instancia de Mautic o instalar vía Composer:
   ```bash
   composer require pancho/mautic-apollo-plugin:0.1.0
   php bin/console cache:clear
   ```
2. En Mautic > *Plugins* activar "Apollo.io" y añadir API Key.
3. Programar cronjobs:
   - `php bin/console mautic:apollo:pull` (cada 10 min)
   - `php bin/console mautic:apollo:retry` (cada 1-5 min)

## Endpoints
- `GET /plugin/apollo/webhook/test` – healthcheck.
- `POST /plugin/apollo/push` – encola payloads manuales para reintento.

## Paridad con flujos comunes en Zapier
- **Create/Update Contact** (Mautic → Apollo) vía eventos de formularios y actualizaciones de contacto.
- **Create/Update Company** (Apollo → Mautic) cuando llega información de organización/dominio.
- **Unsubscribe/Opt-out** sincronizado desde Mautic hacia Apollo.
- **Pull incremental de contactos** (Apollo → Mautic) equivalente a \"New/Updated Contact\" trigger.
- **Paginación + cursor** en pulls continuos (se guarda `last_sync_ts`).
- **Rate limit handling**: reintento en cola ante respuesta 429 de Apollo.
- **Backoff progresivo**: la cola reintenta con ventana creciente hasta 5 intentos.

## Pendiente
- Mapeo avanzado (custom fields, tags/segmentos) y opt-out UI.
- Publicar en Marketplace / empaquetar con icono y traducciones completas.

## Migraciones
- Ejecutar las migraciones del plugin para añadir `attempts` y `next_attempt_at` en la cola:
  ```bash
  php bin/console doctrine:migrations:migrate --prefix="Apollo\\MauticBundle\\Migrations"
  ```

## Campos recomendados
Crea dos campos personalizados en Mautic para mejor upsert/dedupe:
- Lead field `apollo_contact_id` (texto) para guardar el ID de contacto en Apollo.
- Company field `apollo_company_id` (texto) para guardar el ID de organización en Apollo.

Si los campos no existen, el plugin sigue funcionando por email/dominio, pero no podrá reusar IDs en los upserts.
