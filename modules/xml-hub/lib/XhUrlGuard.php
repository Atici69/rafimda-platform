<?php

/**
 * SSRF koruması. Bir URL'nin yalnızca genel (public) internet adreslerine çözülmesine izin verir.
 * - yalnızca http/https, kullanıcı bilgisi (user:pass@) yok, izinli portlar
 * - sayısal/onaltılı/sekizlik IP gizleme biçimleri reddedilir
 * - DNS'ten dönen TÜM adresler genel olmalı (biri özel ise reddedilir); ilk adres bağlantıda SABİTLENİR (DNS rebinding)
 * - IPv4-mapped / 6to4 / NAT64 IPv6 biçimleri içindeki IPv4 de denetlenir
 */
class XhUrlGuard
{
	public const ALLOWED_PORTS = [80, 443, 8080, 8443];

	/** Testlerde değiştirilebilir: callable(string $host): list<string> */
	public static $resolver = null;

	private const BLOCKED_V4 = [
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
		'192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
		'198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
	];

	private const BLOCKED_V6 = ['::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

	private static function inCidr(string $ip, string $cidr): bool
	{
		[$net, $bits] = explode('/', $cidr);
		$a = @inet_pton($ip);
		$b = @inet_pton($net);

		if ($a === false || $b === false || strlen($a) !== strlen($b)) {
			return false;
		}

		$bits = (int) $bits;
		$full = intdiv($bits, 8);

		if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) {
			return false;
		}

		$rem = $bits % 8;

		if ($rem === 0) {
			return true;
		}

		$mask = (0xFF << (8 - $rem)) & 0xFF;

		return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
	}

	public static function isPublicIp(string $ip): bool
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return false;
		}

		if (strpos($ip, ':') === false) {
			foreach (self::BLOCKED_V4 as $c) {
				if (self::inCidr($ip, $c)) {
					return false;
				}
			}

			return true;
		}

		$bin = inet_pton($ip);

		// ::ffff:a.b.c.d (IPv4-mapped) ve ::a.b.c.d (uyumlu): gömülü IPv4'ü denetle
		if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) {
			$v4 = inet_ntop(substr($bin, 12, 4));

			return $v4 !== false && self::isPublicIp($v4);
		}

		// 2002::/16 (6to4): gömülü IPv4
		if (substr($bin, 0, 2) === "\x20\x02") {
			$v4 = inet_ntop(substr($bin, 2, 4));

			return $v4 !== false && self::isPublicIp($v4);
		}

		foreach (self::BLOCKED_V6 as $c) {
			if (self::inCidr($ip, $c)) {
				return false;
			}
		}

		return true;
	}

	/** @return list<string> */
	private static function resolve(string $host): array
	{
		if (self::$resolver !== null) {
			return (self::$resolver)($host);
		}

		$ips = [];
		$v4 = @gethostbynamel($host);

		if (is_array($v4)) {
			$ips = $v4;
		}

		$v6 = @dns_get_record($host, DNS_AAAA);

		if (is_array($v6)) {
			foreach ($v6 as $r) {
				if (!empty($r['ipv6'])) {
					$ips[] = $r['ipv6'];
				}
			}
		}

		return array_values(array_unique($ips));
	}

	/**
	 * @return array{ok:bool, error:string, scheme:string, host:string, port:int, ip:string, url:string}
	 */
	public static function validate(string $url): array
	{
		$out = ['ok' => false, 'error' => '', 'scheme' => '', 'host' => '', 'port' => 0, 'ip' => '', 'url' => $url];
		$url = trim($url);

		if ($url === '' || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7f]/', $url)) {
			$out['error'] = 'Geçersiz URL.';

			return $out;
		}

		$p = parse_url($url);

		if ($p === false || empty($p['scheme']) || empty($p['host'])) {
			$out['error'] = 'Geçersiz URL.';

			return $out;
		}

		$scheme = strtolower((string) $p['scheme']);

		if (!in_array($scheme, ['http', 'https'], true)) {
			$out['error'] = 'Yalnızca http ve https adreslerine izin verilir.';

			return $out;
		}

		if (isset($p['user']) || isset($p['pass'])) {
			$out['error'] = 'URL içinde kullanıcı adı/parola kullanılamaz.';

			return $out;
		}

		$host = strtolower(trim((string) $p['host'], '[]'));
		$port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);

		if (!in_array($port, self::ALLOWED_PORTS, true)) {
			$out['error'] = 'Bu porta izin verilmiyor (izinli: ' . implode(', ', self::ALLOWED_PORTS) . ').';

			return $out;
		}

		$isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

		// 2130706433, 0x7f.1, 017700000001 gibi gizlenmiş IP biçimlerini reddet
		if (!$isIp && preg_match('/^[0-9a-fx.]+$/i', $host) && preg_match('/[0-9]/', $host) && !preg_match('/[g-wyz-]/i', $host)) {
			$out['error'] = 'Sayısal ana makine adı kabul edilmez.';

			return $out;
		}

		if (!$isIp && ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal' || strpos($host, '.') === false)) {
			$out['error'] = 'Yerel ana makine adlarına izin verilmez.';

			return $out;
		}

		$ips = $isIp ? [$host] : self::resolve($host);

		if ($ips === []) {
			$out['error'] = 'Alan adı çözümlenemedi.';

			return $out;
		}

		foreach ($ips as $ip) {
			if (!self::isPublicIp($ip)) {
				$out['error'] = 'Adres özel/yerel bir ağa çözülüyor; engellendi.';

				return $out;
			}
		}

		return ['ok' => true, 'error' => '', 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'url' => $url];
	}
}
