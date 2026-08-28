<?php
// M-Pesa gateway configuration. Rotate this key before production use.
$mpesa_api_key = 'sw_b8d2cbca861b186461836cc2ddd099b2fdf378a3b306cdb7adc23f6d';
$mpesa_base_url = 'https://swiftwallet.co.ke/v3';

// Uses the public tunnel host automatically when the app is opened through it.
// A localhost callback cannot be reached by Swift Wallet; status polling still works.
if (!defined('MPESA_CALLBACK_URL')) {
	$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
		? $_SERVER['HTTP_X_FORWARDED_PROTO']
		: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
	$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
	$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
	$root = $script_name !== ''
		? rtrim(str_replace('\\', '/', dirname($script_name)), '/')
		: '/machinery-rental-system';
	if (substr($root, -9) === '/payments') {
		$root = substr($root, 0, -9);
	}
	if ($root === '' || $root === '.') {
		$root = '/machinery-rental-system';
	}
	define('MPESA_CALLBACK_URL', $scheme . '://' . $host . $root . '/payments/mpesa_callback.php');
}

$mpesa_api_url = $mpesa_base_url . '/stk-initiate/';
$mpesa_callback_url = MPESA_CALLBACK_URL;

function normalize_mpesa_phone(string $phone): ?string
{
	$phone = preg_replace('/[\s\-\+]/', '', $phone);
	if (substr($phone, 0, 1) === '0') {
		$phone = '254' . substr($phone, 1);
	} elseif (substr($phone, 0, 3) !== '254') {
		$phone = '254' . $phone;
	}
	return preg_match('/^254[17]\d{8}$/', $phone) ? $phone : null;
}