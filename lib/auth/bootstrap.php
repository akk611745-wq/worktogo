<?php
/**
 * bootstrap.php  —  WorkToGo Smart Auth Layer :: one-include loader
 * ----------------------------------------------------------------------------
 * Requires every auth-abstraction class in dependency order, so an HTTP handler
 * (send-otp.php, methods.php) or a headless self-test loads the layer with one
 * include. Pure includes — no DB, no side effects, no output. Mirrors the payment
 * layer's require-chain and the agent layer's bootstrap.
 */

require_once __DIR__ . '/OtpChannel.php';
require_once __DIR__ . '/Msg91SmsChannel.php';
require_once __DIR__ . '/Sms2Channel.php';
require_once __DIR__ . '/WhatsAppOtpChannel.php';
require_once __DIR__ . '/LogOtpChannel.php';
require_once __DIR__ . '/OtpChannelManager.php';
require_once __DIR__ . '/LoginMethodResolver.php';
