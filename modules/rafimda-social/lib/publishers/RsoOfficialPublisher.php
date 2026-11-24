<?php

/**
 * Instagram (Meta Graph API – Content Publishing), TikTok (Content Posting API), YouTube (Data API v3 – videos.insert) için ortak İSKELET.
 * Yetkilendirme adresleri resmî OAuth uç noktalarıdır. Kod değişimi, medya yükleme ve yayın UYGULANMADI:
 * uygulama incelemesi (Meta App Review / TikTok audit / Google OAuth doğrulaması), iş hesabı ve test hesabıyla doğrulama gerekir.
 * Bu nedenle isImplemented() false döner; sistem yayın yapmaz ve bunu açıkça bildirir.
 */
final class RsoOfficialPublisher implements RsoPublisher
{
	private const DEF = [
		'instagram' => ['Instagram (Meta Graph API)', 'https://www.facebook.com/dialog/oauth', ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'business_management']],
		'tiktok' => ['TikTok (Content Posting API)', 'https://www.tiktok.com/v2/auth/authorize/', ['user.info.basic', 'video.publish', 'video.upload']],
		'youtube' => ['YouTube (Data API v3)', 'https://accounts.google.com/o/oauth2/v2/auth', ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly']],
	];
	public const NOT_READY = 'Resmî API yayını henüz uygulanmadı: uygulama kaydı, platform incelemesi ve test hesabıyla doğrulama gerekiyor. İçerik hazırlanır ama otomatik yayınlanmaz.';

	public function __construct(private string $platform)
	{
	}

	public function platform(): string
	{
		return $this->platform;
	}

	public function label(): string
	{
		return self::DEF[$this->platform][0] ?? $this->platform;
	}

	public function isImplemented(): bool
	{
		return false;
	}

	public function scopes(): array
	{
		return self::DEF[$this->platform][2] ?? [];
	}

	public function authorizeUrl(string $clientId, string $redirectUri, string $state): string
	{
		$base = self::DEF[$this->platform][1] ?? '';
		$q = $this->platform === 'tiktok'
			? ['client_key' => $clientId, 'response_type' => 'code', 'scope' => implode(',', $this->scopes()), 'redirect_uri' => $redirectUri, 'state' => $state]
			: ['client_id' => $clientId, 'response_type' => 'code', 'scope' => implode($this->platform === 'instagram' ? ',' : ' ', $this->scopes()), 'redirect_uri' => $redirectUri, 'state' => $state]
				+ ($this->platform === 'youtube' ? ['access_type' => 'offline', 'prompt' => 'consent'] : []);

		return $base . '?' . http_build_query($q);
	}

	public function exchangeCode(string $code, string $redirectUri): array
	{
		return ['success' => false, 'message' => self::NOT_READY];
	}

	public function publish(array $account, array $post): array
	{
		return ['success' => false, 'message' => self::NOT_READY];
	}

	public function metrics(array $account, array $post): array
	{
		return ['success' => false, 'message' => 'Platform istatistikleri API bağlantısı kurulduğunda alınır.'];
	}
}
