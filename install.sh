#!/bin/sh
# install.sh - automated OPNsense PPPoE HA cluster installer.
#
# Deploys this repository's runtime assets onto the local node, restarts the
# affected daemons, and installs the correct CARP syshook by detecting the
# node's role from its LAN address:
#
#   PRIMARY   = 192.168.20.253  -> installs 50-pppoe-failback
#   SECONDARY = 192.168.20.252  -> installs 50-pppoe-failover
#
# Usage (run as root on the node itself):
#   ./install.sh                       # auto-detect role from node IP
#   ./install.sh --role=primary        # force a role, skip IP detection
#
# Assets deployed:
#   scripts/pppoe_ha_patch.php       -> /usr/local/opnsense/scripts/
#   actions.d/actions_pppoe_ha.conf  -> /usr/local/opnsense/service/conf/actions.d/
#   syshooks/carp/50-pppoe-<role>    -> /usr/local/etc/rc.syshook.d/carp/
#
# Idempotent: re-running simply refreshes the copies.

set -eu

PRIMARY_IP=192.168.20.253
SECONDARY_IP=192.168.20.252

SRC_DIR=$(cd "$(dirname "$0")" && pwd)

SCRIPTS_DST=/usr/local/opnsense/scripts
ACTIONS_DST=/usr/local/opnsense/service/conf/actions.d
SYSHOOKS_DST=/usr/local/etc/rc.syshook.d/carp

die()  { printf 'install: ERROR: %s\n' "$1" >&2; exit 1; }
info() { printf 'install: %s\n' "$1"; }

[ "$(id -u)" -eq 0 ] || die "must be run as root"

# ------------------------------------------------------------------ argument
FORCE_ROLE=""
for arg in "$@"; do
    case "${arg}" in
        --role=*) FORCE_ROLE="${arg#--role=}" ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) die "unknown argument: ${arg}" ;;
    esac
done

# ------------------------------------------------------------ role detection
# Dynamic: pick the node's own LAN address out of its configured IPv4 set.
detect_role() {
    local_ips=$(ifconfig -a 2>/dev/null | awk '/inet /{print $2}')
    if printf '%s\n' "${local_ips}" | grep -qx "${PRIMARY_IP}"; then
        echo primary
    elif printf '%s\n' "${local_ips}" | grep -qx "${SECONDARY_IP}"; then
        echo secondary
    else
        echo unknown
    fi
}

ROLE="${FORCE_ROLE}"
if [ -z "${ROLE}" ]; then
    ROLE=$(detect_role)
fi

case "${ROLE}" in
    primary)   SYSHOOK=50-pppoe-failback;  PEER=50-pppoe-failover ;;
    secondary) SYSHOOK=50-pppoe-failover;  PEER=50-pppoe-failback ;;
    *)
        die "could not detect role (${PRIMARY_IP}=primary, ${SECONDARY_IP}=secondary); use --role=primary|secondary"
        ;;
esac
info "node role: ${ROLE} -> syshook ${SYSHOOK}"

# ------------------------------------------------------------------ validate
for f in \
    "${SRC_DIR}/scripts/pppoe_ha_patch.php" \
    "${SRC_DIR}/actions.d/actions_pppoe_ha.conf" \
    "${SRC_DIR}/syshooks/carp/${SYSHOOK}"
do
    [ -f "${f}" ] || die "missing source asset: ${f}"
done

# -------------------------------------------------------------------- deploy
info "deploying runtime assets"
install -d -m 0755 "${SYSHOOKS_DST}"

install -m 0755 "${SRC_DIR}/scripts/pppoe_ha_patch.php" \
    "${SCRIPTS_DST}/pppoe_ha_patch.php"
install -m 0644 "${SRC_DIR}/actions.d/actions_pppoe_ha.conf" \
    "${ACTIONS_DST}/actions_pppoe_ha.conf"
install -m 0755 "${SRC_DIR}/syshooks/carp/${SYSHOOK}" \
    "${SYSHOOKS_DST}/${SYSHOOK}"

# The opposite node's hook must not linger on this node.
if [ -e "${SYSHOOKS_DST}/${PEER}" ]; then
    info "removing stale peer hook ${PEER} (belongs on the other node)"
    rm -f "${SYSHOOKS_DST}/${PEER}"
fi

info "installed ${SYSHOOKS_DST}/${SYSHOOK}"

# ------------------------------------------------------------- daemon restart
info "restarting configd"
service configd restart

info "done. next: configctl pppoe_ha apply && configctl filter reload"
