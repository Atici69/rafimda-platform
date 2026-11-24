<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

// Müşteri fatura PDF'i: yalnızca giriş yapmış ve faturanın sahibi olan müşteri (yetkisiz erişimde 404).
App::sendNoStoreHeaders();
$id = (int) Tools::getValue('id', 0);
RiPanel::stream(Customer::isLoggedIn() ? RiInvoice::pdfFor($id, 'customer', (int) Customer::getId()) : null, 'fatura-' . $id . '.pdf');
