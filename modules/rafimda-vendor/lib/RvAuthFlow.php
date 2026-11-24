<?php

/**
 * Satıcı kayıt / giriş / çıkış akışları. Kimlik FriSay `users` + Customer sınıfıdır:
 * aynı kişi için ikinci bir hesap AÇILMAZ.
 */
class RvAuthFlow
{
	public static function login(): void
	{
		App::sendNoStoreHeaders();

		if (Customer::isLoggedIn() && RvContext::current()) {
			RvView::redirect(RvView::panelUrl());
		}

		$error = '';
		$login = '';

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			$login = RvView::post('login');
			$res = Customer::login($login, (string) ($_POST['password'] ?? ''), true);

			if (!empty($res['success'])) {
				RvContext::reset();

				RvView::redirect(RvContext::current() ? RvView::panelUrl() : RvView::url('satici-kayit'));
			}

			$error = (string) ($res['message'] ?? 'Giriş başarısız.');
		}

		RvView::display('guest_login', [
			'rvTitle' => 'Satıcı Girişi',
			'error' => $error,
			'login' => $login,
			'registerUrl' => RvView::url('satici-kayit'),
			'customerLoginUrl' => RvView::url('login'),
		]);
		exit;
	}

	public static function register(): void
	{
		App::sendNoStoreHeaders();

		$loggedIn = Customer::isLoggedIn();

		if ($loggedIn && RvContext::current()) {
			RvView::redirect(RvView::panelUrl());
		}

		$errors = [];
		$notice = '';
		$form = [];
		$done = false;

		if (RvView::isPost()) {
			Security::requireCsrf('front');
			$form = [];

			foreach (['store_name', 'legal_name', 'tax_office', 'tax_number', 'phone', 'email', 'city', 'description', 'full_name', 'account_email', 'account_phone', 'person_type', 'contact_name', 'address', 'district'] as $k) {
				$form[$k] = RvView::post($k);
			}

			$form['categories'] = array_map('intval', (array) ($_POST['categories'] ?? []));
			$form['kvkk_ack'] = empty($_POST['kvkk_ack']) ? '' : '1';
			$form['accept_terms'] = empty($_POST['accept_terms']) ? '' : '1';
			$v = RvVendor::validateStore($form);
			$app = RvApplication::validate($form, $v['clean']);
			$errors = $v['errors'] + $app['errors'];

			if (empty($_POST['accept_terms'])) {
				$errors['accept_terms'] = 'Satıcı sözleşmesini onaylamalısınız.';
			}

			// KVKK aydınlatma metninin okunduğu beyanı (açık rıza DEĞİLDİR; ayrı tutulur)
			if (empty($_POST['kvkk_ack'])) {
				$errors['kvkk_ack'] = 'KVKK aydınlatma metnini okuduğunuzu onaylayın.';
			}

			$idUser = $loggedIn ? Customer::getId() : 0;
			$accountPending = false;

			if ($errors === [] && !$loggedIn) {
				$email = strtolower(RvView::post('account_email'));
				$password = (string) ($_POST['password'] ?? '');

				if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
					$errors['account_email'] = 'Geçerli bir hesap e-postası girin.';
				} elseif (Customer::emailIsRegistered($email)) {
					// Mevcut hesap: yeni hesap AÇMA, şifreyle doğrula (hız sınırı Customer::login içinde).
					$res = Customer::login($email, $password, true);

					if (empty($res['success'])) {
						$errors['password'] = 'Bu e-posta zaten kayıtlı. Mevcut şifrenizi girin: ' . (string) ($res['message'] ?? '');
					} else {
						$idUser = Customer::getId();
					}
				} else {
					if ($password !== (string) ($_POST['password2'] ?? '')) {
						$errors['password'] = 'Şifreler eşleşmiyor.';
					} else {
						$res = Customer::register(RvView::post('full_name'), RvView::post('account_phone'), $password, $email);

						if (empty($res['success'])) {
							$errors['account_email'] = (string) ($res['message'] ?? 'Hesap oluşturulamadı.');
						} else {
							$idUser = (int) ($res['id_user'] ?? 0);
							$accountPending = !Customer::isLoggedIn();
						}
					}
				}
			}

			if ($errors === [] && $idUser > 0) {
				$auto = Settings::get('RAFIMDA_VENDOR_AUTO_APPROVE') === '1';
				$res = RvVendor::create($idUser, $v['clean'], $auto);

				if (empty($res['success'])) {
					$errors['store_name'] = (string) $res['message'];
				} else {
					$sub = RvApplication::submit($idUser, (int) $res['id_vendor'], $app['clean'], true);

					if ($auto && !empty($sub['success'])) {
						RvApplication::autoApprove((int) $sub['id_application']);
					}

					RvContext::reset();
					$done = true;

					if (!$accountPending && Customer::isLoggedIn()) {
						RvView::flash('success', $auto ? 'Mağazanız oluşturuldu.' : (string) ($sub['message'] ?? 'Başvurunuz alındı.') . ' Onaydan sonra paneli kullanabilirsiniz.');
						RvView::redirect(RvView::panelUrl());
					}

					$notice = (string) ($sub['message'] ?? 'Başvurunuz alındı.') . ' Başvurunuz onaylandıktan sonra satıcı girişi yapabilirsiniz.';
				}
			}
		}

		RvView::display('guest_register', [
			'rvTitle' => 'Satıcı Ol',
			'errors' => $errors,
			'form' => $form,
			'loggedIn' => $loggedIn,
			'done' => $done,
			'notice' => $notice,
			'loginUrl' => RvView::url('satici-giris'),
			'personTypes' => RvApplication::PERSON_TYPES,
			'categoryOptions' => RvApplication::categoryOptions(),
			'legalLinks' => class_exists('RlLegal') ? RlLegal::linksFor(['vendor-agreement', 'kvkk']) : [],
		]);
		exit;
	}

	public static function logout(): void
	{
		App::sendNoStoreHeaders();

		if (!RvView::isPost()) {
			RvView::redirect(RvView::url('satici-giris'));
		}

		Security::requireCsrf('front');
		Customer::logout();
		unset($_SESSION['rv_vendor_id']);
		RvContext::reset();
		RvView::redirect(RvView::url('satici-giris'));
	}
}
