<?php
// Python related stuff ... Yuck, hope this doesn't break every 2nd minute.
// Yes it does break randomly. Just as expected. Who comes up with rules like "ensure users are broken!" - thanks very much.


$flexget = shell_exec('flexget -V');

// Prefer python3's tooling; avoid legacy easy_install hacks that fight with Debian packaging.
$python = trim(shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
$pipCmd = $python.' -m pip';

// Some images ship without ensurepip; run it best-effort and fall back to apt when missing.
passthru($python.' -m ensurepip --default-pip 2>/dev/null');

$pipVersion = shell_exec($pipCmd.' --version 2>/dev/null');
if ($pipVersion === null || trim($pipVersion) === '') {
    passthru('apt-get install -y python3-pip python3-venv python3-setuptools');
}
$pipVersion = shell_exec($pipCmd.' --version 2>/dev/null');
if ($pipVersion === null || trim($pipVersion) === '') {
    echo "WARNING: python3 pip is still unavailable; skipping Flexget/gdrivefs installs.\n";
    return;
}

passthru($pipCmd.' install --upgrade pip setuptools wheel');

// Install gdrivefs -- is this even used anymore?
echo "### Install gdrivefs\n";
passthru($pipCmd.' install --upgrade gdrivefs');

echo "### Install/Update Flexget:\n";
// Keep dependency pins in place but ensure the requirement syntax stays valid for pip.
passthru($pipCmd.' install --upgrade pyopenssl ndg-httpsclient cryptography');
passthru($pipCmd." install --upgrade funcsigs 'chardet==3.0.3' 'certifi==2017.4.17'");
passthru($pipCmd.' install --upgrade flexget');


// Keep a single entry point for pip operations.
passthru($pipCmd.' install --upgrade youtube_dl');
