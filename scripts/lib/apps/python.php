<?php
// Python related stuff ... Yuck, hope this doesn't break every 2nd minute.
// Yes it does break randomly. Just as expected. Who comes up with rules like "ensure users are broken!" - thanks very much.


$flexget = shell_exec('flexget -V');

// Prefer python3's tooling; avoid legacy easy_install hacks that fight with Debian packaging.
$python = trim(shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
$pipCmd = $python.' -m pip';

// Some images ship without ensurepip; ignore failures and continue with whatever pip is available.
passthru($python.' -m ensurepip --default-pip 2>/dev/null');
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
