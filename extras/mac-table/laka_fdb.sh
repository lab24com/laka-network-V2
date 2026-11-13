#!/bin/bash
#
# LAKA Network - recolector de tablas MAC (SNMP, solo lectura).
# Verificación externa de Zabbix usada por la plantilla "LAKA Network MAC table by SNMP".
#
#   laka_fdb.sh vlans  HOST COMUNIDAD V3OPTS            Descubrimiento de VLAN (JSON LLD)
#   laka_fdb.sh fdb    HOST COMUNIDAD V3OPTS VLAN       Tabla MAC de una VLAN
#   laka_fdb.sh arpifs HOST COMUNIDAD V3OPTS            Interfaces con entradas ARP (JSON LLD)
#   laka_fdb.sh arp    HOST COMUNIDAD V3OPTS IFINDEX    Tabla ARP (IP -> MAC) de una interfaz L3
#   laka_fdb.sh lag    HOST COMUNIDAD V3OPTS            Miembros de port-channel (PAgP / LACP)
#
# SNMPv2c: la COMUNIDAD se usa tal cual; para la tabla MAC de la VLAN N, Cisco necesita "COMUNIDAD@N" (indexación
# por comunidad), que arma este script. SNMPv3: V3OPTS contiene las opciones de snmp, por ejemplo
#   -v3 -l authPriv -u zabbix -a SHA -A claveauth -x AES -X clavepriv
# y se agrega el contexto "vlan-N" en cada VLAN. Si V3OPTS está vacío se usa SNMPv2c.
#
# Requiere las herramientas de línea de comandos de net-snmp (snmpbulkwalk): dnf install net-snmp-utils / apt install snmp
# La salida es texto compacto (ver README) para quedar muy por debajo del límite de 64 KB de los valores de texto de Zabbix.
#
# LAKA Network – módulo de red para Zabbix 7.0 – 8.0
# © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3

set -u
LC_ALL=C
export LC_ALL

MODE=${1:-}
HOST=${2:-}
COMM=${3:-public}
V3=${4:-}
ARG=${5:-}
BIN=${LAKA_SNMPBULKWALK:-snmpbulkwalk}
TIMEOUT=${LAKA_SNMP_TIMEOUT:-3}
RETRIES=${LAKA_SNMP_RETRIES:-1}
VERSION=1

OID_VTP_STATE=1.3.6.1.4.1.9.9.46.1.3.1.1.2
OID_VTP_NAME=1.3.6.1.4.1.9.9.46.1.3.1.1.4
OID_QBRIDGE_VLAN_NAME=1.3.6.1.2.1.17.7.1.4.3.1.1
OID_BASEPORT_IFINDEX=1.3.6.1.2.1.17.1.4.1.2
OID_FDB_PORT=1.3.6.1.2.1.17.4.3.1.2
OID_FDB_STATUS=1.3.6.1.2.1.17.4.3.1.3
OID_QFDB_PORT=1.3.6.1.2.1.17.7.1.2.2.1.2
OID_QFDB_STATUS=1.3.6.1.2.1.17.7.1.2.2.1.3
OID_ARP_MAC=1.3.6.1.2.1.4.22.1.2
OID_ARP_TYPE=1.3.6.1.2.1.4.22.1.4
OID_IFNAME=1.3.6.1.2.1.31.1.1.1.1
OID_PAGP_GROUP=1.3.6.1.4.1.9.9.98.1.1.1.1.8
OID_LACP_AGG=1.2.840.10006.300.43.1.2.1.1.13

fail() {
	echo "LAKAFDB${VERSION};${MODE};error=$1"
	exit 1
}

# Primer error que imprimió snmpbulkwalk (timeout, usuario desconocido, autenticación...), guardado para el mensaje de error.
SNMP_ERR=""
ERRFILE=$(mktemp 2>/dev/null || echo "/tmp/laka_fdb.$$")
trap 'rm -f "$ERRFILE"' EXIT

fail_snmp() {
	local detail=${SNMP_ERR:-no answer}
	if [ -z "$V3" ]; then
		detail="$detail - check {\$SNMP_COMMUNITY} of the host and the SNMP access list of the equipment"
	else
		detail="$detail - check {\$LAKA.FDB.SNMPV3}"
	fi
	fail "no SNMP response${1:+ $1}: $detail"
}

# walk VLAN OID [opciones snmp adicionales]. VLAN vacía o 0 = contexto por defecto.
# Deja WALK_OUT (líneas "OID valor") y WALK_RC (código de salida de snmpbulkwalk); se ejecuta en el shell actual.
WALK_OUT=""
WALK_RC=0
walk() {
	local vlan=$1 oid=$2
	shift 2
	local out
	if [ -n "$V3" ]; then
		local ctx=()
		if [ -n "$vlan" ] && [ "$vlan" != "0" ]; then ctx=(-n "vlan-$vlan"); fi
		# V3OPTS se separa en palabras a propósito.
		# shellcheck disable=SC2086
		out=$("$BIN" $V3 ${ctx[@]+"${ctx[@]}"} -On -Oq -Oe -t "$TIMEOUT" -r "$RETRIES" "$@" "$HOST" "$oid" 2>"$ERRFILE")
	else
		local community=$COMM
		if [ -n "$vlan" ] && [ "$vlan" != "0" ]; then community="$COMM@$vlan"; fi
		out=$("$BIN" -v2c -c "$community" -On -Oq -Oe -t "$TIMEOUT" -r "$RETRIES" "$@" "$HOST" "$oid" 2>"$ERRFILE")
	fi
	WALK_RC=$?
	if [ "$WALK_RC" -ne 0 ] && [ -z "$SNMP_ERR" ] && [ -s "$ERRFILE" ]; then
		SNMP_ERR=$(head -n 1 "$ERRFILE" | tr -d '\r;' | cut -c1-160)
	fi
	# Descarta las respuestas "No Such Object/Instance" y "No more variables".
	WALK_OUT=$(printf '%s\n' "$out" | awk 'NF >= 2 && $2 != "No" && $0 !~ /No more variables/')
}

json_names() { # stdin: líneas "id<TAB>nombre"; $1 macro del id, $2 macro del nombre
	awk -F '\t' -v a="$1" -v b="$2" '
		{ n = $2; gsub(/\\/, "\\\\", n); gsub(/"/, "\\\"", n);
		  out = out (out == "" ? "" : ",") "{\"" a "\":\"" $1 "\",\"" b "\":\"" n "\"}" }
		END { print "[" out "]" }'
}

[ -n "$HOST" ] || fail "missing host"
command -v "$BIN" >/dev/null 2>&1 || fail "snmpbulkwalk not installed on this Zabbix server/proxy (net-snmp-utils / snmp)"
# Una macro de usuario que Zabbix no pudo resolver llega literal, por ejemplo "{$SNMP_COMMUNITY}".
case "$COMM$V3" in
	*'{$'*) fail "user macro not defined on the host or template: $(printf '%s %s' "$COMM" "$V3" | grep -o '{\$[A-Z0-9_.]*}' | head -n 1)" ;;
esac

case "$MODE" in
test)
	# Prueba de conectividad desde el servidor/proxy de Zabbix: sysName y los datos que usaría cada modo.
	walk "" 1.3.6.1.2.1.1.5
	[ -n "$WALK_OUT" ] || fail_snmp
	echo "sysName: $(printf '%s\n' "$WALK_OUT" | head -n 1 | cut -d' ' -f2- | tr -d '"')"
	walk "" "$OID_VTP_STATE"; echo "Active VLANs (CISCO-VTP-MIB): $(printf '%s\n' "$WALK_OUT" | awk '$2 == "1"' | grep -c . )"
	# Primera VLAN activa distinta de 1 (la VLAN 1 suele estar vacía); si no hay, la VLAN 1.
	first=$(printf '%s\n' "$WALK_OUT" | awk '$2 == "1" { n = split($1, a, "."); v = a[n]; if (v > 1 && (v < 1002 || v > 1005)) { print v; found = 1; exit } if (v == 1) one = 1 } END { if (!found && one) print 1 }')
	if [ -n "$first" ]; then
		walk "$first" "$OID_FDB_PORT"
		echo "MAC table VLAN $first (${V3:+context vlan-$first}${V3:-community@$first}): $(printf '%s\n' "$WALK_OUT" | grep -c . ) entries${SNMP_ERR:+ - $SNMP_ERR}"
	fi
	walk "" "$OID_QFDB_PORT"; echo "Q-BRIDGE MAC table: $(printf '%s\n' "$WALK_OUT" | grep -c . ) entries"
	walk "" "$OID_ARP_MAC" -Ox; echo "ARP entries: $(printf '%s\n' "$WALK_OUT" | grep -c . )"
	walk "" "$OID_PAGP_GROUP"; echo "Port-channel members (PAgP/LACP): $(printf '%s\n' "$WALK_OUT" | grep -c . )"
	;;

vlans)
	walk "" "$OID_VTP_STATE"; states=$WALK_OUT
	rc=$WALK_RC
	if [ -n "$states" ]; then
		walk "" "$OID_VTP_NAME"; names=$WALK_OUT
		{ printf '%s\n' "$names" | sed 's/^/N /'; printf '%s\n' "$states" | sed 's/^/S /'; } | awk '
			function last(oid,   a, n) { n = split(oid, a, "."); return a[n] }
			$1 == "N" { v = $0; sub(/^N [^ ]+ /, "", v); gsub(/^"|"$/, "", v); name[last($2)] = v; next }
			$1 == "S" && $3 == "1" { id = last($2); if (id < 1002 || id > 1005) ok[id] = 1 }
			END { for (id in ok) printf "%s\t%s\n", id, (id in name ? name[id] : "VLAN" id) }' \
			| sort -n | json_names '{#LAKA.VLAN}' '{#LAKA.VLAN.NAME}'
		exit 0
	fi
	walk "" "$OID_QBRIDGE_VLAN_NAME"; names=$WALK_OUT
	if [ -n "$names" ]; then
		printf '%s\n' "$names" | awk '
			{ n = split($1, a, "."); id = a[n]; v = $0; sub(/^[^ ]+ /, "", v); gsub(/^"|"$/, "", v); printf "%s\t%s\n", id, v }' \
			| sort -n | json_names '{#LAKA.VLAN}' '{#LAKA.VLAN.NAME}'
		exit 0
	fi
	[ "$rc" -eq 0 ] || fail_snmp
	# Sin información de VLAN: una sola tabla para el contexto por defecto.
	printf '%s\t%s\n' 0 all | json_names '{#LAKA.VLAN}' '{#LAKA.VLAN.NAME}'
	;;

fdb)
	vlan=${ARG:-0}
	case "$vlan" in *[!0-9]*) fail "invalid vlan" ;; esac
	src=bridge
	walk "$vlan" "$OID_BASEPORT_IFINDEX"; ports=$WALK_OUT
	rc1=$WALK_RC
	walk "$vlan" "$OID_FDB_PORT"; fdbp=$WALK_OUT
	rc2=$WALK_RC
	fdbs=""
	if [ -n "$fdbp" ]; then
		walk "$vlan" "$OID_FDB_STATUS"; fdbs=$WALK_OUT
	elif [ "$vlan" != "0" ]; then
		# Q-BRIDGE-MIB (una consulta para todas las VLAN; en la mayoría de equipos el FDB id = VLAN id).
		walk "" "$OID_QFDB_PORT.$vlan"; qp=$WALK_OUT
		if [ -n "$qp" ]; then
			src=qbridge
			fdbp=$qp
			walk "" "$OID_QFDB_STATUS.$vlan"; fdbs=$WALK_OUT
			walk "" "$OID_BASEPORT_IFINDEX"; ports=$WALK_OUT
		fi
	fi
	if [ -z "$fdbp" ] && [ "$rc1" -ne 0 ] && [ "$rc2" -ne 0 ]; then fail_snmp "for vlan $vlan"; fi
	{
		printf '%s\n' "$ports" | sed 's/^/P /'
		printf '%s\n' "$fdbp" | sed 's/^/F /'
		printf '%s\n' "$fdbs" | sed 's/^/S /'
	} | awk -v vlan="$vlan" -v src="$src" -v ver="$VERSION" '
		function mac(oid,   a, n, i, s) { n = split(oid, a, "."); s = ""; for (i = n - 5; i <= n; i++) s = s sprintf("%02x", a[i]); return s }
		$1 == "P" { n = split($2, a, "."); if ($3 ~ /^[0-9]+$/) map[a[n]] = $3; next }
		$1 == "F" { if ($3 ~ /^[0-9]+$/) port[mac($2)] = $3; next }
		$1 == "S" { if ($3 ~ /^[0-9]+$/) st[mac($2)] = $3; next }
		END {
			count = 0
			for (m in port) {
				s = (m in st) ? st[m] : 3
				# dot1dTpFdbStatus: se conservan other(1) y learned(3); invalid(2), self(4) y mgmt(5) no son equipos finales.
				if (s != 1 && s != 3) continue
				bp = port[m]
				if (bp == 0) continue
				ifx = (bp in map) ? map[bp] : "b" bp
				list[ifx] = list[ifx] (list[ifx] == "" ? "" : ",") m
				count++
			}
			printf "LAKAFDB%s;fdb;vlan=%s;src=%s;n=%d\n", ver, vlan, src, count
			for (i in list) print i ":" list[i]
		}'
	;;

arpifs)
	walk "" "$OID_ARP_MAC" -Ox; arp=$WALK_OUT
	if [ -z "$arp" ]; then
		[ "$WALK_RC" -eq 0 ] || fail_snmp
		echo "[]"
		exit 0
	fi
	walk "" "$OID_IFNAME"; ifnames=$WALK_OUT
	# Nombres de VLAN para las SVI (Vl1021 -> nombre de la VLAN 1021).
	walk "" "$OID_VTP_NAME"; vnames=$WALK_OUT
	[ -n "$vnames" ] || { walk "" "$OID_QBRIDGE_VLAN_NAME"; vnames=$WALK_OUT; }
	{
		printf '%s\n' "$ifnames" | sed 's/^/N /'
		printf '%s\n' "$vnames" | sed 's/^/V /'
		printf '%s\n' "$arp" | sed 's/^/A /'
	} | awk '
		function val(   v) { v = $0; sub(/^[A-Z] [^ ]+ /, "", v); gsub(/^"|"$/, "", v); return v }
		$1 == "N" { n = split($2, a, "."); name[a[n]] = val(); next }
		$1 == "V" { n = split($2, a, "."); vlan[a[n]] = val(); next }
		$1 == "A" { n = split($2, a, "."); ok[a[n - 4]] = 1 }
		END {
			for (i in ok) {
				ifname = (i in name) ? name[i] : "if" i
				vname = ""
				if (match(ifname, /[0-9]+$/) && ifname ~ /^[Vv][Ll]([Aa][Nn])?[0-9]+$/) {
					id = substr(ifname, RSTART)
					if (id in vlan) vname = vlan[id]
				}
				printf "%s\t%s\t%s\n", i, ifname, vname
			}
		}' \
		| sort -n | awk -F '\t' '
			function esc(x) { gsub(/\\/, "\\\\", x); gsub(/"/, "\\\"", x); return x }
			{ out = out (out == "" ? "" : ",") "{\"{#LAKA.ARP.IFINDEX}\":\"" $1 "\",\"{#LAKA.ARP.IFNAME}\":\"" esc($2) "\",\"{#LAKA.ARP.VLAN.NAME}\":\"" esc($3) "\"}" }
			END { print "[" out "]" }'
	;;

arp)
	ifx=${ARG:-}
	case "$ifx" in ''|*[!0-9]*) fail "invalid ifindex" ;; esac
	walk "" "$OID_ARP_MAC.$ifx" -Ox; arp=$WALK_OUT
	rc=$WALK_RC
	[ -n "$arp" ] || [ "$rc" -eq 0 ] || fail_snmp
	walk "" "$OID_ARP_TYPE.$ifx"; types=$WALK_OUT
	{ printf '%s\n' "$types" | sed 's/^/T /'; printf '%s\n' "$arp" | sed 's/^/A /'; } | awk -v ifx="$ifx" -v ver="$VERSION" '
		function ipkey(oid,   a, n) { n = split(oid, a, "."); return a[n - 3] "." a[n - 2] "." a[n - 1] "." a[n] }
		$1 == "T" { type[ipkey($2)] = $3; next }
		$1 == "A" {
			ip = ipkey($2)
			v = $0; sub(/^A [^ ]+ /, "", v); gsub(/"/, "", v); gsub(/^ +| +$/, "", v)
			k = split(v, b, /[ :.-]+/); m = ""
			if (k == 6) { for (i = 1; i <= 6; i++) { h = tolower(b[i]); if (length(h) == 1) h = "0" h; m = m h } }
			else if (k == 3) { for (i = 1; i <= 3; i++) m = m tolower(b[i]) }
			else if (k == 1 && length(v) == 12) m = tolower(v)
			if (length(m) != 12 || m == "000000000000" || m == "ffffffffffff") next
			mac[ip] = m
		}
		END {
			count = 0
			for (ip in mac) {
				if ((ip in type) && type[ip] == 2) continue
				n = split(ip, o, ".")
				p = o[1] "." o[2] "." o[3]
				list[p] = list[p] (list[p] == "" ? "" : ",") o[4] "=" mac[ip]
				count++
			}
			printf "LAKAFDB%s;arp;if=%s;n=%d\n", ver, ifx, count
			for (p in list) print p ":" list[p]
		}'
	;;

lag)
	walk "" "$OID_PAGP_GROUP"; pagp=$WALK_OUT
	rc1=$WALK_RC
	walk "" "$OID_LACP_AGG"; lacp=$WALK_OUT
	rc2=$WALK_RC
	if [ -z "$pagp" ] && [ -z "$lacp" ] && [ "$rc1" -ne 0 ] && [ "$rc2" -ne 0 ]; then fail_snmp; fi
	ifnames=""
	if [ -n "$pagp$lacp" ]; then walk "" "$OID_IFNAME"; ifnames=$WALK_OUT; fi
	{
		printf '%s\n' "$ifnames" | sed 's/^/N /'
		printf '%s\n' "$pagp" | sed 's/^/G /'
		printf '%s\n' "$lacp" | sed 's/^/G /'
	} | awk -v ver="$VERSION" '
		$1 == "N" { n = split($2, a, "."); v = $0; sub(/^N [^ ]+ /, "", v); gsub(/^"|"$/, "", v); name[a[n]] = v; next }
		$1 == "G" {
			n = split($2, a, "."); member = a[n]; agg = $3
			if (agg !~ /^[0-9]+$/ || agg == 0 || agg == member) next
			key = agg SUBSEP member
			if (key in seen) next
			seen[key] = 1
			members[agg] = members[agg] (members[agg] == "" ? "" : ",") member
		}
		END {
			count = 0
			for (g in members) count++
			printf "LAKAFDB%s;lag;n=%d\n", ver, count
			for (g in members) print g ";" (g in name ? name[g] : "") ";" members[g]
		}'
	;;

*)
	echo "Usage: $0 test|vlans|fdb|arpifs|arp|lag HOST COMMUNITY [V3OPTS] [VLAN|IFINDEX]" >&2
	fail "invalid mode"
	;;
esac
