<?php
/**
 *
 * phpBB Browser Push Notifications. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\webpushnotifications\tests\controller;

use phpbb\exception\http_exception;
use phpbb\notification\type\quote;
use phpbb\request\request_interface;
use phpbb\webpushnotifications\ucp\controller\webpush;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class controller_webpush_test extends \phpbb_database_test_case
{
	protected $auth;
	protected $avatar_helper;
	protected $config;
	protected $controller;
	protected $controller_helper;
	protected $db;
	protected $form_helper;
	protected $language;
	protected $notification;
	protected $notification_manager;
	protected $path_helper;
	protected $phpbb_root_path;
	protected $php_ext;
	protected $request;
	protected $template;
	protected $user;
	protected $user_loader;

	protected static function setup_extensions()
	{
		return ['phpbb/webpushnotifications'];
	}

	protected function getDataSet()
	{
		return $this->createXMLDataSet(__DIR__ . '/fixtures/webpush.xml');
	}

	protected function setUp(): void
	{
		global $auth, $config, $phpbb_dispatcher, $user, $phpbb_root_path, $phpEx;

		parent::setUp();

		$phpbb_dispatcher = new \phpbb_mock_event_dispatcher();

		$this->auth = $this->createMock(\phpbb\auth\auth::class);
		$auth = $this->auth;
		$this->config = new \phpbb\config\config([
			'allow_nocensors'	=> false,
			'sitename'			=> 'yourdomain.com',
		]);
		$config = $this->config;
		$this->controller_helper = $this->createMock(\phpbb\controller\helper::class);
		$this->db = $this->new_dbal();
		$this->form_helper = $this->createMock(\phpbb\webpushnotifications\form\form_helper::class);
		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);
		$this->notification_manager = $this->createMock(\phpbb\notification\manager::class);
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $phpEx;
		$symfony_request = $this->createMock(\phpbb\symfony_request::class);
		$this->request = $this->createMock(request_interface::class);
		$this->template = $this->createMock(\Twig\Environment::class);
		$this->user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user = $this->user;
		$this->user_loader = new \phpbb\user_loader($this->db, $this->phpbb_root_path, $this->php_ext, 'phpbb_users');
		$this->path_helper = new \phpbb\path_helper($symfony_request, new \phpbb\filesystem\filesystem(), $this->request, $phpbb_root_path, $phpEx);

		$this->controller = new webpush(
			$this->config,
			$this->controller_helper,
			$this->db,
			$this->form_helper,
			$this->language,
			$this->notification_manager,
			$this->path_helper,
			$this->request,
			$this->user_loader,
			$this->user,
			$this->template,
			'phpbb_wpn_notification_push',
			'phpbb_wpn_push_subscriptions'
		);
	}

	private function setupCommonMocks(): void
	{
		global $cache;
		$cache = $this->createMock(\phpbb\cache\service::class);
		$cache->method('obtain_word_list')->willReturn([]);

		$this->auth->method('acl_get')->willReturn(true);

		$this->notification_manager->method('get_item_type_class')
			->willReturnCallback($this->getNotificationTypeCallback());
	}

	private function getNotificationTypeCallback(): callable
	{
		return function(string $type_name, array $row_data) {
			$notification_type = new quote(
				$this->db,
				$this->language,
				$this->user,
				$this->auth,
				$this->phpbb_root_path,
				$this->php_ext,
				'phpbb_notifications'
			);

			$notification_type->set_user_loader($this->user_loader);
			$notification_type->set_initial_data($row_data);

			return $notification_type;
		};
	}

	private function setupUserData(int $user_id): void
	{
		$this->user->data = [
			'is_bot'        => false,
			'user_type'     => USER_NORMAL,
			'user_id'       => $user_id,
			'user_options'  => 230271,
		];
	}

	private function getExpectedResponse(): array
	{
		return [
			'heading' => 'yourdomain.com',
			'title' => 'Quoted by Guest in:',
			'text' => '"Welcome to phpBB3"',
			'url' => 'phpBB/viewtopic.php?p=1#p1',
			'avatar' => ['src' => ''],
		];
	}

	public function data_notification_exceptions(): array
	{
		return [
			'not_ajax' => [
				false,
				false,
				USER_NORMAL,
				2,
				[],
				'NO_AUTH_OPERATION',
			],
			'is_bot' => [
				true,
				true,
				USER_NORMAL,
				2,
				[],
				'NO_AUTH_OPERATION',
			],
			'inactive_user' => [
				true,
				false,
				USER_INACTIVE,
				2,
				[],
				'NO_AUTH_OPERATION',
			],
			'ignore_user' => [
				true,
				false,
				USER_IGNORE,
				2,
				[],
				'NO_AUTH_OPERATION',
			],
			'no_notification' => [
				true,
				false,
				USER_NORMAL,
				2,
				[],
				'AJAX_ERROR_TEXT',
			],
			'no_notification_anonymous' => [
				true,
				false,
				USER_NORMAL,
				ANONYMOUS,
				[
					['token', '', false, request_interface::REQUEST, 'foobar'],
				],
				'AJAX_ERROR_TEXT',
			],
			'no_notification_anonymous_no_token' => [
				true,
				false,
				USER_NORMAL,
				ANONYMOUS,
				[],
				'NO_AUTH_OPERATION',
			],
		];
	}

	/**
	 * @dataProvider data_notification_exceptions
	 */
	public function test_notification_no_data($is_ajax, $is_bot, $user_type, $user_id, $request_data, $expected_message)
	{
		$this->request->method('is_ajax')->willReturn($is_ajax);
		$this->request->method('variable')->willReturnMap($request_data);
		$this->user->data = [
			'is_bot'		=> $is_bot,
			'user_type'		=> $user_type,
			'user_id'		=> $user_id,
		];

		$this->expectException(http_exception::class);
		$this->expectExceptionMessage($expected_message);

		$this->controller->notification();
	}

	public function test_get_user_notification()
	{
		$this->setupCommonMocks();

		$this->request->method('is_ajax')->willReturn(true);
		$this->request->method('variable')
			->willReturnMap([
				['token', '', false, request_interface::REQUEST, 'foobar'],
				['item_id', 0, false, request_interface::REQUEST, 1],
				['type_id', 0, false, request_interface::REQUEST, 4],
			]);
		$this->setupUserData(2);

		$json_response = $this->controller->notification();

		$response_data = json_decode($json_response->getContent(), true);

		$this->assertEquals($this->getExpectedResponse(), $response_data);
	}

	public function test_get_user_notification_anonymous()
	{
		$this->setupCommonMocks();

		$this->request->method('is_ajax')->willReturn(true);
		$this->request->method('variable')
			->willReturnMap([
				['token', '', false, request_interface::REQUEST, '0ccf8fcd96a66297b77b66109cbe9870e1a6fa66e42b9bf36d1f2c7263240058'],
				['item_id', 0, false, request_interface::REQUEST, 1],
				['type_id', 0, false, request_interface::REQUEST, 4],
				['user_id', 0, false, request_interface::REQUEST, 2],
			]);
		$this->setupUserData(ANONYMOUS);

		$json_response = $this->controller->notification();

		$response_data = json_decode($json_response->getContent(), true);

		$this->assertEquals($this->getExpectedResponse(), $response_data);
	}

	public function test_get_user_notification_anonymous_invalid_token()
	{
		$this->setupCommonMocks();

		$this->request->method('is_ajax')->willReturn(true);
		$this->request->method('variable')
			->willReturnMap([
				['token', '', false, request_interface::REQUEST, '488c17afe4f18714c235b395e21b78df1c3d78bf1e13d0633ed9425d2eebf967'],
				['item_id', 0, false, request_interface::REQUEST, 1],
				['type_id', 0, false, request_interface::REQUEST, 4],
				['user_id', 0, false, request_interface::REQUEST, 2],
			]);
		$this->setupUserData(ANONYMOUS);

		$this->expectException(http_exception::class);
		$this->expectExceptionMessage('NO_AUTH_OPERATION');

		$this->controller->notification();
	}

	public function test_get_user_notification_legacy()
	{
		$this->setupCommonMocks();

		$this->request->method('is_ajax')->willReturn(true);
		$this->request->method('variable')
			->willReturnMap([
				['token', '', false, request_interface::REQUEST, 'foobar'],
				['item_id', 0, false, request_interface::REQUEST, 2],
				['type_id', 0, false, request_interface::REQUEST, 4],
			]);
		$this->setupUserData(2);

		$json_response = $this->controller->notification();

		$response_data = json_decode($json_response->getContent(), true);

		$this->assertEquals($this->getExpectedResponse(), $response_data);
	}

	/**
	 * @throws SyntaxError
	 * @throws RuntimeError
	 * @throws LoaderError
	 */
	public function test_worker()
	{
		$this->template->method('render')->willReturn('rendered_content');
		$this->controller_helper->method('route')->willReturn('test_route');
		$this->config['assets_version'] = '1.0';

		$response = $this->controller->worker();

		$this->assertEquals('text/javascript; charset=UTF-8', $response->headers->get('Content-Type'));
		$this->assertEquals('rendered_content', $response->getContent());
		$this->assertNull($response->headers->get('X-PHPBB-IS-BOT'));
	}

	/**
	 * @throws RuntimeError
	 * @throws SyntaxError
	 * @throws LoaderError
	 */
	public function test_worker_bot()
	{
		$this->template->method('render')->willReturn('rendered_content');
		$this->controller_helper->method('route')->willReturn('test_route');
		$this->config['assets_version'] = '1.0';
		$this->user->data['is_bot'] = true;

		$response = $this->controller->worker();

		$this->assertEquals('yes', $response->headers->get('X-PHPBB-IS-BOT'));
	}

	/**
	 * @throws \ReflectionException
	 */
	public function test_check_subscribe_requests_invalid_form_token()
	{
		$this->form_helper->method('check_form_tokens')->willReturn(false);

		$this->expectException(http_exception::class);
		$this->expectExceptionMessage('FORM_INVALID');

		$check_subscribe_reflection = new ReflectionMethod($this->controller, 'check_subscribe_requests');
		$check_subscribe_reflection->setAccessible(true);
		$check_subscribe_reflection->invoke($this->controller);
	}

	/**
	 * @throws \ReflectionException
	 */
	public function test_check_subscribe_requests_anonymous_user()
	{
		$this->form_helper->method('check_form_tokens')->willReturn(true);
		$this->request->method('is_ajax')->willReturn(true);
		$this->user->data['user_id'] = ANONYMOUS;

		$this->expectException(http_exception::class);
		$this->expectExceptionMessage('NO_AUTH_OPERATION');

		$check_subscribe_reflection = new ReflectionMethod($this->controller, 'check_subscribe_requests');
		$check_subscribe_reflection->setAccessible(true);
		$check_subscribe_reflection->invoke($this->controller);
	}

	public function test_sub_unsubscribe_success()
	{
		$this->form_helper->method('check_form_tokens')->willReturn(true);
		$this->request->method('is_ajax')->willReturn(true);
		$this->user->data['user_id'] = 2;
		$this->user->data['is_bot'] = false;
		$this->user->data['user_type'] = USER_NORMAL;

		$symfony_request = $this->createMock(\phpbb\symfony_request::class);
		$symfony_request->method('get')->willReturn(json_encode([
			'endpoint' => 'test_endpoint',
			'expiration_time' => 0,
			'keys' => ['p256dh' => 'test_p256dh', 'auth' => 'test_auth']
		]));

		$response = $this->controller->subscribe($symfony_request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(['success' => true, 'form_tokens' => $this->form_helper->get_form_tokens(webpush::FORM_TOKEN_UCP)], json_decode($response->getContent(), true));

		$this->assertEquals([
			'user_id' => '2',
			'endpoint' => 'test_endpoint',
			'p256dh' => 'test_p256dh',
			'auth' => 'test_auth',
			'expiration_time' => '0',
			'subscription_id' => '1',
		], $this->get_subscription_data());

		// Now unsubscribe
		$response = $this->controller->unsubscribe($symfony_request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(['success' => true, 'form_tokens' => $this->form_helper->get_form_tokens(webpush::FORM_TOKEN_UCP)], json_decode($response->getContent(), true));

		$this->assertEmpty($this->get_subscription_data());
	}

	private function get_subscription_data()
	{
		$sql = 'SELECT *
			FROM phpbb_wpn_push_subscriptions
			WHERE user_id = 2';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}
}
