# Mautic Apollo Plugin (Mautic 7)

## Requisitos
- Mautic 7.x
- PHP 8.2+
- Acceso a la API Key de Apollo

## Configuracion
1. Ir a **Settings -> Integrations -> Apollo**.
2. Cargar la **API Key** y guardar.
3. Ir a **Settings -> Plugins** y habilitar el plugin **Mautic Apollo**.

## Campos personalizados recomendados
Para mantener la relacion entre Mautic y Apollo (upsert correcto), crear:

### Contactos
- Campo: `apollo_contact_id`
- Tipo: Texto

### Empresas
- Campo: `apollo_company_id`
- Tipo: Texto

Ruta sugerida:
- **Contacts -> Custom Fields**
- **Companies -> Custom Fields**

## Comandos utiles
Ejecutar pull manual:
```
php bin/console mautic:apollo:pull
```

Reintentar cola:
```
php bin/console mautic:apollo:retry
```

## Cron recomendado
Ejecutar 1 vez por dia (limite API):
```
php /var/www/mautic-new/bin/console mautic:apollo:pull   # 02:00
php /var/www/mautic-new/bin/console mautic:apollo:retry  # 02:10
```

## Notas
- Si `mautic:apollo:pull` responde "Apollo integration not configured", revisar API Key.
- Si la sincronizacion no crea empresas, verificar que exista el campo `apollo_company_id`.

## Limites y cursor
- El pull esta limitado a 100 contactos por corrida.
- Se guarda un cursor en la tabla `apollo_sync_state` para no re-sincronizar todo.
- En logs se registra `count`, `limit` y `last_ts`.
