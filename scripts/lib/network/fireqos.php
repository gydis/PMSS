<?php
/**
 * FireQOS configuration helpers.
 */

require_once __DIR__.'/../runtime.php';

function networkBuildFireqosConfig(array $networkConfig, array $users, array $localnets): string
{
    $templatePath = getenv('PMSS_FIREQOS_TEMPLATE') ?: '/etc/seedbox/config/template.fireqos';
    $template = file_get_contents($templatePath);
    if ($template === false) {
        $template = 'interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n';
    }

    $fireqosConfigLocal = "class local commit 10%\n";
    foreach ($localnets as $localnet) {
        $fireqosConfigLocal .= "    match dst {$localnet}\n";
    }

    $fireqosConfigUsers = '';
    $fireqosMark = 1;
    if (!empty($users)) {
        foreach ($users as $username) {
            $uid = trim((string)shell_exec("id -u {$username}"));
            if ($uid === '') {
                continue;
            }

            $limit = '';
            if (file_exists("/var/run/pmss/trafficLimits/{$username}.enabled")) {
                $limit = ' ceil '.((int)$networkConfig['throttle']['max']).'Mbit';
            }

            $fireqosConfigUsers .= "    class {$username}{$limit} \n";
            $fireqosConfigUsers .= "      match rawmark {$fireqosMark}\n";
            ++$fireqosMark;
        }
    }

    $rendered = str_replace(
        ['##INTERFACE', '##SPEED', '##LOCALNETWORK', '##USERMATCHES'],
        [
            $networkConfig['interface'] ?? 'eth0',
            $networkConfig['speed'] ?? 1000,
            $fireqosConfigLocal,
            $fireqosConfigUsers
        ],
        $template
    );

    return $rendered;
}

function networkApplyFireqos(string $config): void
{
    file_put_contents('/etc/seedbox/config/fireqos.conf', $config);
    shell_exec('fireqos start /etc/seedbox/config/fireqos.conf >> /var/log/pmss/fireqos.log 2>&1');
}
