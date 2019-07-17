<?php
/*
*  ====>
*
*   Save a slide. Whether a user is allowed to access this
*   API endpoint depends on the parameters passed to the
*   endpoint.
*
*  Permissions
*   * id != null => Allow if the caller is in the admin
*     group or the caller is the owner of the slide and is
*     in the editor group.
*   * id == null => Allow if the caller is in the admin or
*     editor group.
*   * Otherwise allow restricted access if the caller is in
*     the collaborators array of the slide. In this case the
*     queue_name and collaborators parameters of the API
*     call are silently discarded.
*
*  This endpoint only allows slide modification if the caller
*  has locked the slide by calling slide_lock_acquire.php first.
*  If the slide is not locked or is locked by someone else, the
*  '424 Failed Dependency' status code is returned. If a new
*  slide is created, the slide is automatically locked for the
*  caller.
*
*  Note!
*
*  This endpoint accepts a few unused parameters to simplify
*  implementing the client interface for this endpoint.
*  Specifically, clients *must* be able to send all data
*  received from slide_get.php back to this endpoint, even if
*  it's not actually used. This makes it possible to implement
*  a simple Object Oriented interface that just sends all data
*  fields in the client side Slide object to this endpoint
*  without filtering what can be sent.
*
*  **Request:** POST, application/json
*
*  Parameters
*    * id            = The ID of the slide to modify or either
*                      undefined or null for new slide.
*    * name          = The name of the slide.
*    * index         = The index of the slide.
*    * duration      = The duration of the slide.
*    * markup        = The markup of the slide.
*    * enabled       = Whether the slide is enabled or not.
*    * sched         = Whether the slide is scheduled or not.
*    * sched_t_s     = The slide schedule starting timestamp.
*    * sched_t_e     = The slide schedule ending timestamp.
*    * animation     = The slide animation identifier.
*    * queue_name    = The name of the slide queue of this slide.
*    * collaborators = A list of slide collaborators.
*    * owner         = Unused (see above)
*    * lock          = Unused (see above)
*    * assets        = Unused (see above)
*
*  Return value
*    * This endpoint returns all the parameters above.
*
*  <====
*/

namespace pub\api\endpoints\slide;

require_once($_SERVER['DOCUMENT_ROOT'].'/../common/php/Config.php');

use \api\APIEndpoint;
use \api\APIException;
use \api\HTTPStatus;
use \common\php\Queue;
use \common\php\slide\Slide;
use \common\php\auth\User;
use \common\php\auth\Session;

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
					'id' => ['type' => ['string', 'null']],
					'name' => ['type' => 'string'],
					'index' => ['type' => 'integer'],
					'markup' => ['type' => 'string'],
					'owner' => [],
					'duration' => ['type' => 'integer'],
					'enabled' => ['type' => 'boolean'],
					'sched' => ['type' => 'boolean'],
					'sched_t_s' => ['type' => 'integer'],
					'sched_t_e' => ['type' => 'integer'],
					'animation' => ['type' => 'integer'],
					'queue_name' => ['type' => 'string'],
					'collaborators' => [
						'type' => 'array',
						'items' => [
							'type' => 'string'
						]
					],
					'lock' => [],
					'assets' => []
				],
				'required' => [
					'name',
					'index',
					'markup',
					'duration',
					'enabled',
					'sched',
					'sched_t_s',
					'sched_t_e',
					'animation',
					'queue_name',
					'collaborators'
				]
			]
		]
	],
	function($req, $module_data) {
		$params = $module_data['APIJSONValidatorModule'];
		$session = $module_data['APIAuthModule']['session'];
		$caller = $module_data['APIAuthModule']['user'];
		$slide = new Slide();

		/*
		* Check permissions and route the API call to the correct
		* handler function.
		*/
		if (property_exists($params, 'id') && $params->id !== NULL) {
			$slide->load($params->id);
			if (
				$caller->is_in_group('admin')
				|| (
					$caller->is_in_group('editor')
					&& $caller->get_name() === $slide->get_owner()
				)
			) {
				// admin or editor+owner => ALLOW modifying.
				return modify_slide($session, $slide, $params, TRUE);
			} else if (
				$caller->is_in_group('editor')
				&& in_array($caller->get_name(), $slide->get_collaborators())
			) {
				// Restricted modification permissions for collaborators.
				return modify_slide($session, $slide, $params, FALSE);
			} else {
				throw new APIException(
					'User not authorized to do this operation.',
					HTTPStatus::UNAUTHORIZED
				);
			}
		} else if (
			$caller->is_in_group('admin')
			|| $caller->is_in_group('editor')
		) {
			// admin or editor => ALLOW creation.
			return create_slide($caller, $session, $slide, $params);
		} else {
			throw new APIException(
				'User not authorized to do this operation.',
				HTTPStatus::UNAUTHORIZED
			);
		}
	}
);

function ensure_slide_lock(Slide $slide, Session $session): void {
	/*
	*  Ensure that the slide $slide is locked by $session and
	*  the lock is not expired.
	*/
	$lock = $slide->get_lock();
	if ($lock === NULL) {
		throw new APIException(
			'Slide not locked.',
			HTTPStatus::FAILED_DEPENDENCY
		);
	} else if (!$lock->is_expired() && !$lock->is_owned_by($session)) {
		throw new APIException(
			'Slide locked by another user.',
			HTTPStatus::FAILED_DEPENDENCY
		);
	}	
}

function set_slide_data(Slide $slide, $data, bool $owner): void {
	/*
	*  Set the slide data of $slide.
	*/

	// Don't set 'queue_name' and 'collaborators' if $owner === FALSE.
	if ($owner === TRUE) {
		$slide->set_queue($data->queue_name);
		$slide->set_collaborators($data->collaborators);
	}

	$slide->set_name($data->name);
	$slide->set_index($data->index);
	$slide->set_duration($data->duration);
	$slide->set_markup($data->markup);
	$slide->set_enabled($data->enabled);
	$slide->set_sched($data->sched);
	$slide->set_sched_t_s($data->sched_t_s);
	$slide->set_sched_t_e($data->sched_t_e);
	$slide->set_animation($data->animation);
	$slide->check_sched_enabled();
}

function create_slide(User $caller, Session $session, Slide $slide, $data) {
	/*
	*  Handler function for creating the slide $slide.
	*
	*  Note that Slide::set_owner() must be called
	*  before Slide::set_collaborators()!
	*/
	$slide->gen_id();
	$slide->set_owner($caller->get_name());
	$slide->lock_acquire($session);

	set_slide_data($slide, $data, TRUE);
	if (!$caller->get_quota()->has_quota('slides')) {
		/*
		* The user doesn't have slide quota so abort the slide
		* creation process. Remove the broken slide from $queue
		* by calling Queue::remove_broken_slides().
		*/
		$queue = new Queue();
		$queue->load($slide->get_queue_name());
		$queue->remove_slide($slide);
		$queue->write();

		throw new APIException(
			'Slide quota exceeded.',
			HTTPStatus::FORBIDDEN
		);
	} else {
		$caller->get_quota()->use_quota('slides');
		$caller->write();
	}
	return finish($slide);
}

function modify_slide(Session $session, Slide $slide, $data, bool $owner) {
	/*
	*  Handler function for modifying $slide.
	*/
	ensure_slide_lock($slide, $session);
	set_slide_data($slide, $data, $owner);
	return finish($slide);
}

function finish(Slide $slide) {
	$slide->write();

	// Juggle slide indices.
	$queue = new Queue();
	$queue->load($slide->get_queue_name());
	$queue->juggle($slide->get_id());

	// Get the slide data from $queue since $queue->juggle() modifies it.
	return $queue->get_slide($slide->get_id())->export(FALSE, FALSE);
}
