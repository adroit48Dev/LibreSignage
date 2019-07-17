<?php
/*
*  ====>
*
*  Remove a slide queue and all slides in it. The operation is authorized
*  if the user is in the 'admin' group or if the user is in the editor
*  group and owns all the slides in the queue.
*
*  **Request:** POST, application/json
*
*  Parameters
*    * name = Queue name.
*
*  <====
*/

namespace pub\api\endpoints\queue;

require_once($_SERVER['DOCUMENT_ROOT'].'/../common/php/Config.php');

use \api\APIEndpoint;
use \api\APIException;
use \api\HTTPStatus;
use \common\php\slide\Slide;
use \common\php\Queue;
use \common\php\Util;

APIEndpoint::POST(
	[
		'APIAuthModule' => [
			'cookie_auth' => FALSE
		],
		'APIRateLimitModule' => [],
		'APIJSONValidatorModule' => [
			'schema' => [
				'type' => 'object',
				'properties' => [
					'name' => [
						'type' => 'string'
					]
				],
				'required' => ['name']
			]
		]
	],
	function($req, $module_data) {
		$caller = $module_data['APIAuthModule']['user'];
		$params = $module_data['APIJSONValidatorModule'];

		$queue = new Queue();
		$queue->load($params->name);
		$owner = $queue->get_owner();

		if (
			$caller->is_in_group('admin')
			|| (
				$caller->is_in_group('editor')
				&& Util::array_check($queue->slides(), function($s) use($caller) {
					return $s->get_owner() === $caller->get_name();
				})
			)
		) {
			$queue->remove();
			return [];
		}
		throw new APIException(
			'Non-admin user not authorized.',
			HTTPStatus::UNAUTHORIZED
		);
	}
);
