#!/usr/local/bin/php
<?php
/*
 * pppoe_ha_patch.php - OPNsense PPPoE HA automated configuration patch engine.
 *
 * Idempotent, native OPNsense configuration helper built on
 * parse_config() / write_config(). Targets a 2-node CARP HA cluster whose WAN
 * is PPPoE assigned directly to an interface (i.e. NO WAN CARP VIP), where
 * stock automatic failover cannot trigger. See README.md for the architecture.
 *
 * Usage:
 *   /usr/local/opnsense/scripts/pppoe_ha_patch.php apply [--lan-gateway=NAME]
 *   /usr/local/opnsense/scripts/pppoe_ha_patch.php verify
 *   /usr/local/opnsense/scripts/pppoe_ha_patch.php revert
 *   /usr/local/opnsense/scripts/pppoe_ha_patch.php gwswitch <GatewayName>
 * Or via configd:  configctl pppoe_ha {apply|verify|revert|reload}
 */

require_once('config.inc');
require_once('util.inc');
require_once('interfaces.inc');

/* ------------------------------------------------------------------ config */
$ALIAS_NAME    = getenv('PPPOE_HA_ALIAS') ?: 'Internal_Networks';
$RULE_DESCR    = 'HA PPPoE Failover Outbound NAT Override';
$ALIAS_DESCR   = 'All internal LAN/VLAN subnets (HA PPPoE failover NAT override)';
$NAT_INTERFACE = 'wan';
$ENSURE_NET    = '192.168.20.0/24';   /* always include the main LAN net */

/* --------------------------------------------------------------- utilities */
function pha_out($m)  { fwrite(STDOUT, $m . "\n"); }
function pha_ok($m)   { fwrite(STDOUT, '[ OK ] ' . $m . "\n"); }
function pha_warn($m) { fwrite(STDOUT, '[WARN] ' . $m . "\n"); }

function pha_discover_nets($config, $ensure)
{
    $nets = [];
    foreach (($config['interfaces'] ?? []) as $ifname => $ifcfg) {
        if ($ifname === 'wan' || !empty($ifcfg['internal_dynamic']) || empty($ifcfg['enable'])) {
            continue;
        }
        list($ip, $network, $bits, $dev) = interfaces_primary_address($ifname);
        if (!empty($network) && !in_array($network, $nets, true)) {
            $nets[] = $network;
        }
    }
    if (!in_array($ensure, $nets, true)) {
        $nets[] = $ensure;
    }
    return $nets;
}

function pha_find_alias($config, $name)
{
    $root = $config['OPNsense']['Firewall']['Alias']['aliases']['alias'] ?? null;
    if (is_array($root)) {
        foreach ($root as $i => $a) {
            if (is_array($a) && ($a['name'] ?? '') === $name) {
                return $i;
            }
        }
    }
    return -1;
}

function pha_find_rule($config, $descr)
{
    $rules = $config['nat']['outbound']['rule'] ?? null;
    if (is_array($rules)) {
        foreach ($rules as $i => $r) {
            if (is_array($r) && ($r['descr'] ?? '') === $descr) {
                return $i;
            }
        }
    }
    return -1;
}
function pha_apply(&$config, $aliasName, $ruleDescr, $aliasDescr, $natInterface, $ensureNet, $gwName = null)
{
    $changed = 0;

    if (($config['nat']['outbound']['mode'] ?? '') !== 'hybrid') {
        $config['nat']['outbound']['mode'] = 'hybrid';
        $changed++;
        pha_ok('set nat.outbound.mode = hybrid');
    }

    $nets = pha_discover_nets($config, $ensureNet);
    $content = implode("\n", $nets);
    $ai = pha_find_alias($config, $aliasName);
    if ($ai === -1) {
        $config['OPNsense']['Firewall']['Alias']['aliases']['alias'][] = [
            '@attributes' => ['uuid' => generate_uuid()],
            'enabled' => '1', 'name' => $aliasName, 'type' => 'network',
            'path_expression' => '', 'proto' => '', 'interface' => '', 'counters' => '0',
            'updatefreq' => '', 'content' => $content, 'password' => '', 'username' => '',
            'authtype' => '', 'expire' => '', 'categories' => '', 'description' => $aliasDescr,
        ];
        $changed++;
        pha_ok("created alias {$aliasName} => " . implode(', ', $nets));
    } elseif (($config['OPNsense']['Firewall']['Alias']['aliases']['alias'][$ai]['content'] ?? '') !== $content) {
        $config['OPNsense']['Firewall']['Alias']['aliases']['alias'][$ai]['content'] = $content;
        $changed++;
        pha_ok("updated alias {$aliasName} => " . implode(', ', $nets));
    } else {
        pha_ok("alias {$aliasName} already current");
    }

    $ri = pha_find_rule($config, $ruleDescr);
    if ($ri === -1) {
        $config['nat']['outbound']['rule'][] = [
            'interface' => $natInterface, 'ipprotocol' => 'inet',
            'source' => ['network' => $aliasName], 'destination' => ['any' => true],
            'target' => '', 'disabled' => '0', 'nordr' => '0', 'nosync' => '0', 'descr' => $ruleDescr,
        ];
        $changed++;
        pha_ok("created outbound NAT rule (source={$aliasName}, target=Interface address)");
    } elseif (($config['nat']['outbound']['rule'][$ri]['source']['network'] ?? '') !== $aliasName) {
        $config['nat']['outbound']['rule'][$ri]['source'] = ['network' => $aliasName];
        $changed++;
        pha_ok("updated outbound NAT rule source => {$aliasName}");
    } else {
        pha_ok('outbound NAT rule already current');
    }

    if (empty($config['system']['gw_switch_default'])) {
        $config['system']['gw_switch_default'] = '1';
        $changed++;
        pha_ok('enabled system.gw_switch_default');
    } else {
        pha_ok('system.gw_switch_default already enabled');
    }

    if (!empty($config['hasync'])) {
        if (empty($config['hasync']['disconnectppps'])) {
            $config['hasync']['disconnectppps'] = '1';
            $changed++;
            pha_ok('enabled hasync.disconnectppps');
        } else {
            pha_ok('hasync.disconnectppps already enabled');
        }
    } else {
        pha_warn('no hasync section; skipping disconnectppps');
    }

    if (!empty($gwName)) {
        $changed += pha_gwswitch($config, $gwName);
    }

    return $changed;
}
function pha_gwswitch(&$config, $gwName)
{
    $changed = 0;
    $items = $config['OPNsense']['Gateways']['gateway_item'] ?? null;
    if (is_array($items)) {
        foreach ($items as $i => $gw) {
            if (($gw['name'] ?? '') === $gwName && !empty($gw['defaultgw'])) {
                $config['OPNsense']['Gateways']['gateway_item'][$i]['defaultgw'] = '0';
                $changed++;
                pha_ok("cleared defaultgw flag on gateway {$gwName}");
            }
        }
    }
    if ($changed === 0) {
        pha_warn("gateway '{$gwName}' not found or already non-default");
    }
    return $changed;
}

function pha_revert(&$config, $aliasName, $ruleDescr)
{
    $changed = 0;
    if (!empty($config['nat']['outbound']['rule']) && is_array($config['nat']['outbound']['rule'])) {
        foreach ($config['nat']['outbound']['rule'] as $i => $r) {
            if (($r['descr'] ?? '') === $ruleDescr) {
                unset($config['nat']['outbound']['rule'][$i]);
                $config['nat']['outbound']['rule'] = array_values($config['nat']['outbound']['rule']);
                $changed++;
                pha_ok('removed outbound NAT rule');
                break;
            }
        }
    }
    $ai = pha_find_alias($config, $aliasName);
    if ($ai !== -1) {
        unset($config['OPNsense']['Firewall']['Alias']['aliases']['alias'][$ai]);
        $config['OPNsense']['Firewall']['Alias']['aliases']['alias'] =
            array_values($config['OPNsense']['Firewall']['Alias']['aliases']['alias']);
        $changed++;
        pha_ok("removed alias {$aliasName}");
    }
    return $changed;
}

function pha_verify($config, $aliasName, $ruleDescr)
{
    pha_out('nat.outbound.mode        : ' . ($config['nat']['outbound']['mode'] ?? '(unset)'));
    pha_out('system.gw_switch_default : ' . (empty($config['system']['gw_switch_default']) ? 'disabled' : 'enabled'));
    pha_out('hasync.disconnectppps    : ' . (!empty($config['hasync']['disconnectppps']) ? 'enabled' : 'disabled/absent'));

    $ai = pha_find_alias($config, $aliasName);
    if ($ai === -1) {
        pha_out("alias {$aliasName} : ABSENT");
    } else {
        $c = trim($config['OPNsense']['Firewall']['Alias']['aliases']['alias'][$ai]['content'] ?? '');
        pha_out("alias {$aliasName} : " . ($c === '' ? '(empty)' : str_replace("\n", ', ', $c)));
    }

    $ri = pha_find_rule($config, $ruleDescr);
    if ($ri === -1) {
        pha_out('outbound NAT override : ABSENT');
    } else {
        $r = $config['nat']['outbound']['rule'][$ri];
        $tgt = ($r['target'] ?? '') === '' ? 'Interface address' : $r['target'];
        pha_out("outbound NAT override : iface=" . ($r['interface'] ?? '?') .
            " source=" . ($r['source']['network'] ?? '?') . " target={$tgt}");
    }
}

/* -------------------------------------------------------------------- main */
$config = parse_config();
$mode = $argv[1] ?? 'verify';
$changed = 0;

switch ($mode) {
    case 'apply':
        $gwName = null;
        foreach ($argv as $a) {
            if (str_starts_with($a, '--lan-gateway=')) {
                $gwName = substr($a, strlen('--lan-gateway='));
            }
        }
        $changed = pha_apply($config, $ALIAS_NAME, $RULE_DESCR, $ALIAS_DESCR, $NAT_INTERFACE, $ENSURE_NET, $gwName);
        break;
    case 'gwswitch':
        if (empty($argv[2])) {
            pha_warn('usage: pppoe_ha_patch.php gwswitch <GatewayName>');
            exit(2);
        }
        $changed = pha_gwswitch($config, $argv[2]);
        break;
    case 'revert':
        $changed = pha_revert($config, $ALIAS_NAME, $RULE_DESCR);
        break;
    case 'verify':
    default:
        pha_verify($config, $ALIAS_NAME, $RULE_DESCR);
        exit(0);
}

if ($changed > 0) {
    write_config('pppoe_ha_patch: ' . $mode . " ({$changed} change(s))");
    pha_ok("write_config committed ({$changed} change(s))");
    pha_out('Now reload:  configctl filter reload  &&  /usr/local/etc/rc.routing_configure');
} else {
    pha_ok('no changes required');
}

