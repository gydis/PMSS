#!/usr/bin/php
<?php
/**
 * Configure network firewalling and traffic shaping for PMSS hosts.
 */

require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/networkInfo.php';
require_once '/scripts/lib/network/iptables.php';
require_once '/scripts/lib/network/fireqos.php';
// Collect tenant usernames for FireQOS shaping.
$usersRaw = trim((string) shell_exec('/scripts/listUsers.php'));
$users    = $usersRaw === '' ? [] : array_filter(explode("\n", $usersRaw), 'strlen');

// Retrieve persisted interface selections and LAN bypass ranges.
$networkConfig = networkLoadConfig();
$localnets     = networkLoadLocalnets();

// Resolve the uplink interface and current speed capacity.
$link      = $networkConfig['interface'] ?? detectPrimaryInterface();
$interface = $link;
$linkSpeed = getLinkSpeed($link);

if ($interface === '') {
    die("Error: Could not determine primary interface\n");
}

// Pull optional monitoring chain additions from helper script output.
$monitoringCommands = networkParseMonitoringCommands(shell_exec('/scripts/util/makeMonitoringRules.php') ?: '');

// Placeholder tokens used later for rule rendering.
$replacements = [
    '##IFACE##' => $interface,
    '##LINK##'  => $link,
];

// Drop known bogon source networks on the public interface.
$bogonSources = [
    '0.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/3',
];

// Guard against TCP SACK exploits on ingress.
$tcpsackRules = [
    '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j LOG --log-prefix "tcpsack: " --log-level 4',
    '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP',
];

// Render bogon list into INPUT drop rules for the uplink interface.
$bogonRules = array_map(
    fn (string $source) => "-A INPUT -i ##IFACE## -s {$source} -j DROP",
    $bogonSources
);

// Permit VPN entry points and overlay interfaces.
$inputRules = [
    '-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 1194 -j ACCEPT',
    '-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 51820 -j ACCEPT',
    '-A INPUT -i tun+ -j ACCEPT',
    '-A INPUT -i wg+ -j ACCEPT',
];

// Allow overlay forwarding while keeping stateful checks intact.
$forwardRules = [
    '-A FORWARD -i tun+ -o tun+ -j DROP',
    '-A FORWARD -i tun+ -j ACCEPT',
    '-A FORWARD -i tun+ -o ##IFACE## -m state --state RELATED,ESTABLISHED -j ACCEPT',
    '-A FORWARD -i ##IFACE## -o tun+ -m state --state RELATED,ESTABLISHED -j ACCEPT',
    '-A FORWARD -i wg+ -o ##IFACE## -j ACCEPT',
    '-A FORWARD -i ##IFACE## -o wg+ -m state --state RELATED,ESTABLISHED -j ACCEPT',
];

// Permit overlay traffic egress in the default policy set.
$outputRules = [
    '-A OUTPUT -o tun+ -j ACCEPT',
    '-A OUTPUT -o wg+ -j ACCEPT',
];

// Compose the final filter rule list in execution order.
$filterCommands = array_merge(
    $tcpsackRules,
    $bogonRules,
    $inputRules,
    $forwardRules,
    $outputRules,
    $monitoringCommands
);

// Provide source NAT for WireGuard and OpenVPN ranges.
$natCommands = [
    '-A POSTROUTING -s 10.8.0.0/24 -o ##LINK## -j MASQUERADE',
    '-A POSTROUTING -s 10.90.90.0/24 -o ##LINK## -j MASQUERADE',
];

// Ensure kernel forwarding remains enabled for tenant networks.
file_put_contents('/proc/sys/net/ipv4/ip_forward', '1');

$renderedFilter = array_map(
    fn (string $cmd) => str_replace(array_keys($replacements), array_values($replacements), $cmd),
    $filterCommands
);
$renderedNat = array_map(
    fn (string $cmd) => str_replace(array_keys($replacements), array_values($replacements), $cmd),
    $natCommands
);

// Prefer atomic iptables-restore to minimise transient inconsistencies.
if (!networkApplyIptablesAtomically($renderedFilter, $renderedNat)) {
    logMessage('iptables-restore failed, falling back to sequential rules');
    networkApplyIptablesFallback($filterCommands, $natCommands, $replacements);
}

// Render FireQOS shaping rules with the refreshed tenant list.
$fireqosConfig = networkBuildFireqosConfig(
    $networkConfig + ['interface' => $interface, 'speed' => $networkConfig['speed'] ?? $linkSpeed],
    $users,
    $localnets
);
// Load the generated policy into the running FireQOS instance.
networkApplyFireqos($fireqosConfig);
