<?php
/**
 *
 * phpBB Browser Push Notifications. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2023, phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\webpushnotifications\notification\method;

use Minishlink\WebPush\Subscription;
use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\log\log_interface;
use phpbb\notification\method\messenger_base;
use phpbb\notification\type\type_interface;
use phpbb\user;
use phpbb\user_loader;
use phpbb\webpushnotifications\form\form_helper;
use phpbb\webpushnotifications\json\sanitizer as json_sanitizer;

/**
* Web Push notification method class
* This class handles sending push messages for notifications
*/

class webpush extends messenger_base implements extended_method_interface
{
	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var log_interface */
	protected $log;

	/** @var user */
	protected $user;

	/** @var string Notification Web Push table */
	protected $notification_webpush_table;

	/** @var string Notification push subscriptions table */
	protected $push_subscriptions_table;

	/**
	 * Notification Method Web Push constructor
	 *
	 * @param config $config
	 * @param driver_interface $db
	 * @param log_interface $log
	 * @param user_loader $user_loader
	 * @param user $user
	 * @param string $phpbb_root_path
	 * @param string $php_ext
	 * @param string $notification_webpush_table
	 * @param string $push_subscriptions_table
	 */
	public function __construct(config $config, driver_interface $db, log_interface $log, user_loader $user_loader, user $user, string $phpbb_root_path,
								string $php_ext, string $notification_webpush_table, string $push_subscriptions_table)
	{
		parent::__construct($user_loader, $phpbb_root_path, $php_ext);

		$this->config = $config;
		$this->db = $db;
		$this->log = $log;
		$this->user = $user;
		$this->notification_webpush_table = $notification_webpush_table;
		$this->push_subscriptions_table = $push_subscriptions_table;
	}

	/**
	* {@inheritDoc}
	*/
	public function get_type(): string
	{
		return 'notification.method.webpush';
	}

	/**
	* {@inheritDoc}
	*/
	public function is_available(type_interface $notification_type = null): bool
	{
		return parent::is_available($notification_type) && $this->config['webpush_enable']
			&& !empty($this->config['webpush_vapid_public']) && !empty($this->config['webpush_vapid_private']);
	}

	/**
	* {@inheritdoc}
	*/
	public function get_notified_users($notification_type_id, array $options): array
	{
		$notified_users = [];

		$sql = 'SELECT user_id
			FROM ' . $this->notification_webpush_table . '
			WHERE notification_type_id = ' . (int) $notification_type_id .
			(isset($options['item_id']) ? ' AND item_id = ' . (int) $options['item_id'] : '') .
			(isset($options['item_parent_id']) ? ' AND item_parent_id = ' . (int) $options['item_parent_id'] : '') .
			(isset($options['user_id']) ? ' AND user_id = ' . (int) $options['user_id'] : '');
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$notified_users[$row['user_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $notified_users;
	}

	/**
	* Parse the queue and notify the users
	*/
	public function notify()
	{
		$insert_buffer = new \phpbb\db\sql_insert_buffer($this->db, $this->notification_webpush_table);

		/** @var type_interface $notification */
		foreach ($this->queue as $notification)
		{
			$data = $notification->get_insert_array();
			$data += [
				'push_data'		=> json_encode([
					'heading'	=> $this->config['sitename'],
					'title'		=> strip_tags($notification->get_title()),
					'text'		=> strip_tags($notification->get_reference()),
					'url'		=> htmlspecialchars_decode($notification->get_url()),
					'avatar'	=> $notification->get_avatar(),
				]),
				'notification_time'		=> time(),
			];
			$data = self::clean_data($data);
			$insert_buffer->insert($data);
		}

		$insert_buffer->flush();

		$this->notify_using_webpush();

		return false;
	}

	/**
	 * Notify using Web Push
	 *
	 * @return void
	 */
	protected function notify_using_webpush(): void
	{
		if (empty($this->queue))
		{
			return;
		}

		// Load all users we want to notify
		$user_ids = [];
		foreach ($this->queue as $notification)
		{
			$user_ids[] = $notification->user_id;
		}

		// Do not send push notifications to banned users
		if (!function_exists('phpbb_get_banned_user_ids'))
		{
			include($this->phpbb_root_path . 'includes/functions_user.' . $this->php_ext);
		}
		$banned_users = phpbb_get_banned_user_ids($user_ids);

		// Load all the users we need
		$notify_users = array_diff($user_ids, $banned_users);
		$this->user_loader->load_users($notify_users, array(USER_IGNORE));

		// Get subscriptions for users
		$user_subscription_map = $this->get_user_subscription_map($notify_users);

		$auth = [
			'VAPID' => [
				'subject' => generate_board_url(false),
				'publicKey' => $this->config['webpush_vapid_public'],
				'privateKey' => $this->config['webpush_vapid_private'],
			],
		];

		$web_push = new \Minishlink\WebPush\WebPush($auth);

		$number_of_notifications = 0;
		$remove_subscriptions = [];

		// Time to go through the queue and send notifications
		/** @var type_interface $notification */
		foreach ($this->queue as $notification)
		{
			$user = $this->user_loader->get_user($notification->user_id);

			$user_subscriptions = $user_subscription_map[$notification->user_id] ?? [];

			if ($user['user_type'] == USER_INACTIVE && $user['user_inactive_reason'] == INACTIVE_MANUAL
				|| empty($user_subscriptions))
			{
				continue;
			}

			// Add actual Web Push data
			$data = [
				'item_id'	=> $notification->item_id,
				'type_id'	=> $notification->notification_type_id,
			];
			$json_data = json_encode($data);

			foreach ($user_subscriptions as $subscription)
			{
				try
				{
					$push_subscription = Subscription::create([
						'endpoint'			=> $subscription['endpoint'],
						'keys'				=> [
							'p256dh'	=> $subscription['p256dh'],
							'auth'		=> $subscription['auth'],
						],
					]);
					$web_push->queueNotification($push_subscription, $json_data);
					$number_of_notifications++;
				}
				catch (\ErrorException $exception)
				{
					$remove_subscriptions[] = $subscription['subscription_id'];
					$this->log->add('user', $user['user_id'], $user['user_ip'] ?? '', 'LOG_WEBPUSH_SUBSCRIPTION_REMOVED', false, [
						'reportee_id' => $user['user_id'],
						$user['username'],
					]);
				}
			}
		}

		// Remove any subscriptions that couldn't be queued, i.e. that have invalid data
		if (count($remove_subscriptions))
		{
			$sql = 'DELETE FROM ' . $this->push_subscriptions_table . '
				WHERE ' . $this->db->sql_in_set('subscription_id', $remove_subscriptions);
			$this->db->sql_query($sql);
		}

		try
		{
			foreach ($web_push->flush($number_of_notifications) as $report)
			{
				if (!$report->isSuccess())
				{
					$report_data = json_sanitizer::sanitize($report->jsonSerialize());
					$this->log->add('admin', ANONYMOUS, '', 'LOG_WEBPUSH_MESSAGE_FAIL', false, [$report_data['reason']]);
				}
			}
		}
		catch (\ErrorException $exception)
		{
			$this->log->add('critical', ANONYMOUS, '', 'LOG_WEBPUSH_MESSAGE_FAIL', false, [$exception->getMessage()]);
		}

		// We're done, empty the queue
		$this->empty_queue();
	}

	/**
	* {@inheritdoc}
	*/
	public function mark_notifications($notification_type_id, $item_id, $user_id, $time = false, $mark_read = true)
	{
		$sql = 'DELETE FROM ' . $this->notification_webpush_table . '
			WHERE ' . ($notification_type_id !== false ? $this->db->sql_in_set('notification_type_id', is_array($notification_type_id) ? $notification_type_id : [$notification_type_id]) : '1=1') .
			($user_id !== false ? ' AND ' . $this->db->sql_in_set('user_id', $user_id) : '') .
			($item_id !== false ? ' AND ' . $this->db->sql_in_set('item_id', $item_id) : '');
		$this->db->sql_query($sql);
	}

	/**
	* {@inheritdoc}
	*/
	public function mark_notifications_by_parent($notification_type_id, $item_parent_id, $user_id, $time = false, $mark_read = true)
	{
		$sql = 'DELETE FROM ' . $this->notification_webpush_table . '
			WHERE ' . ($notification_type_id !== false ? $this->db->sql_in_set('notification_type_id', is_array($notification_type_id) ? $notification_type_id : [$notification_type_id]) : '1=1') .
			($user_id !== false ? ' AND ' . $this->db->sql_in_set('user_id', $user_id) : '') .
			($item_parent_id !== false ? ' AND ' . $this->db->sql_in_set('item_parent_id', $item_parent_id, false, true) : '');
		$this->db->sql_query($sql);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prune_notifications($timestamp, $only_read = true): void
	{
		$sql = 'DELETE FROM ' . $this->notification_webpush_table . '
			WHERE notification_time < ' . (int) $timestamp;
		$this->db->sql_query($sql);

		$this->config->set('read_notification_last_gc', (string) time(), false);
	}

	/**
	 * Clean data to contain only what we need for webpush notifications table
	 *
	 * @param array $data Notification data
	 * @return array Cleaned notification data
	 */
	public static function clean_data(array $data): array
	{
		$row = [
			'notification_type_id'	=> null,
			'item_id'				=> null,
			'item_parent_id'		=> null,
			'user_id'				=> null,
			'push_data'				=> null,
			'notification_time'		=> null,
		];

		return array_intersect_key($data, $row);
	}

	public function get_ucp_template_data(helper $controller_helper, form_helper $form_helper): array
	{
		$subscription_map = $this->get_user_subscription_map([$this->user->id()]);
		$subscriptions = [];

		if (isset($subscription_map[$this->user->id()]))
		{
			foreach ($subscription_map[$this->user->id()] as $subscription)
			{
				$subscriptions[] = [
					'endpoint'			=> $subscription['endpoint'],
					'expirationTime'	=> $subscription['expiration_time'],
				];
			}
		}

		return [
			'NOTIFICATIONS_WEBPUSH_ENABLE'	=> true,
			'U_WEBPUSH_SUBSCRIBE'			=> $controller_helper->route('phpbb_webpushnotifications_ucp_push_subscribe_controller'),
			'U_WEBPUSH_UNSUBSCRIBE'			=> $controller_helper->route('phpbb_webpushnotifications_ucp_push_unsubscribe_controller'),
			'VAPID_PUBLIC_KEY'				=> $this->config['webpush_vapid_public'],
			'U_WEBPUSH_WORKER_URL'			=> $controller_helper->route('phpbb_webpushnotifications_ucp_push_worker_controller'),
			'SUBSCRIPTIONS'					=> $subscriptions,
			'WEBPUSH_FORM_TOKENS'			=> $form_helper->get_form_tokens(\phpbb\webpushnotifications\ucp\controller\webpush::FORM_TOKEN_UCP),
		];
	}

	/**
	 * Get subscriptions for notify users
	 *
	 * @param array $notify_users Users to notify
	 *
	 * @return array Subscription map
	 */
	protected function get_user_subscription_map(array $notify_users): array
	{
		// Get subscriptions for users
		$user_subscription_map = [];

		$sql = 'SELECT subscription_id, user_id, endpoint, p256dh, auth, expiration_time
			FROM ' . $this->push_subscriptions_table . '
			WHERE ' . $this->db->sql_in_set('user_id', $notify_users);
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$user_subscription_map[$row['user_id']][] = $row;
		}
		$this->db->sql_freeresult($result);

		return $user_subscription_map;
	}
}
