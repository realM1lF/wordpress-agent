<?php

namespace Mohami\Agent\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ChatController extends WP_REST_Controller {
    protected $namespace = 'mohami-agent/v1';
    protected $rest_base = 'chat';

    public function __construct() {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'sendMessage'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => [
                    'message' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'session_id' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<session_id>[a-zA-Z0-9_-]+)/history', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getHistory'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);
    }

    public function checkPermission(): bool {
        return current_user_can('edit_posts');
    }

    public function sendMessage(WP_REST_Request $request): WP_REST_Response {
        $message = $request->get_param('message');
        $sessionId = $request->get_param('session_id') ?? uniqid('sess_', true);

        // TODO: Connect to AI service
        $response = [
            'session_id' => $sessionId,
            'message' => 'Hallo! Ich bin dein WordPress KI-Assistent. (Noch in Entwicklung)',
            'timestamp' => current_time('mysql'),
        ];

        return new WP_REST_Response($response, 200);
    }

    public function getHistory(WP_REST_Request $request): WP_REST_Response {
        $sessionId = $request->get_param('session_id');
        
        // TODO: Load from database
        return new WP_REST_Response([
            'session_id' => $sessionId,
            'messages' => [],
        ], 200);
    }
}
