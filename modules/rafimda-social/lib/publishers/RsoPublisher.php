<?php

/**
 * Resmî platform API'si üzerinden yayın sözleşmesi. Şifreyle bot girişi, otomatik beğeni/takip/yorum/mesaj YOKTUR ve eklenmeyecektir.
 * Hesabı kullanıcı kendisi OAuth ile yetkilendirir; token şifreli saklanır, loglanmaz.
 */
interface RsoPublisher
{
	public function platform(): string;

	public function label(): string;

	/** Resmî API ile uçtan uca doğrulanmış mı? Değilse yayın yapılmaz. */
	public function isImplemented(): bool;

	/** @return list<string> gereken izinler */
	public function scopes(): array;

	/** OAuth yetkilendirme adresi (uygulama kimliği yapılandırılmışsa). */
	public function authorizeUrl(string $clientId, string $redirectUri, string $state): string;

	/** @return array{success:bool,token?:string,expires_in?:int,message:string} */
	public function exchangeCode(string $code, string $redirectUri): array;

	/** @return array{success:bool,external_id?:string,message:string} */
	public function publish(array $account, array $post): array;

	/** @return array{success:bool,metrics?:array<string,int>,message:string} */
	public function metrics(array $account, array $post): array;
}
