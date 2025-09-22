# WireGuard Usage

PMSS installs and manages a single server endpoint at `/etc/wireguard/wg0.conf`.
During provisioning the installer generates server keys, enables `wg-quick@wg0`
and writes connection instructions to both `/etc/wireguard/README` and each
user's `~/wireguard.txt`.

Typical workflow:

1. Read `~/wireguard.txt` to obtain your server endpoint, public key, and
   configuration template.
2. Generate a client key pair (`wg genkey | tee private.key | wg pubkey > public.key`).
3. Share the public key with the administrator so it can be appended as a `[Peer]`
   in `/etc/wireguard/wg0.conf` (the template already includes MTU and NAT rules).
4. Apply the client template on your device and set the private key.

A cron watchdog (`checkWireguard.php`) ensures the kernel module stays loaded and
`wg-quick@wg0` remains active. Logs are available in `/var/log/pmss/checkWireguard.log`.
