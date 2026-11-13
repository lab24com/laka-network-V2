# LAKA Network — widget para Zabbix 7.0 – 8.0

Consola de equipos de red para dashboards de Zabbix: panel frontal, interfaces, topología CDP/LLDP, localizador MAC/IP, consumo, ópticas SFP, PoE y salud del hardware. Detecta automáticamente todas las plantillas oficiales de Cisco de Zabbix 7.0 a 8.0 y es compatible con cualquier plantilla basada en IF-MIB (FortiGate, genéricos).

## Instalación MODULO
1. Copiar la carpeta del módulo a `/usr/share/zabbix/modules/laka-network-switch` (en 7.2+ puede ser `/usr/share/zabbix/ui/modules/...` según el paquete).
2. Ajustar propietario y SELinux:

   ```bash
   chown -R root:root /usr/share/zabbix/modules/laka-network-switch
   restorecon -Rv /usr/share/zabbix/modules/laka-network-switch
   ```

3. **Administración → General → Módulos → Escanear directorio** y habilitar **Network**.
4. Agregar el widget **Network** al dashboard y elegir grupos de hosts o hosts.

> **Al actualizar**: elimine la carpeta anterior del módulo antes de copiar la nueva, vuelva a escanear el directorio de módulos y recargue el navegador con **Ctrl+F5** (o vacíe la caché). Si ve el aviso "El script del widget no se inició", el navegador sigue usando el JS anterior.

## Instalación Extras - tablas MAC y ARP por SNMP

Se hace en el **servidor o proxy de Zabbix que monitorea los switches**, no en los switches.

1. Instale las herramientas de net-snmp:

   ```bash
   dnf install net-snmp-utils      # RHEL / Rocky / Alma
   apt install snmp                # Debian / Ubuntu
   ```

2. Copie el script al directorio de verificaciones externas (parámetro `ExternalScripts` de `zabbix_server.conf` o `zabbix_proxy.conf`; en los paquetes oficiales es `/usr/lib/zabbix/externalscripts`):

   ```bash
   cp laka_fdb.sh /usr/lib/zabbix/externalscripts/
   chmod 755 /usr/lib/zabbix/externalscripts/laka_fdb.sh
   ```

Se hace en el **servidor o proxy de Zabbix que monitorea los switches**, no en los switches.

1. Instale las herramientas de net-snmp:

   ```bash
   dnf install net-snmp-utils      # RHEL / Rocky / Alma
   apt install snmp                # Debian / Ubuntu
   ```

2. Copie el script al directorio de verificaciones externas (parámetro `ExternalScripts` de `zabbix_server.conf` o `zabbix_proxy.conf`; en los paquetes oficiales es `/usr/lib/zabbix/externalscripts`):

   ```bash
   cp laka_fdb.sh /usr/lib/zabbix/externalscripts/
   chmod 755 /usr/lib/zabbix/externalscripts/laka_fdb.sh
   ```
## Importar Plantilla ópticas SFP y PoE por SNMP

1. Importe `template_laka_network_optics_poe_snmp.yaml` en **Recolección de datos → Plantillas → Importar**.

## Importar template_laka_network_mac_table_snmp

1. Importe `template_laka_network_mac_table_snmp.yaml` en **Recolección de datos → Plantillas → Importar**.

## Importar template_laka_cisco_topology_snmp

1. Importe `template_laka_cisco_topology_snmp.yaml` en **Recolección de datos → Plantillas → Importar**.

Agregar todos los equipos de red que seran monitoreados

## Compatibilidad

| Zabbix | Estado |
|---|---|
| 7.0 LTS | Compatible |
| 7.2 / 7.4 | Compatible |
| 8.0 | Compatible (verificado contra 8 Lts) |

### Plantillas Cisco detectadas automáticamente

Con el perfil **Automático** (por defecto) el widget reconoce las claves de todas las plantillas oficiales de [zabbix.com/integrations/cisco](https://www.zabbix.com/integrations/cisco); no hace falta editar claves.

| Plantilla oficial | Interfaces | CPU / memoria | Hardware | Sistema |
|---|---|---|---|---|
| Cisco IOS by SNMP (IOS / IOS-XE) | ✔ (nombre y alias desde el nombre del ítem) | ✔ | Temperatura (+ estado), ventiladores, fuentes | Modelo, N/S, versión, uptime, ICMP |
| Cisco IOS versions 12.0_3_T-12.2_3.5 by SNMP | ✔ | ✔ | ✔ | ✔ |
| Cisco IOS prior to 12.0_3_T by SNMP (+ Network interfaces by SNMP) | ✔ (contadores de 32 bits) | ✔ (`avgBusy5`) | ✔ | ✔ |
| Cisco Catalyst 3750V2-24FS/24PS/24TS/48PS/48TS by SNMP | ✔ (claves numéricas) | ✔ | ✔ | ✔ |
| Cisco Nexus 9000 Series by SNMP | ✔ | ✔ | ✔ (CISCO-ENTITY-FRU / ENTITY-SENSOR) | ✔ |
| Cisco ASAv by SNMP | ✔ | ✔ | — | Modelo, N/S, versión ASA, uptime |
| Cisco SD-WAN device by HTTP | ✔ | ✔ | — | Modelo, N/S, versión, uptime |
| Cisco Secure Firewall Threat Defense by HTTP | ✔ (estado y tráfico) | ✔ | Temperatura, ventiladores | Modelo, N/S, versión, uptime |

No aplica a *Cisco SD-WAN by HTTP* (controlador vManage que crea los hosts de dispositivo) ni a las plantillas UCS (servidores, en `templates/server`). Los hosts de dispositivo que crea la plantilla SD-WAN sí se muestran.

Si un host tiene una plantilla personalizada, escriba sus claves en **Claves de ítems**: se prueban antes que las oficiales. Para usar solo esas claves, elija el perfil *Solo las claves de abajo*.

## Configuración

El formulario está dividido en secciones colapsables.

- **Perfil de plantilla**: *Automático* (por defecto) prueba primero las claves de la sección **Claves de ítems** y después las de todas las plantillas oficiales (Cisco, IF-MIB genérico, FortiGate). Las variantes *Automático, primero …* cambian solo la prioridad. *Solo las claves de abajo* desactiva la detección.
- **Umbrales de utilización**: advertencia y crítico (por defecto 60 % y 80 %).
- **Filtros de interfaces**: expresiones regulares de inclusión y exclusión (se validan al guardar).

### Patrones de claves

Cada patrón de interfaz debe tener exactamente un `*`, que representa `{#SNMPINDEX}`:

```text
net.if.status[ifOperStatus.*]
net.if.adminstatus[ifAdminStatus.*]
net.if.name[ifName.*]
net.if.alias[ifAlias.*]
net.if.speed[ifHighSpeed.*]
net.if.in[ifHCInOctets.*]
net.if.out[ifHCOutOctets.*]
```

En las plantillas HTTP (SD-WAN, FTD) la clave usa el nombre de la interfaz entre comillas; escriba `"*"`, por ejemplo `sd_wan.device.if.status["*"]`.

Opcionales: VLAN de acceso, estado y potencia PoE, `ifLastChange` (muestra hace cuánto cambió el estado del puerto, útil para encontrar puertos sin uso).

Los patrones de modelo, CPU, memoria, uptime y ubicación aceptan `*` como comodín.

## Idioma

En la configuración del widget, campo **Idioma**:

| Opción | Resultado |
|---|---|
| Automático | Sigue el idioma del usuario de Zabbix: español (`es_*`), portugués (`pt_*`) o inglés para los demás. |
| English / Español / Português (Brasil) | Fija el idioma del widget para todos los usuarios del dashboard. |

El idioma se aplica a la consola, la topología, las ventanas, las gráficas y los formatos de fecha. Los nombres de problemas, triggers e ítems se muestran como vienen de Zabbix. Las etiquetas del formulario de configuración siguen el idioma guardado en el widget (después de guardarlo una vez).

Si quieres algo Personalizaod puedes escribirme:
 ```bash
© LAKA Soluciones Tecnologicas
· Autor: Willian Tola
· Contactos:  +591 70806592
```

Licencia GPLv3
