#!/usr/bin/php
<?php
/**
 * Configure network firewalling and traffic shaping for PMSS hosts.
 */

require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/networkInfo.php';
require_once '/scripts/lib/network/iptables.php';
require_once '/scripts/lib/network/fireqos.php';

$usersRaw = trim((string)shell_exec('/scripts/listUsers.php'));
$users     = $usersRaw === '' ? [] : array_filter(explode("\n", $usersRaw), 'strlen');

$networkConfig = networkLoadConfig();
$localnets     = networkLoadLocalnets();

$link      = $networkConfig['interface'] ?? detectPrimaryInterface();
$interface = $link;
$linkSpeed = getLinkSpeed($link);

if ($interface === '') {
    die("Error: Could not determine primary interface\n");
}

$monitoringCommands = networkParseMonitoringCommands(shell_exec('/scripts/util/makeMonitoringRules.php') ?: '');

$replacements = [
    '##IFACE##' => $interface,
    '##LINK##'  => $link,
];

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

$filterCommands = array_merge(
    [
        '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j LOG --log-prefix "tcpsack: " --log-level 4',
        '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP',
    ],
    array_map(fn ($source) => "-A INPUT -i ##IFACE## -s {$source} -j DROP", $bogonSources),
    [
        '-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 1194 -j ACCEPT',
        '-A INPUT -i tun+ -j ACCEPT',
        '-A FORWARD -i tun+ -o tun+ -j DROP',
        '-A FORWARD -i tun+ -j ACCEPT',
        '-A FORWARD -i tun+ -o ##IFACE## -m state --state RELATED,ESTABLISHED -j ACCEPT',
        '-A FORWARD -i ##IFACE## -o tun+ -m state --state RELATED,ESTABLISHED -j ACCEPT',
        '-A OUTPUT -o tun+ -j ACCEPT',
    ],
    $monitoringCommands
);

$natCommands = ['-A POSTROUTING -s 10.8.0.0/24 -o ##LINK## -j MASQUERADE'];

file_put_contents('/proc/sys/net/ipv4/ip_forward', '1');

$renderedFilter = array_map(fn ($cmd) => str_replace(array_keys($replacements), array_values($replacements), $cmd), $filterCommands);
$renderedNat    = array_map(fn ($cmd) => str_replace(array_keys($replacements), array_values($replacements), $cmd), $natCommands);

if (!networkApplyIptablesAtomically($renderedFilter, $renderedNat)) {
    logMessage('iptables-restore failed, falling back to sequential rules');
    networkApplyIptablesFallback($filterCommands, $natCommands, $replacements);
}

$fireqosConfig = networkBuildFireqosConfig(
    $networkConfig + ['interface' => $interface, 'speed' => $networkConfig['speed'] ?? $linkSpeed],
    $users,
    $localnets
);
networkApplyFireqos($fireqosConfig);
