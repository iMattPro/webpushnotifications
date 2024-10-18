<?php
/**
 *
 * phpBB Browser Push Notifications. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2024, phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ » “ ” …
//

$lang = array_merge($lang, [
	'HELP_FAQ_WPN'					=> 'Web Push Notifications for Browsers',
	'HELP_FAQ_WPN_WHAT_QUESTION'	=> 'What are web push notifications?',
	'HELP_FAQ_WPN_WHAT_ANSWER'		=> 'Web push notifications enhance phpBB’s notification system by allowing real-time notifications to be sent directly to your desktop or mobile device, even if you’re not actively browsing the forum. These notifications function like app alerts, providing instant updates for private messages, post interactions, moderation actions, and more.',
	'HELP_FAQ_WPN_HOW_QUESTION'		=> 'How can I receive forum notification alerts on my computer or mobile device?',
	'HELP_FAQ_WPN_HOW_ANSWER'		=> 'Navigate to ‘Notification options’ in your UCP (User Control Panel) and click ‘Enable web push notifications.’ After enabling it, your browser may prompt you to allow the forum to send notifications to your device—make sure to grant permission. If you’re still not receiving notifications, check your device’s system settings to ensure notifications are enabled for your browser.',
	'HELP_FAQ_WPN_IOS_QUESTION'		=> 'How does it work on mobile devices (iOS/Android)?',
	'HELP_FAQ_WPN_IOS_ANSWER'		=> 'For certain devices, such as those running iOS or iPadOS, you may need to add the forum site to your Home Screen for push notifications to work. This essentially makes the forum function like a standalone web app. Refer to your device’s operating system instructions to enable push notifications for <a href="https://www.xda-developers.com/how-enable-safari-notifications-iphone/" target="_blank">iOS / iPadOS</a> or <a href="https://support.google.com/chrome/answer/3220216?hl=en&co=GENIE.Platform%3DAndroid&oco=0" target="_blank">Android</a>.',
	'HELP_FAQ_WPN_SESSION_QUESTION'	=> 'Will I receive notifications if I am logged out?',
	'HELP_FAQ_WPN_SESSION_ANSWER'	=> 'Yes, you will continue to receive notifications even if you’re logged out.',
	'HELP_FAQ_WPN_SUBBING_QUESTION'	=> 'Why are the “Enable Push Notifications” buttons disabled?',
	'HELP_FAQ_WPN_SUBBING_ANSWER'	=> 'If the “Enable Push Notifications” buttons appear but cannot be clicked, your browser or device likely doesn’t support push notifications. Try using a different browser or device that supports this feature.',
	'HELP_FAQ_WPN_GENERAL_QUESTION'	=> 'What if I’m still having trouble receiving notifications after enabling them?',
	'HELP_FAQ_WPN_GENERAL_ANSWER'	=> 'Make sure this forum is allowed to send notifications in your browser settings. Also, verify that your device’s system settings permit notifications from your web browser or app. Some browsers deliver notifications even when closed, while others only do so when the browser is open. <a href="https://github.com/phpbb-extensions/webpushnotifications?tab=readme-ov-file#browser-support" target="_blank">Here is a browser support table.</a>',
]);
