# opnsense-pppoe-ha

CARP High Availability for OPNsense® when the WAN is **PPPoE assigned directly
to an interface** — i.e. there is **no WAN CARP VIP**. This project provides
upgrade-safe CARP syshooks, a native configuration patch engine and configd
hooks that make PPPoE **failover** and **failback** work automatically on a
2-node cluster behind a Zyxel GM4100-B0 (or any ISP that delivers PPPoE on a
tagged VLAN).

> Field-tested on OPNsense (FreeBSD), dual-node, `igc` NICs, PPPoE over VLAN.

## Table of contents

1. [The problem](#the-problem)
2. [Architecture](#architecture)
3. [Zyxel GM4100-B0 context](#zyxel-gm4100-b0-context)
4. [Components](#components)
5. [Installation](#installation)
6. [Usage](#usage)
7. [Verification](#verification)
8. [Troubleshooting](#troubleshooting)
9. [License](#license)

## The problem

OPNsense's built-in HA failover moves **CARP VIPs** between nodes. When a
client's gateway is a CARP VIP *and* the WAN also holds a CARP VIP, failover is
transparent. But when the WAN is **PPPoE**, the WAN cannot hold a CARP VIP:
PPPoE is a point-to-point session, the peer assigns your address dynamically,
and typically only one session per subscriber is permitted. Consequently:

* The stock `20-ppp` CARP syshook **never fires** — it keys off a CARP event
  whose parent is the PPP `ports` device, which does not exist in this design.
* On takeover the backup node does **not** know it must re-dial PPPoE.
* The backup's default gateway may still point at the *old* master's LAN VIP,
  so even after the VIP moves the node routes "to itself" and has no internet.
* ISP-side anti-abuse may reject a re-dial if the WAN source MAC changed.

This project solves all four with a small, idempotent set of native OPNsense
configuration changes plus two CARP syshooks.

## Architecture

| Role | Address | Notes |
|------|---------|-------|
| Primary LAN | `192.168.20.253` | CARP `advskew 0` |
| Secondary LAN | `192.168.20.252` | CARP `advskew 100` |
| LAN CARP VIP (vhid 1) | `192.168.20.254` | client gateway |
| pfsync / sync link | `10.0.99.1` ↔ `10.0.99.2` | back-to-back wire |
| WAN (PPP+EF) | `pppoe0` | PPPoE over VLAN 2 (`vlan0.1.7` on `igc0`) |

```
        ┌──────────────┐         pfsync / XMLRPC sync        ┌──────────────┐
        │   PRIMARY    │  10.0.99.1 ══════════════ 10.0.99.2  │  SECONDARY   │
        │ .253 / master│                                      │ .252 / backup│
        └───┬──────┬───┘                                      └───┬──────┬───┘
            │igc1  │igc0(VLAN2 → PPPoE)                          │igc1  │igc0
            │      │                                              │      │
        ┌───┴──────┴───────────────  LAN  ─────────────────────┴──────┴───┐
        │            CARP VIP 192.168.20.254  (client gateway)            │
        └───────────────────────────────┬─────────────────────────────────┘
                                         │
                                 Zyxel GM4100-B0
                              (ISP ONT/router, PPPoE on VLAN 2)
                                         │
                                     Internet
```

Both nodes share **one** LAN CARP VIP and pfsync over the dedicated link. There
is no WAN VIP — the WAN is a per-node PPPoE session that must be dialled only by
whichever node currently holds the LAN VIP.
## Zyxel GM4100-B0 context

The **Zyxel GM4100-B0** is an ISP-supplied gateway (ONT/router) used by several
European fibre providers. Used as designed it routes and NATs on its own — but
this project assumes it is placed in **bridge / modem mode** so that OPNsense
terminates the PPPoE session itself. In that mode:

* The GM4100-B0 presents the WAN as a **VLAN-tagged** Ethernet link. In our
  reference deployment that is **VLAN 2, priority 0**, surfacing in OPNsense as
  `vlan0.1.7` (tag 2 on `igc0`).
* OPNsense dials **PPPoE on top of that VLAN** with the ISP credentials
  (`<ppps><ppp><ports>vlan0.1.7</ports>…`).
* Because PPPoE terminates on the nodes, the ISP sees **the node's source MAC**.
  Anti-abuse/duplicate-session rules at the ISP can therefore block a takeover
  if the node presents a different MAC — mitigate by cloning the active WAN MAC
  onto the backup (`<wan><spoofmac>…</spoofmac>`).
* The ISP generally permits **one PPPoE session per subscriber**, which is why
  `disconnect dialup interfaces` matters: the backup must not hold a session
  while the master is alive.

Keep the GM4100-B0 in bridge mode, leave the WAN VLAN tag (2) intact, and use
the **same PPPoE credentials on both nodes**.

## Components

### 1. CARP syshooks — `/usr/local/etc/rc.syshook.d/carp/`

`devd` calls these as `rc.syshook carp <vhid>@<iface> <MASTER|BACKUP|INIT>`.

| File | Node | Behaviour |
|------|------|-----------|
| `50-pppoe-failover` | Secondary | LAN→**MASTER**: dial `configctl interface reconfigure wan`; then a **backgrounded** tracker pings `1.1.1.1` every 2 s and logs the total unattended recovery delay to `/var/log/pppoe_failover.log`. LAN→BACKUP/INIT: suspend WAN. |
| `50-pppoe-failback` | Primary | Mirror image for the Primary reclaiming MASTER (logs to `/var/log/pppoe_failback.log`); on BACKUP/INIT it does **not** suspend the WAN (stays ready to dial). |

Both are self-contained, kill nothing on the peer, and are safe across OPNsense
upgrades (they live in the user hook directory, not in stock files).

### 2. Outbound NAT override

* `nat.outbound.mode = hybrid`.
* A **manual Source NAT rule** on the WAN interface:
  * Source = `Internal_Networks` (a `network` alias auto-populated from every
    enabled non-WAN interface subnet)
  * Destination = any, Protocol = any
  * **Target = Interface address** (empty `<target/>`) → each node translates to
    its *own* current PPPoE address, which is exactly what makes failover work.

### 3. Gateway switching

`system.gw_switch_default = 1` — lets a node elect its own live PPPoE gateway as
the default route when it becomes master (down/defunct gateways are skipped).

### 4. HA dialup sync

`hasync.disconnectppps = 1` — the backup drops its dialer while in BACKUP state.

### 5. LAN gateway default flag

If a node has a gateway pinned to the LAN CARP VIP with `defaultgw=1`
(monitoring disabled), it will always win the default-route election — pointing
*at itself* after takeover. The patch clears that flag (`defaultgw=0`) so the
dynamic PPPoE gateway (priority 254) outranks the LAN gateway (priority 255).

### 6. `pppoe_ha_patch.php`

The idempotent, native (`parse_config()` / `write_config()`) engine that applies
items 2–5 above, plus an optional gateway-flag fix.

### 7. `actions_pppoe_ha.conf`

configd service hooks exposing the patch as `configctl pppoe_ha …`.
## Installation

### Prerequisites

* Two OPNsense nodes forming a CARP pair with a shared pfsync link.
* A LAN CARP VIP that is the client default gateway.
* A WAN PPPoE session dialled by `mpd5` over a VLAN-tagged Ethernet link.
* `git` **not** required at runtime.

### Deploy the runtime files

```sh
install -m 0755 scripts/pppoe_ha_patch.php \
    /usr/local/opnsense/scripts/pppoe_ha_patch.php

install -m 0644 actions.d/actions_pppoe_ha.conf \
    /usr/local/opnsense/service/conf/actions.d/actions_pppoe_ha.conf

service configd restart
```

### Install the CARP syshooks (per node)

```sh
# On the SECONDARY only:
install -m 0755 50-pppoe-failover /usr/local/etc/rc.syshook.d/carp/50-pppoe-failover
# On the PRIMARY only:
install -m 0755 50-pppoe-failback  /usr/local/etc/rc.syshook.d/carp/50-pppoe-failback
```

### Apply the patch and sync

Run on the **master** node, then push to the peer:

```sh
configctl pppoe_ha apply
configctl filter reload
/usr/local/etc/rc.routing_configure
configctl filter sync               # replicate NAT/alias/rules to the backup
```

If a node has a LAN/VIP gateway pinned as default, clear its flag too:

```sh
/usr/local/opnsense/scripts/pppoe_ha_patch.php apply --lan-gateway=LAN_Gateway_Primary
/usr/local/etc/rc.routing_configure
```

### Optional: clone the WAN MAC onto the backup

Only if your ISP requires a stable WAN source MAC:

```sh
# find the active WAN MAC on the master:
ifconfig | awk '/^(pppoe|vlan|igc)/{d=$1} /ether/{print d, $2}'
# on the backup, set the WAN override (Interfaces → [WAN] → MAC, or config.xml):
#   <wan><spoofmac>aa:bb:cc:dd:ee:ff</spoofmac></wan>
```

This is a **per-node** setting: `interfaces` is not part of the HA `syncitems`
(`rules,aliases,nat,virtualip,kea,users`), so a sync will not overwrite it.

## Usage

```sh
# via configd
configctl pppoe_ha apply     # idempotent; applies NAT mode, alias, rule, gw-switch, disconnectppps
configctl pppoe_ha verify    # print current state
configctl pppoe_ha revert    # remove the NAT rule + alias
configctl pppoe_ha reload    # reload the pf filter

# direct
/usr/local/opnsense/scripts/pppoe_ha_patch.php apply [--lan-gateway=NAME]
/usr/local/opnsense/scripts/pppoe_ha_patch.php gwswitch <GatewayName>
```

Environment override for the alias name: `PPPOE_HA_ALIAS=My_Alias`.
## Verification

```sh
# 0) state report
configctl pppoe_ha verify

# 1) the NAT override is live and targets the interface address
pfctl -sn | grep pppoe0
#   nat on pppoe0 inet from <Internal_Networks> to any -> (pppoe0:0) port 1024:65535

# 2) the alias expands to all internal subnets
pfctl -t Internal_Networks -T show

# 3) routing reload must not raise pf syntax errors
ls -l /tmp/rules.debug.error 2>/dev/null || echo "no pf errors"

# 4) failover drill: power off the master, then on the backup:
netstat -rn -f inet | grep default
#   default  <isp-gw>  UGS  pppoe0       <-- must NOT be the LAN VIP anymore
```

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `configctl pppoe_ha …` → *Action not allowed or missing* | conf not installed or configd not restarted. Re-run the install + `service configd restart`. |
| pf refuses to load: `syntax error` around `proto any` | the "any" protocol must be represented by an **absent** `protocol` key, never `proto any`. The patch already omits it. |
| Default route stuck on the LAN VIP after takeover | a gateway pinned to the VIP still has `defaultgw=1`. Clear it: `pppoe_ha_patch.php apply --lan-gateway=<NAME>`. |
| Backup never dials | the `50-pppoe-failover` hook must exist on the **secondary**; check `/var/log/pppoe_failover.log`. |
| ISP rejects the re-dial | duplicate session or changed MAC. Ensure `hasync.disconnectppps=1`, and consider cloning the WAN MAC (see above). |
| MAC flapping in the ISP switch | the backup is emitting on the WAN L2 while the master is alive. Prefer `disconnect dialup interfaces`, or drop the MAC clone. |

**Gateway election reminder:** OPNsense orders gateways by `defaultgw` flag
first, then by *lower* priority number. Dynamic PPPoE gateways get priority
`254`, tunnel gateways and manually-defined gateways default to `255` — so a
live PPPoE gateway naturally beats a LAN/VIP gateway once its default flag is
cleared.

## Repository layout

```
opnsense-pppoe-ha/
├── LICENSE
├── README.md
├── actions.d/
│   └── actions_pppoe_ha.conf      # configd hooks  → /usr/local/opnsense/service/conf/actions.d/
├── scripts/
│   └── pppoe_ha_patch.php         # patch engine    → /usr/local/opnsense/scripts/
└── (CARP syshooks deployed under /usr/local/etc/rc.syshook.d/carp/)
```

## License

MIT — see [LICENSE](LICENSE).



