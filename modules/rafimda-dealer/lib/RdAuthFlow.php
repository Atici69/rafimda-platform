<?php

/** Bayi başvuru / giriş / çıkış / referans akışları. Kimlik FriSay `users` + Customer sınıfıdır; ikinci hesap AÇILMAZ. Parola sıfırlama çekirdeğin /forgot-password akışıdır. */
class RdAuthFlow
{
	public static function login(): void
	{
		App::sendNoStoreHeaders();

		if (Customer::isLoggedIn() && RdContext::current()) {
			RdView::redirect(RdView::panelUrl());
		}

		$error = '';
		$login = '';

		if (RdView::isPost()) {
			Security::requireCsrf('front');
			$login = RdView::post('login');
			$res = Customer::login($login, (string) ($_POST['password'] ?? ''), true);

			if (!empty($res['success'])) {
				RdContext::reset();

				if (RdContext::current()) {
					RdView::redirect(RdView::panelUrl());
				}

				RdView::flash('warning', 'Bu hesapla kayıtlı bir bayi başvurusu bulunamadı. Başvuru formunu doldurabilirsiniz.');
				RdView::redirect(RdView::url('bayi-basvuru'));
			}

			$error = (string) ($res['message'] ?? 'Giriş başarısız. E-posta/telefon veya şifre hatalı.');
		}

		RdView::display('guest_login', [
			'rdTitle' => 'Bayi Girişi',
			'error' => $error,
			'login' => $login,
			'applyUrl' => RdView::url('bayi-basvuru'),
			'forgotUrl' => RdView::url('forgot-password'),
			'customerUrl' => RdView::url('login'),
			'flash' => RdView::takeFlash(),
		]);
		exit;
	}

	public static function apply(): void
	{
		App::sendNoStoreHeaders();

		$loggedIn = Customer::isLoggedIn();

		if ($loggedIn && RdContext::current()) {
			RdView::redirect(RdView::panelUrl());
		}

		$errors = [];
		$notice = '';
		$form = [];
		$done = false;
		$parentCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ust'] ?? '')) ?? '');

		if (RdView::isPost()) {
			Security::requireCsrf('front');
			$form = [];

			foreach (['company_name', 'contact_name', 'phone', 'email', 'city', 'tax_office', 'tax_number', 'note', 'full_name', 'account_email', 'account_phone'] as $k) {
				$form[$k] = RdView::post($k);
			}

			$parentCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', RdView::post('parent_code')) ?? '');
			$v = RdDealer::validate($form);
			$errors = $v['errors'];

			if (empty($_POST['accept_terms'])) {
				$errors['accept_terms'] = 'Bayilik koşullarını onaylamalısınız.';
			}

			$idUser = $loggedIn ? Customer::getId() : 0;
			$accountPending = false;

			if ($errors === [] && !$loggedIn) {
				$email = strtolower(RdView::post('account_email'));
				$password = (string) ($_POST['password'] ?? '');

				if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
					$errors['account_email'] = 'Geçerli bir hesap e-postası girin.';
				} elseif (Customer::emailIsRegistered($email)) {
					$res = Customer::login($email, $password, true);

					if (empty($res['success'])) {
						$errors['password'] = 'Bu e-posta zaten kayıtlı. Mevcut şifrenizi girin: ' . (string) ($res['message'] ?? '');
					} else {
						$idUser = Customer::getId();
					}
				} elseif ($password !== (string) ($_POST['password2'] ?? '')) {
					$errors['password'] = 'Şifreler eşleşmiyor.';
				} else {
					$res = Customer::register(RdView::post('full_name'), RdView::post('account_phone'), $password, $email);

					if (empty($res['success'])) {
						$errors['account_email'] = (string) ($res['message'] ?? 'Hesap oluşturulamadı.');
					} else {
						$idUser = (int) ($res['id_user'] ?? 0);
						$accountPending = !Customer::isLoggedIn();
					}
				}
			}

			if ($errors === [] && $idUser > 0) {
				$res = RdDealer::create($idUser, $v['clean'], $parentCode);

				if (empty($res['success'])) {
					$errors['company_name'] = (string) $res['message'];
				} else {
					RdContext::reset();
					$done = true;
					$notice = 'Başvurunuz alındı. Yönetici değerlendirmesinden sonra bayi paneline giriş yapabilirsiniz.' . ($accountPending ? ' Hesabınız onaylandığında giriş yapabilirsiniz.' : '');
				}
			}
		}

		RdView::display('guest_apply', [
			'rdTitle' => 'Bayi Başvurusu',
			'errors' => $errors,
			'form' => $form,
			'loggedIn' => $loggedIn,
			'done' => $done,
			'notice' => $notice,
			'parentCode' => $parentCode,
			'loginUrl' => RdView::url('bayi-giris'),
			'flash' => RdView::takeFlash(),
		]);
		exit;
	}

	public static function logout(): void
	{
		App::sendNoStoreHeaders();

		if (!RdView::isPost()) {
			RdView::redirect(RdView::url('bayi-giris'));
		}

		Security::requireCsrf('front');
		Customer::logout();
		RdContext::reset();
		RdView::redirect(RdView::url('bayi-giris'));
	}

	/** /bayi-ref?kod=XXXX — referans kodunu 30 gün saklar ve ana sayfaya yönlendirir (geçersiz kodda sessizce). */
	public static function ref(): void
	{
		$dealer = RdDealer::byCode((string) ($_GET['kod'] ?? ''));

		if ($dealer) {
			$_SESSION['rd_ref'] = (string) $dealer['referral_code'];
			$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

			if (!headers_sent()) {
				setcookie(RdCommission::COOKIE, (string) $dealer['referral_code'], ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
			}
		}

		RdView::redirect(RdView::url(''));
	}
}
