# Apollo.io ↔ Mautic Plugin (beta skeleton)

Plugin de Mautic (type `mautic-plugin`) para sincronizar contactos y empresas con Apollo.io.

## Instalación rápida
1. Copiar carpeta `plugins/ApolloMauticBundle` dentro de la instancia de Mautic o instalar vía Composer:
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

## Pendiente
- Mapeo completo Lead/Company en SyncService.
- Manejo de rate limit 429 y backoff en QueueService.
- UI de mapping de campos y opt-out.
