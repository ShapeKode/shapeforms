<?php
/**
 * AI Form Builder.
 *
 * Generates a complete form structure from a plain-language description. The
 * admin types "a job application form with a CV upload"; the module turns that
 * into real ShapeForms fields on the builder canvas.
 *
 * ── How it works ─────────────────────────────────────────────────────────────
 *
 *   1. A "Create with AI" card is injected beside the Blank Form / Use a
 *      Template cards on the builder's starting screens (pure JS — see
 *      assets/js/ai-builder.js).
 *   2. The prompt is POSTed to this module's REST route, which is nonce- and
 *      capability-gated.
 *   3. PHP calls the language model **server-side** and constrains the reply
 *      with a JSON schema whose `type` enum is built from the site's REAL field
 *      registry (`shapeforms_allowed_field_types`). The model therefore cannot
 *      invent a field type this install does not have.
 *   4. The validated spec is returned to the builder, which maps it to Lite's
 *      rows -> columns -> fields structure and hands it to Lite's own
 *      normalizeStructure()/renderAll() — the same path a template takes.
 *
 * ── One transport ───────────────────────────────────────────────────────────
 *
 * The site calls the chosen provider directly (Anthropic, OpenAI, Gemini or
 * OpenRouter; see `providers()`) with a key the admin supplied. There is no
 * ShapeForms infrastructure in the path and nothing is proxied — a prompt goes
 * from this site to that provider and nowhere else, which is also what the
 * readme's External Services section promises.
 *
 * ── Why the call is made from PHP, not the browser ───────────────────────────
 *
 * Calling the provider straight from admin JS would put the endpoint (and, on
 * the BYO path, the API key) in front of anyone who opens devtools, and would
 * leave the hosted endpoint open to the world. Routing through a REST route
 * keeps the credential server-side, lets the nonce + `manage_options` check do
 * real work, and gives one place to rate-limit.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds form structures from a natural-language description.
 */
class ShapeForms_Lite_AI_Builder {

	/**
	 * Option holding the site-owned provider API keys (BYO transport), as a
	 * provider-slug => key map.
	 *
	 * One key per provider rather than one key overall: a credential is only
	 * valid at the provider that issued it, so a single shared field means
	 * switching provider either sends the wrong key or silently discards the
	 * one already entered. Keeping them apart also lets an admin configure
	 * several and switch between them freely.
	 */
	const OPTION_API_KEYS = 'shapeforms_lite_ai_api_keys';

	/**
	 * Option holding which provider that key belongs to.
	 */
	const OPTION_PROVIDER = 'shapeforms_lite_ai_provider';

	/**
	 * Option holding per-provider model overrides, as a provider-slug => model
	 * map. Absent or empty means "use the provider's built-in default".
	 *
	 * Per provider rather than one value overall for the same reason the keys
	 * are: a model name only means something at the service that hosts it, so a
	 * single shared field would send `gpt-4o` to Google the moment the admin
	 * switched provider.
	 */
	const OPTION_MODELS = 'shapeforms_lite_ai_models';

	/**
	 * Ceiling on the reply, in tokens.
	 *
	 * A spec at the MAX_FIELDS limit runs to roughly half this, so it is
	 * headroom rather than a working limit. Sending it matters beyond good
	 * manners: omit it and a gateway assumes the model's own maximum, which on a
	 * credit-metered account is pre-billed and rejected outright with "requires
	 * more credits, or fewer max_tokens" before any generation happens.
	 */
	const MAX_OUTPUT_TOKENS = 8000;

	/**
	 * Provider used when the site has not chosen one.
	 */
	const DEFAULT_PROVIDER = 'anthropic';

	/**
	 * REST namespace/route for the generate call.
	 */
	const REST_NAMESPACE = 'shapeforms-lite/v1';
	const REST_ROUTE     = '/ai/generate';

	/**
	 * Anthropic API version pin, sent as a header on that provider only.
	 */
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Longest prompt we accept, in characters.
	 */
	const MAX_PROMPT_CHARS = 1000;

	/**
	 * Most fields we will build from one prompt.
	 *
	 * Counts section breaks and page breaks too, and a multi-step form spends a
	 * good number of its slots on those rather than on questions — hence the
	 * headroom over the 40 that sufficed while every form was one flat page.
	 */
	const MAX_FIELDS = 60;

	/**
	 * Per-user generation allowance and its window, in seconds.
	 */
	const RATE_LIMIT_MAX    = 20;
	const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Whether the feature can do anything at all.
	 *
	 * There is no on/off setting: a key IS the switch. The feature cannot
	 * generate without one, so a separate toggle would only add a state where
	 * it is switched on and still cannot work — two things to get wrong where
	 * one will do.
	 *
	 * @return bool
	 */
	/**
	 * The sparkle mark that stands for this feature in the admin.
	 *
	 * Dashicons has no sparkle, and the nearest candidates all say something
	 * else — `superhero-alt`, used here before, is a gem in a shield, which reads
	 * as "premium" on a feature that is free. A four-point star with a smaller
	 * companion is the mark every mainstream AI affordance uses, so it is the one
	 * glyph a user reads as "AI" before reading the label.
	 *
	 * The fill is a gradient rather than `currentColor` because the mark has to
	 * hold its meaning whatever colour surrounds it.
	 *
	 * Several of these can share a page, and SVG resolves `url(#id)` against the
	 * whole document, so the gradient id is suffixed per placement — a duplicate
	 * would make one mark paint with another's gradient. `sanitize_html_class()`
	 * guarantees the suffix is inert even though every caller passes a literal.
	 *
	 * The card in the form builder draws the same mark in JavaScript, because it
	 * is injected client-side (see `assets/js/ai-builder.js`). Change one, change
	 * the other.
	 *
	 * @param string $variant Placement, used to keep the gradient id unique.
	 * @return string Inline SVG markup, built entirely from literals.
	 */
	public static function sparkle_svg( $variant ) {
		$id = 'shapeforms-ai-spark-' . sanitize_html_class( $variant );

		return '<svg class="shapeforms-ai-spark" viewBox="1.9 0.45 24 24" aria-hidden="true" focusable="false">'
			. '<defs>'
				. '<linearGradient id="' . $id . '" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">'
					. '<stop offset="0" stop-color="#4f46e5"></stop>'
					. '<stop offset="0.55" stop-color="#9333ea"></stop>'
					. '<stop offset="1" stop-color="#d946ef"></stop>'
				. '</linearGradient>'
			. '</defs>'
			. '<path fill="url(#' . $id . ')" d="M12 2c.9 5.1 2 6.2 7.1 7.1-5.1.9-6.2 2-7.1 7.1-.9-5.1-2-6.2-7.1-7.1 5.1-.9 6.2-2 7.1-7.1Z"></path>'
			. '<path fill="url(#' . $id . ')" opacity="0.75" d="M18.5 14.1c.56 3.16 1.24 3.84 4.4 4.4-3.16.56-3.84 1.24-4.4 4.4-.56-3.16-1.24-3.84-4.4-4.4 3.16-.56 3.84-1.24 4.4-4.4Z"></path>'
			. '</svg>';
	}

	public static function is_configured() {
		return '' !== self::api_key_for( self::selected_provider() );
	}

	/**
	 * Registers module hooks.
	 *
	 * @param ShapeForms_Lite_Loader $loader Hook loader.
	 * @return void
	 */
	public function register_hooks( ShapeForms_Lite_Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_rest_route' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_builder_assets', 20 );
	}

	// =========================================================================
	// REST route
	// =========================================================================

	/**
	 * Registers the generate endpoint.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_generate' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'prompt' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Only form managers may spend a generation.
	 *
	 * The REST cookie authentication layer already verifies the `X-WP-Nonce`
	 * header for cookie-authenticated requests, so the capability check is the
	 * remaining gate.
	 *
	 * @return bool
	 */
	public function can_generate() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Turns a prompt into a validated field spec.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate( WP_REST_Request $request ) {
		$prompt = trim( (string) $request->get_param( 'prompt' ) );

		if ( '' === $prompt ) {
			return new WP_Error(
				'shapeforms_ai_empty_prompt',
				__( 'Describe the form you want before generating.', 'shapeforms' ),
				array( 'status' => 400 )
			);
		}

		// Trim rather than reject: a slightly-too-long description should still
		// produce a form, not an error the admin has to hand-edit around.
		if ( function_exists( 'mb_substr' ) ) {
			$prompt = mb_substr( $prompt, 0, self::MAX_PROMPT_CHARS );
		} else {
			$prompt = substr( $prompt, 0, self::MAX_PROMPT_CHARS );
		}

		$limited = $this->check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$spec = $this->request_spec( $prompt );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		$fields = $this->sanitize_fields( isset( $spec['fields'] ) ? $spec['fields'] : array() );

		if ( empty( $fields ) ) {
			return new WP_Error(
				'shapeforms_ai_no_fields',
				__( 'No usable fields came back. Try describing the form in more detail.', 'shapeforms' ),
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response(
			array(
				'title'  => isset( $spec['title'] ) ? sanitize_text_field( (string) $spec['title'] ) : '',
				'fields' => $fields,
			)
		);
	}

	// =========================================================================
	// Rate limiting
	// =========================================================================

	/**
	 * Counts generations per user within a rolling window.
	 *
	 * Guards against a runaway script or a curious admin burning through the
	 * allowance on their own provider account; it is not a security boundary
	 * (the capability check is).
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$key   = 'shapeforms_ai_rate_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new WP_Error(
				'shapeforms_ai_rate_limited',
				__( 'You have generated a lot of forms in a short time. Try again in a little while.', 'shapeforms' ),
				array( 'status' => 429 )
			);
		}

		// Re-setting the transient keeps the original expiry only when the key
		// already exists; on the first call this starts the window.
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	// =========================================================================
	// Transports
	// =========================================================================

	/**
	 * The providers this module can talk to.
	 *
	 * Each entry carries everything that differs between them: where to POST,
	 * which model to ask for, what the key looks like, and where to send the
	 * site owner to get one.
	 *
	 * `dialect` is what the request and reply shapes are built from — NOT the
	 * slug. That indirection is what lets a filter register an OpenAI-compatible
	 * gateway (OpenRouter, LiteLLM, Azure) as its own provider without this
	 * module learning a new API: it declares `'dialect' => 'openai'` and reuses
	 * that shape wholesale.
	 *
	 * Model IDs move faster than plugin releases — `shapeforms_ai_model` overrides
	 * any of them without touching this file.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function providers() {
		$providers = array(
			'anthropic' => array(
				'label'    => 'Anthropic (Claude)',
				'dialect'  => 'anthropic',
				'endpoint' => 'https://api.anthropic.com/v1/messages',
				'model'    => 'claude-opus-4-8',
				'key_url'  => 'https://console.anthropic.com/settings/keys',
				'key_hint' => 'sk-ant-…',
			),
			'openai'    => array(
				'label'    => 'OpenAI',
				'dialect'  => 'openai',
				'endpoint' => 'https://api.openai.com/v1/chat/completions',
				'model'    => 'gpt-5',
				'key_url'  => 'https://platform.openai.com/api-keys',
				'key_hint' => 'sk-…',
			),
			'gemini'    => array(
				'label'    => 'Google Gemini',
				'dialect'  => 'gemini',
				'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				// A rolling alias, deliberately: a pinned version (gemini-3-pro)
				// 404s the moment Google rotates it, and a shipped plugin cannot
				// chase model IDs. Flash is ample for a schema-constrained task
				// this small and has the better free-tier quota.
				'model'    => 'gemini-flash-latest',
				'key_url'  => 'https://aistudio.google.com/apikey',
				'key_hint' => 'AQ.… or AIza…',
			),
			'openrouter' => array(
				'label'           => 'OpenRouter',
				// OpenRouter speaks the OpenAI wire format, so it needs no dialect
				// of its own — the value of adding it is the model catalogue behind
				// one key, which the Model picker is what unlocks.
				'dialect'         => 'openai',
				'endpoint'        => 'https://openrouter.ai/api/v1/chat/completions',
				// A default that is fast, cheap and reliably honours a strict JSON
				// schema. Anything in the catalogue can replace it in Settings.
				'model'           => 'openai/gpt-4o',
				'key_url'         => 'https://openrouter.ai/keys',
				'key_hint'        => 'sk-or-v1-…',
				// Presence of this is what gives a provider a Model picker. The
				// direct providers deliberately have none: each hosts a handful of
				// models we already pick the right one from, so a chooser there is
				// a way to get it wrong. An aggregator is the opposite — choosing
				// is the entire reason to use one.
				'models_endpoint' => 'https://openrouter.ai/api/v1/models',
			),
		);

		/**
		 * Filters the AI providers available on this site.
		 *
		 * An entry needs a `dialect`, and that is usually all it needs: both
		 * `build_provider_request()` and `extract_reply_text()` branch on the
		 * dialect rather than the slug, so anything speaking one of the three
		 * already understood — most notably any OpenAI-compatible gateway or
		 * aggregator — works with no further change. The registered provider
		 * gets its own entry in the settings dropdown and its own API key slot
		 * automatically.
		 *
		 * Only a genuinely new wire format needs those two methods extended.
		 * For smaller adjustments to an existing dialect, filter
		 * `shapeforms_ai_request` instead.
		 *
		 * @param array<string, array<string, string>> $providers Provider registry.
		 */
		return (array) apply_filters( 'shapeforms_ai_providers', $providers );
	}

	/**
	 * The provider this site is configured to use.
	 *
	 * Falls back to the default whenever the stored value names a provider that
	 * no longer exists — e.g. after a filter that added one is removed.
	 *
	 * @return string Provider slug.
	 */
	private function current_provider() {
		return self::selected_provider();
	}

	/**
	 * The provider this site is set to use.
	 *
	 * Static because the Settings screen needs the same answer, and it must not
	 * disagree with the one the request path uses.
	 *
	 * @return string Provider slug.
	 */
	public static function selected_provider() {
		$provider = (string) get_option( self::OPTION_PROVIDER, self::DEFAULT_PROVIDER );

		return isset( self::providers()[ $provider ] ) ? $provider : self::DEFAULT_PROVIDER;
	}

	/**
	 * Every stored provider key, as a provider-slug => key map.
	 *
	 * Folds in the single-key option this replaced, once. That key belonged to
	 * whichever provider was selected when it was saved, so that is where it
	 * lands — filing it anywhere else would hand one provider another's
	 * credential, which fails as an authentication error the admin cannot
	 * explain. Slugs no longer in the registry are dropped rather than kept,
	 * so a stale entry can never be picked up by a later filter that reuses the
	 * same slug for a different service.
	 *
	 * @return array<string, string>
	 */
	public static function api_keys() {
		$stored    = get_option( self::OPTION_API_KEYS, array() );
		$stored    = is_array( $stored ) ? $stored : array();
		$providers = self::providers();

		$keys = array();

		foreach ( $stored as $slug => $key ) {
			$slug = sanitize_key( (string) $slug );
			$key  = trim( (string) $key );

			if ( '' !== $key && isset( $providers[ $slug ] ) ) {
				$keys[ $slug ] = $key;
			}
		}

		return $keys;
	}

	/**
	 * The stored key for one provider, or '' when it has none.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function api_key_for( $provider ) {
		$keys = self::api_keys();

		return isset( $keys[ $provider ] ) ? $keys[ $provider ] : '';
	}

	/**
	 * Per-provider model overrides, as a provider-slug => model map.
	 *
	 * Only entries for providers still in the registry are returned, on the same
	 * reasoning as api_keys(): a stale slug reused later by a different service
	 * would otherwise inherit a model name that means nothing there.
	 *
	 * @return array<string, string>
	 */
	public static function models() {
		$stored    = get_option( self::OPTION_MODELS, array() );
		$stored    = is_array( $stored ) ? $stored : array();
		$providers = self::providers();
		$models    = array();

		foreach ( $stored as $slug => $model ) {
			$slug  = sanitize_key( (string) $slug );
			$model = trim( (string) $model );

			if ( '' !== $model && isset( $providers[ $slug ] ) ) {
				$models[ $slug ] = $model;
			}
		}

		return $models;
	}

	/**
	 * The model to use for one provider: the admin's override, else the
	 * provider's built-in default.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function model_for( $provider ) {
		$models = self::models();

		if ( ! empty( $models[ $provider ] ) ) {
			return $models[ $provider ];
		}

		$providers = self::providers();

		return isset( $providers[ $provider ]['model'] ) ? (string) $providers[ $provider ]['model'] : '';
	}

	/**
	 * Whether this provider offers a Model picker at all.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function has_model_picker( $provider ) {
		$providers = self::providers();

		return ! empty( $providers[ $provider ]['models_endpoint'] );
	}

	/**
	 * The models a provider can be pointed at, grouped by vendor for optgroups.
	 *
	 * Fetched from the provider's own catalogue rather than hardcoded: a list
	 * baked into a shipped plugin is wrong within weeks, and this one runs to
	 * roughly three hundred entries across fifty vendors.
	 *
	 * Only models that can actually complete a generation are listed. Offering
	 * one that will certainly fail is worse than not offering it, and each of
	 * these was verified against the live catalogue and, where possible, a real
	 * request:
	 *
	 *   structured_outputs  The load-bearing one, and NOT the same as
	 *                       `response_format`. That flag only means the model
	 *                       accepts `{"type":"json_object"}` — free-form JSON.
	 *                       Sending a `json_schema` to a model without
	 *                       `structured_outputs` returns HTTP 404 "No endpoints
	 *                       found that can handle the requested parameters",
	 *                       which reads as a broken plugin rather than a wrong
	 *                       model. Both flags are required because the schema
	 *                       travels inside the response_format field.
	 *   context window      Must hold the system prompt, the schema, the
	 *                       admin's description AND the reply. A 4k model with
	 *                       an 8k reply ceiling cannot succeed on any input.
	 *   text output         Image, audio and video models are in the catalogue
	 *                       and cannot return a form spec.
	 *   not expired         The catalogue keeps models past their retirement
	 *                       date; choosing one is choosing a future failure.
	 *   not :batch          Asynchronous, and an admin is waiting on this.
	 *
	 * Cached either way. A failure caches briefly and returns empty, which the
	 * settings screen renders as a plain text box — a catalogue being
	 * unreachable must not cost the admin the ability to set a model.
	 *
	 * @param string $provider Provider slug.
	 * @return array<string, array<string, string>> Vendor => [ model id => label ].
	 */
	public static function model_choices( $provider ) {
		if ( ! self::has_model_picker( $provider ) ) {
			return array();
		}

		$cache_key = 'shapeforms_ai_models_' . $provider;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$providers = self::providers();
		$response  = wp_remote_get(
			$providers[ $provider ]['models_endpoint'],
			array( 'timeout' => 10 )
		);

		$body = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			// Short TTL: a transient outage should not lock the picker away for
			// half a day.
			set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );

			return array();
		}

		// Room for the system prompt, the schema, the admin's description and the
		// reply. Derived from the reply ceiling rather than hardcoded so the two
		// cannot drift apart.
		$min_context = self::MAX_OUTPUT_TOKENS + 4000;
		$now         = time();
		$grouped     = array();

		foreach ( $body['data'] as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) ) {
				continue;
			}

			$id = (string) $model['id'];

			if ( ':batch' === substr( $id, -6 ) ) {
				continue;
			}

			$supported = isset( $model['supported_parameters'] ) && is_array( $model['supported_parameters'] )
				? $model['supported_parameters']
				: array();

			if ( ! in_array( 'structured_outputs', $supported, true ) || ! in_array( 'response_format', $supported, true ) ) {
				continue;
			}

			if ( isset( $model['context_length'] ) && (int) $model['context_length'] < $min_context ) {
				continue;
			}

			// Text and NOTHING else. Merely containing text is not enough:
			// `openai/gpt-audio` lists ["text","audio"] and answers a plain
			// completion with "Provider returned error", because it expects to be
			// driven as a speech model. An entry that produces anything besides
			// text is built for a different job.
			//
			// Absent means text — only the multimodal entries declare this.
			$architecture = isset( $model['architecture'] ) && is_array( $model['architecture'] ) ? $model['architecture'] : array();
			$outputs      = isset( $architecture['output_modalities'] ) && is_array( $architecture['output_modalities'] )
				? $architecture['output_modalities']
				: array( 'text' );

			if ( array( 'text' ) !== array_values( $outputs ) ) {
				continue;
			}

			if ( ! empty( $model['expiration_date'] ) ) {
				$expires = strtotime( (string) $model['expiration_date'] );

				if ( $expires && $expires < $now ) {
					continue;
				}
			}

			$parts  = explode( '/', $id );
			$vendor = count( $parts ) > 1 ? $parts[0] : 'other';
			$label  = ! empty( $model['name'] ) ? (string) $model['name'] : $id;

			$grouped[ $vendor ][ $id ] = $label;
		}

		ksort( $grouped );
		foreach ( $grouped as $vendor => $models ) {
			asort( $grouped[ $vendor ] );
		}

		set_transient( $cache_key, $grouped, 12 * HOUR_IN_SECONDS );

		return $grouped;
	}

	/**
	 * Cleans a posted provider-slug => model map into what may be stored.
	 *
	 * A model name is an opaque identifier at a remote service, so this checks
	 * the shape rather than the value — anything outside the characters model
	 * IDs actually use is a tampered POST, not a model this plugin has not heard
	 * of yet.
	 *
	 * @param mixed $posted Raw posted map, already unslashed.
	 * @return array<string, string>
	 */
	public static function sanitize_posted_models( $posted ) {
		if ( ! is_array( $posted ) ) {
			return array();
		}

		$providers = self::providers();
		$models    = array();
		$submitted = array();

		foreach ( $posted as $slug => $model ) {
			$slug = sanitize_key( (string) $slug );

			if ( ! isset( $providers[ $slug ] ) ) {
				continue;
			}

			$submitted[ $slug ] = true;

			$model = trim( sanitize_text_field( (string) $model ) );

			// Slashes and colons are load-bearing in model IDs
			// (`anthropic/claude-sonnet-4.5`, `openai/gpt-4o:extended`), and a
			// leading tilde marks a rolling alias on OpenRouter
			// (`~google/gemini-flash-latest`) — the ids most worth choosing,
			// since they do not need chasing when a vendor rotates a version.
			// All are allowed alongside the usual identifier characters.
			if ( '' === $model || ! preg_match( '#^[A-Za-z0-9._:/~-]{1,128}$#', $model ) ) {
				continue;
			}

			// Storing a value identical to the built-in default would pin the
			// provider to today's default and silently miss a future change.
			if ( isset( $providers[ $slug ]['model'] ) && $model === $providers[ $slug ]['model'] ) {
				continue;
			}

			$models[ $slug ] = $model;
		}

		// Absent means unknown, not cleared — see sanitize_posted_keys().
		foreach ( self::models() as $slug => $model ) {
			if ( ! isset( $submitted[ $slug ] ) ) {
				$models[ $slug ] = $model;
			}
		}

		return $models;
	}

	/**
	 * Cleans a posted provider-slug => key map into what may be stored.
	 *
	 * Lives here rather than in the Settings save handler so the module owns the
	 * shape of its own option, and so this is reachable without driving a form
	 * submission — the save handler ends in a redirect and cannot be exercised
	 * directly.
	 *
	 * Every provider's field posts on every save (the inactive ones are hidden,
	 * not removed), so a field that posts EMPTY was deliberately cleared and
	 * that provider's key is dropped.
	 *
	 * A field that does not post at all is a different thing entirely, and is
	 * left alone. That happens when the settings page being saved was rendered
	 * before a provider existed — register one, and every admin still holding an
	 * older page would otherwise wipe its key the next time they pressed Save,
	 * with nothing on screen to suggest they had. Absent means unknown, not
	 * cleared.
	 *
	 * @param mixed $posted Raw posted map, already unslashed.
	 * @return array<string, string>
	 */
	public static function sanitize_posted_keys( $posted ) {
		if ( ! is_array( $posted ) ) {
			return array();
		}

		$providers = self::providers();
		$keys      = array();
		$submitted = array();

		foreach ( $posted as $slug => $key ) {
			$slug = sanitize_key( (string) $slug );

			// A slug outside the registry has no request shape behind it, so a
			// key stored against one could never be used — and accepting it
			// would let a tampered POST write arbitrary option data.
			if ( ! isset( $providers[ $slug ] ) ) {
				continue;
			}

			$submitted[ $slug ] = true;

			$key = trim( sanitize_text_field( (string) $key ) );

			if ( '' !== $key ) {
				$keys[ $slug ] = $key;
			}
		}

		foreach ( self::api_keys() as $slug => $key ) {
			if ( ! isset( $submitted[ $slug ] ) ) {
				$keys[ $slug ] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Dispatches to whichever transport this site is configured for.
	 *
	 * @param string $prompt Admin's description.
	 * @return array<string, mixed>|WP_Error Decoded spec.
	 */
	private function request_spec( $prompt ) {
		// Only the selected provider's key counts. A key stored against another
		// provider is not a fallback — it would authenticate nowhere useful and
		// would silently bill an account the admin did not choose.
		$api_key = self::api_key_for( $this->current_provider() );

		if ( '' !== $api_key ) {
			return $this->request_via_provider( $prompt, $api_key );
		}

		// A key is the admin's to supply and there is nothing to fall back to,
		// so say so plainly and name the screen. Anything vaguer sends them
		// looking for a fault that is not there.
		return new WP_Error(
			'shapeforms_ai_no_key',
			__( 'Add your AI provider API key under ShapeForms → Settings → AI to generate forms.', 'shapeforms' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The request/response dialect a provider speaks.
	 *
	 * Defaults to the module's default dialect when a filter-registered provider
	 * omits it — a wrong-but-known shape surfaces as a clear API error, whereas
	 * an unknown dialect would need a separate failure path for no benefit.
	 *
	 * @param string $provider Provider slug.
	 * @return string Dialect slug.
	 */
	private function dialect_for( $provider ) {
		$providers = self::providers();

		return ! empty( $providers[ $provider ]['dialect'] )
			? (string) $providers[ $provider ]['dialect']
			: self::DEFAULT_PROVIDER;
	}

	/**
	 * Builds the endpoint, headers and body for one provider.
	 *
	 * The three providers agree on the idea — a system instruction, a user
	 * message, and a JSON schema the reply must satisfy — and disagree on every
	 * detail of how to express it. This is the only place that difference lives.
	 *
	 * @param string $provider Provider slug.
	 * @param string $prompt   Admin's description.
	 * @param string $api_key  Site-owned provider key.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private function build_provider_request( $provider, $prompt, $api_key ) {
		$config  = self::providers()[ $provider ];
		$model   = (string) apply_filters( 'shapeforms_ai_model', self::model_for( $provider ), $provider );
		$url     = str_replace( '{model}', rawurlencode( $model ), $config['endpoint'] );
		$system  = $this->system_prompt();
		$dialect = $this->dialect_for( $provider );

		/**
		 * Filters the reply-length ceiling, in tokens.
		 *
		 * Worth lowering on a credit-metered account: gateways bill the ceiling
		 * up front, so a request can be refused for a budget it was never going
		 * to spend. Lower it too far and a large form is truncated mid-JSON, so
		 * treat roughly 100 tokens per field as the floor.
		 *
		 * @param int    $max      Ceiling in tokens.
		 * @param string $provider Provider slug.
		 */
		$max_tokens = (int) apply_filters( 'shapeforms_ai_max_output_tokens', self::MAX_OUTPUT_TOKENS, $provider );
		$max_tokens = max( 1000, $max_tokens );

		$headers = array( 'Content-Type' => 'application/json' );

		switch ( $dialect ) {
			case 'openai':
				$headers['Authorization'] = 'Bearer ' . $api_key;

				$body = array(
					'model'           => $model,
					'max_tokens'      => $max_tokens,
					'messages'        => array(
						array(
							'role'    => 'system',
							'content' => $system,
						),
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					// `strict` is what makes the type enum binding rather than
					// advisory. It requires every property to appear in
					// `required` and `additionalProperties: false` — which the
					// shared schema already satisfies.
					'response_format' => array(
						'type'        => 'json_schema',
						'json_schema' => array(
							'name'   => 'shapeforms_form_spec',
							'strict' => true,
							'schema' => $this->response_schema( $provider ),
						),
					),
				);
				break;

			case 'gemini':
				// Gemini takes the key in a header rather than the query string,
				// keeping it out of access logs.
				$headers['x-goog-api-key'] = $api_key;

				$body = array(
					'systemInstruction' => array(
						'parts' => array( array( 'text' => $system ) ),
					),
					'contents'          => array(
						array(
							'role'  => 'user',
							'parts' => array( array( 'text' => $prompt ) ),
						),
					),
					'generationConfig'  => array(
						'responseMimeType' => 'application/json',
						'responseSchema'   => $this->response_schema( $provider ),
					),
				);
				break;

			case 'anthropic':
			default:
				$headers['x-api-key']         = $api_key;
				$headers['anthropic-version'] = self::ANTHROPIC_VERSION;

				$body = array(
					'model'         => $model,
					'max_tokens'    => $max_tokens,
					'system'        => $system,
					'messages'      => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'output_config' => array(
						'format' => array(
							'type'   => 'json_schema',
							'schema' => $this->response_schema( $provider ),
						),
						// Form generation is a well-scoped, schema-constrained
						// task running in front of a waiting admin, so favour
						// latency.
						'effort' => 'low',
					),
				);
				break;
		}

		// OpenRouter speaks the OpenAI dialect but fronts many upstreams, only
		// some of which honour a strict json_schema. Without require_parameters
		// it is free to route to one that ignores it, and the reply comes back as
		// prose that fails JSON decoding — an intermittent failure that depends
		// on routing rather than on anything the admin did.
		//
		// The two attribution headers are how OpenRouter labels traffic in the
		// account dashboard; they are optional to the API and useful to the admin.
		if ( 'openrouter' === $provider ) {
			$body['provider'] = array( 'require_parameters' => true );

			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = 'ShapeForms';
		}

		$request = array(
			'url'  => $url,
			'args' => array(
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			),
		);

		/**
		 * Filters the outgoing request just before it is sent.
		 *
		 * The three built-in dialects cover the shapes the major providers
		 * speak, but an aggregator reached through one of them may still want
		 * something extra — routing preferences, attribution headers, a
		 * per-model parameter. Without this the only way to add one would be to
		 * teach the switch above about a provider it otherwise handles fine.
		 *
		 * `body` is a JSON string; decode, alter and re-encode it.
		 *
		 * @param array{url: string, args: array<string, mixed>} $request  Request.
		 * @param string                                         $provider Provider slug.
		 * @param string                                         $dialect  Dialect slug.
		 */
		return (array) apply_filters( 'shapeforms_ai_request', $request, $provider, $dialect );
	}

	/**
	 * Pulls the JSON payload out of a provider's reply envelope.
	 *
	 * Every provider returns the spec as a JSON *string* nested somewhere
	 * different. This unwraps that envelope; decoding is the caller's job.
	 *
	 * @param string               $provider Provider slug.
	 * @param array<string, mixed> $decoded  Decoded response body.
	 * @return string JSON text, or an empty string when the shape is unfamiliar.
	 */
	private function extract_reply_text( $provider, array $decoded ) {
		switch ( $this->dialect_for( $provider ) ) {
			case 'openai':
				return isset( $decoded['choices'][0]['message']['content'] )
					? (string) $decoded['choices'][0]['message']['content']
					: '';

			case 'gemini':
				return isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
					? (string) $decoded['candidates'][0]['content']['parts'][0]['text']
					: '';

			case 'anthropic':
			default:
				if ( ! empty( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
					foreach ( $decoded['content'] as $block ) {
						if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
							return (string) $block['text'];
						}
					}
				}

				return '';
		}
	}

	/**
	 * Bring-your-own-key transport: call the configured provider directly.
	 *
	 * @param string $prompt  Admin's description.
	 * @param string $api_key Site-owned provider key.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request_via_provider( $prompt, $api_key ) {
		$provider = $this->current_provider();
		$request  = $this->build_provider_request( $provider, $prompt, $api_key );

		$response = $this->post_with_retry( $request['url'], $request['args'] );

		$decoded = $this->decode_remote( $response, $provider );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$text = $this->extract_reply_text( $provider, $decoded );

		if ( '' === $text ) {
			return new WP_Error(
				'shapeforms_ai_empty_reply',
				$this->reply_failure_message( $provider ),
				array( 'status' => 502 )
			);
		}

		$spec = $this->decode_spec_text( $text );

		if ( null === $spec ) {
			return new WP_Error(
				'shapeforms_ai_bad_reply',
				$this->reply_failure_message( $provider ),
				array( 'status' => 502 )
			);
		}

		return $spec;
	}

	/**
	 * Turns a model's reply into a spec, forgiving the ways they wrap one.
	 *
	 * A schema is meant to make this unnecessary, and with the major providers
	 * it does. Through an aggregator it does not: enforcement is per-upstream,
	 * and where it is emulated by prompting rather than enforced by the decoder
	 * the model is free to answer in its own shape. Measured against the live
	 * OpenRouter catalogue, every one of these came back from a model whose
	 * listing claimed strict schema support:
	 *
	 *   ```json … ```        A markdown fence. json_decode() fails on the
	 *                        backticks alone, so a perfectly good spec was being
	 *                        thrown away over three characters.
	 *   prose then JSON      "Here's the form you asked for: {…}".
	 *   {"form": {…}}        The spec wrapped in a single-key envelope.
	 *   [ {…}, {…} ]         The field list on its own, no envelope.
	 *   {"formTitle": …}     The right shape under a different name.
	 *
	 * Being liberal here costs nothing in safety: this only decides whether a
	 * reply is worth looking at. Every value in it is still validated against
	 * the field registry by sanitize_fields() before it can reach a form.
	 *
	 * @param string $text Raw reply text.
	 * @return array<string, mixed>|null Spec, or null if there is no spec in there.
	 */
	private function decode_spec_text( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return null;
		}

		// Strip a fenced code block, with or without a language tag.
		if ( 0 === strpos( $text, '```' ) ) {
			$text = (string) preg_replace( '/^```[a-zA-Z0-9]*\s*/', '', $text );
			$text = trim( (string) preg_replace( '/```\s*$/', '', $text ) );
		}

		$spec = json_decode( $text, true );

		// Prose around the JSON: take everything between the first opening
		// bracket and the last matching closing one.
		if ( ! is_array( $spec ) ) {
			$start = strcspn( $text, '{[' );

			if ( $start < strlen( $text ) ) {
				$close = '{' === $text[ $start ] ? '}' : ']';
				$end   = strrpos( $text, $close );

				if ( false !== $end && $end > $start ) {
					$spec = json_decode( substr( $text, $start, $end - $start + 1 ), true );
				}
			}
		}

		if ( ! is_array( $spec ) ) {
			return null;
		}

		// A bare list is the fields, with the title left for us to fill in.
		if ( isset( $spec[0] ) ) {
			return array(
				'title'  => '',
				'fields' => $spec,
			);
		}

		// A single-key envelope around the real thing.
		if ( ! isset( $spec['fields'] ) && 1 === count( $spec ) ) {
			$inner = reset( $spec );

			if ( is_array( $inner ) && isset( $inner['fields'] ) ) {
				$spec = $inner;
			}
		}

		if ( ! isset( $spec['fields'] ) || ! is_array( $spec['fields'] ) ) {
			return null;
		}

		if ( ! isset( $spec['title'] ) || ! is_string( $spec['title'] ) ) {
			$spec['title'] = '';

			foreach ( array( 'formTitle', 'form_title', 'name', 'heading' ) as $alias ) {
				if ( ! empty( $spec[ $alias ] ) && is_string( $spec[ $alias ] ) ) {
					$spec['title'] = $spec[ $alias ];
					break;
				}
			}
		}

		return $spec;
	}

	/**
	 * What to say when a model answers but the answer is unusable.
	 *
	 * Worth distinguishing, because the cause is different depending on who
	 * chose the model. Roughly one model in six across a spread of the
	 * OpenRouter catalogue cannot do this job even though its metadata says it
	 * can — some ignore the schema and reply in prose, some spend the entire
	 * reply budget thinking, some are simply too slow. None of that is knowable
	 * before the request, so the picker cannot filter them out.
	 *
	 * When the admin has chosen a model themselves, saying "try again" sends
	 * them round the same loop forever; the model is the thing to change. When
	 * they have not, it really is worth retrying.
	 *
	 * @param string $provider Provider slug the reply came from.
	 * @return string
	 */
	private function reply_failure_message( $provider ) {
		$model = self::models();

		if ( ! empty( $model[ $provider ] ) ) {
			return sprintf(
				/* translators: %s: the model id the admin selected. */
				__( '%s did not return a usable form. Not every model can follow a strict JSON schema, whatever its listing claims — pick a different one under Model in Settings.', 'shapeforms' ),
				$model[ $provider ]
			);
		}

		return __( 'The AI service returned something unexpected. Please try again.', 'shapeforms' );
	}

	/**
	 * POSTs, retrying the failures that are worth retrying.
	 *
	 * Providers shed load with 503 and throttle with 429, and both clear in
	 * seconds — "This model is currently experiencing high demand" is the admin
	 * being handed a transient condition to solve themselves. Everything else,
	 * including every 4xx, is returned on the first attempt: a bad key or a
	 * malformed request will fail identically however many times it is sent.
	 *
	 * Two extra attempts at most, so the worst case adds six seconds to a
	 * request that already allows sixty.
	 *
	 * @param string               $url  Endpoint.
	 * @param array<string, mixed> $args wp_remote_post arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post_with_retry( $url, $args ) {
		$attempts = 3;
		$response = null;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 !== $code && 503 !== $code ) {
				return $response;
			}

			if ( $attempt < $attempts ) {
				sleep( $attempt * 2 );
			}
		}

		return $response;
	}

	/**
	 * Turns a wp_remote_post() result into a decoded body or a WP_Error.
	 *
	 * Every remote failure mode lands here so the caller never has to reason
	 * about transport errors versus HTTP status versus malformed JSON.
	 *
	 * @param array<string, mixed>|WP_Error $response Raw wp_remote_post result.
	 * @param string                        $provider Provider slug, when known.
	 * @return array<string, mixed>|WP_Error
	 */
	private function decode_remote( $response, $provider = '' ) {
		if ( is_wp_error( $response ) ) {
			/* A timeout and an unreachable host arrive here identically, and
			   telling someone to check their connection when the model simply
			   took too long sends them to fix the wrong thing. Slow models are
			   common — a heavily loaded one can sit well past a minute — so the
			   two are worth separating, and the model is worth naming when the
			   admin picked it. */
			$detail = $response->get_error_message();

			if ( false !== stripos( $detail, 'timed out' ) || false !== stripos( $detail, 'timeout' ) ) {
				$models = self::models();

				return new WP_Error(
					'shapeforms_ai_timeout',
					'' !== $provider && ! empty( $models[ $provider ] )
						? sprintf(
							/* translators: %s: the model id the admin selected. */
							__( '%s took too long to answer. Some models are much slower than others — pick a faster one under Model in Settings, or try again when it is less busy.', 'shapeforms' ),
							$models[ $provider ]
						)
						: __( 'The AI service took too long to answer. Please try again.', 'shapeforms' ),
					array( 'status' => 504 )
				);
			}

			return new WP_Error(
				'shapeforms_ai_unreachable',
				__( 'Could not reach the AI service. Check your connection and try again.', 'shapeforms' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( 200 !== $code ) {
			// Surface the service's own message when it sent one — "Invalid API
			// key" is far more actionable than a generic failure.
			$detail = '';
			if ( is_array( $json ) ) {
				if ( isset( $json['error']['message'] ) ) {
					$detail = (string) $json['error']['message'];
				} elseif ( isset( $json['message'] ) ) {
					$detail = (string) $json['message'];
				}
			}

			return new WP_Error(
				'shapeforms_ai_http_error',
				'' !== $detail
					? $detail
					: sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The AI service returned an error (HTTP %d).', 'shapeforms' ),
						$code
					),
				array( 'status' => 502 )
			);
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'shapeforms_ai_bad_reply',
				__( 'The AI service returned something unexpected. Please try again.', 'shapeforms' ),
				array( 'status' => 502 )
			);
		}

		return $json;
	}

	// =========================================================================
	// Schema + prompt
	// =========================================================================

	/**
	 * Lite's own base field types, as defined in `ShapeForms_Lite_Ajax_Save::prepare_rows()`.
	 *
	 * Lite builds this list as a local variable and only then runs it through the
	 * `shapeforms_allowed_field_types` filter, so the filter alone yields just the
	 * types add-ons have appended — not the core set. Seeding the filter with the
	 * same base list is what makes the result the site's real registry.
	 *
	 * Keep in sync with Lite if it ever ships a new core field type; a missing
	 * entry here only means the AI will not offer that type, never that a form
	 * breaks.
	 *
	 * @var array<int, string>
	 */
	private static $lite_base_types = array(
		'text',
		'name',
		'email',
		'number',
		'textarea',
		'select',
		'multiselect',
		'checkbox',
		'radio',
		'date',
		'time',
		'tel',
		'url',
		'captcha',
		'section_break',
		'terms_conditions',
		'file',
		'submit',
		'input_mask',
		'html_editor',
		'paragraph',
		'numeric',
		'address',
		'country',
		'star_rating',
		'slider_range',
	);

	/**
	 * Field types the model must never choose, and why.
	 *
	 * Two different reasons, deliberately kept in one list because the effect is
	 * the same — the type is absent from the schema enum, so it cannot be
	 * emitted at all rather than emitted and then discarded.
	 *
	 * BUILDER-OWNED — the builder supplies these itself, or they are a decision
	 * the site makes rather than the form design does:
	 *   submit      The builder appends one to every form.
	 *   captcha     An anti-spam policy choice, not a field the description asks for.
	 *   html_editor Arbitrary markup; `paragraph` covers explanatory text safely.
	 *
	 * UNCONFIGURABLE — the field carries settings this spec has no room to
	 * express, so the model can pick the type but never make it work. Each would
	 * land on the canvas looking finished and be an empty shell:
	 *   product      Needs `product_options` (label + price pairs). Bare, it seeds
	 *                a fake "Option 1 / 10.00" that reads as real configuration.
	 *   quantity     Needs `linked_product`; orphaned without one.
	 *   calculation  Needs `calc_formula`; computes and displays nothing without it.
	 *   image_choice Needs uploaded images.
	 *   repeater     Needs its sub-field list.
	 *   matrix       Needs its row and column axes.
	 *   lookup       Needs a data source.
	 *   hidden_field Needs a value or population source, and has no visible label
	 *                for the model to reason about.
	 *   input_mask   Needs `mask_pattern`.
	 *
	 * `custom_amount` and `order_summary` are deliberately NOT here: the first
	 * works bare (limits are opt-in and default off, which is right for a
	 * donation box) and the second auto-discovers the payment fields around it.
	 *
	 * @var array<int, string>
	 */
	private static $never_offer = array(
		// Builder-owned.
		'submit',
		'captcha',
		'html_editor',
		// Unconfigurable from this spec.
		'product',
		'quantity',
		'calculation',
		'image_choice',
		'repeater',
		'matrix',
		'lookup',
		'hidden_field',
		'input_mask',
	);

	/**
	 * The field types this install can actually build.
	 *
	 * The core set plus whatever add-ons register, so a site with extra
	 * field types can use them and a site without them never sees them offered.
	 * `self::$never_offer` is then removed — see that property for the reasoning
	 * behind each entry.
	 *
	 * `page_break` is offered (it was not previously): it is how Lite represents
	 * a step boundary, and without it a multi-step form cannot be expressed at
	 * all. `section_break` and `paragraph` are offered for the same reason —
	 * they are the only way the model can group or explain a long form.
	 *
	 * @return array<int, string>
	 */
	private function allowed_field_types() {
		/** This filter is documented in shapeforms/admin/ajax-save.php */
		$types = apply_filters( 'shapeforms_allowed_field_types', self::$lite_base_types );
		$types = is_array( $types ) ? $types : self::$lite_base_types;

		/**
		 * Filters the field types the AI builder must never offer.
		 *
		 * Add a type here when it needs configuration the generated spec cannot
		 * carry — offering it produces a field that looks built and is empty.
		 *
		 * @param string[] $never_offer Field type slugs.
		 */
		$never = (array) apply_filters( 'shapeforms_ai_never_offer_types', self::$never_offer );
		$types = array_values( array_unique( array_diff( $types, $never ) ) );

		// An empty registry would produce an invalid schema (`enum: []`).
		if ( empty( $types ) ) {
			$types = array( 'text', 'email', 'textarea', 'select', 'radio', 'checkbox', 'number', 'tel', 'url', 'date' );
		}

		return $types;
	}

	/**
	 * JSON schema the model's reply must satisfy.
	 *
	 * The `enum` on `type` is the load-bearing part: it makes an unbuildable
	 * field type impossible rather than something to detect and discard.
	 *
	 * One schema serves every provider, with a single dialect adjustment —
	 * Gemini's `responseSchema` is an OpenAPI subset that **rejects**
	 * `additionalProperties` outright, so it is stripped for that provider.
	 * Anthropic and OpenAI both want it, and OpenAI's `strict` mode requires it.
	 *
	 * @param string $provider Provider slug the schema is being built for.
	 * @return array<string, mixed>
	 */
	private function response_schema( $provider = self::DEFAULT_PROVIDER ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'title'  => array(
					'type'        => 'string',
					'description' => 'A short, human-readable name for the form.',
				),
				'fields' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'             => array(
								'type'        => 'string',
								'enum'        => $this->allowed_field_types(),
								'description' => 'The field type to use.',
							),
							'ref'              => array(
								'type'        => 'string',
								'description' => 'Short lowercase handle unique within this form, e.g. "donation_frequency". Used only so other fields can reference this one in show_if. Never shown to the visitor.',
							),
							'label'            => array(
								'type'        => 'string',
								'description' => 'Visible label, in sentence case.',
							),
							'required'         => array(
								'type'        => 'boolean',
								'description' => 'Whether the visitor must fill this in.',
							),
							'placeholder'      => array(
								'type'        => 'string',
								'description' => 'Example text shown inside the empty field. Empty string when not useful.',
							),
							'description'      => array(
								'type'        => 'string',
								'description' => 'Short help text shown under the field. Empty string when the label is self-explanatory.',
							),
							'default_value'    => array(
								'type'        => 'string',
								'description' => 'Value the field starts with. Empty string for almost every field.',
							),
							'options'          => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Choices for select, radio, checkbox and multiselect. Empty array for every other type.',
							),
							'width'            => array(
								'type'        => 'string',
								'enum'        => array( '100%', '50%', '33.33%', '25%' ),
								'description' => 'Column width. Consecutive fields whose widths add up to 100% are placed side by side on one row.',
							),
							'min'              => array(
								'type'        => 'string',
								'description' => 'Minimum for number, numeric and slider_range. Empty string otherwise.',
							),
							'max'              => array(
								'type'        => 'string',
								'description' => 'Maximum for number, numeric and slider_range. Empty string otherwise.',
							),
							'step'             => array(
								'type'        => 'string',
								'description' => 'Step increment for number, numeric and slider_range. Empty string otherwise.',
							),
							// Flattened rather than a nested `show_if` object on
							// purpose: a conditionally-present object needs a
							// nullable type, which OpenAI strict mode and Gemini's
							// OpenAPI subset each spell differently. Three always-
							// present strings behave identically everywhere, and an
							// empty ref simply means "always visible".
							'show_if_ref'      => array(
								'type'        => 'string',
								'description' => 'The ref of an EARLIER field this one depends on. Empty string means always visible.',
							),
							// No empty member in this enum: Gemini rejects an empty
							// string as an enum value outright ("enum[0]: cannot be
							// empty"), and OpenAI strict mode requires the property
							// to be present regardless. So "no condition" is carried
							// by an empty show_if_ref alone, and this falls back to
							// a harmless default that is then ignored.
							'show_if_operator' => array(
								'type'        => 'string',
								'enum'        => array( 'is', 'is_not', 'contains', 'not_empty', 'empty', 'greater_than', 'less_than' ),
								'description' => 'How to compare the other field. Ignored unless show_if_ref is set — use "is" when there is no condition.',
							),
							'show_if_value'    => array(
								'type'        => 'string',
								'description' => 'Value to compare against. For a choice field this must match one of its options exactly. Empty string for the not_empty and empty operators.',
							),
						),
						'required'             => array( 'type', 'ref', 'label', 'required', 'placeholder', 'description', 'default_value', 'options', 'width', 'min', 'max', 'step', 'show_if_ref', 'show_if_operator', 'show_if_value' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'title', 'fields' ),
			'additionalProperties' => false,
		);

		if ( 'gemini' === $this->dialect_for( $provider ) ) {
			$schema = self::strip_schema_key( $schema, 'additionalProperties' );
		}

		return $schema;
	}

	/**
	 * Removes one key from every level of a schema.
	 *
	 * @param array<string, mixed> $schema Schema to walk.
	 * @param string               $key    Key to drop.
	 * @return array<string, mixed>
	 */
	private static function strip_schema_key( array $schema, $key ) {
		unset( $schema[ $key ] );

		foreach ( $schema as $name => $value ) {
			if ( is_array( $value ) ) {
				$schema[ $name ] = self::strip_schema_key( $value, $key );
			}
		}

		return $schema;
	}

	/**
	 * One-line glosses for the field types, keyed by slug.
	 *
	 * A bare list of 39 slugs makes the model infer meaning from the slug alone,
	 * which is why `nps`, `matrix` and `date_range` were effectively invisible to
	 * it while `text` and `number` were over-used. Naming what each type does is
	 * what turns an available type into a usable one.
	 *
	 * A type with no entry here still gets offered — it is simply listed with its
	 * slug alone, exactly as every type was before. That matters because add-ons
	 * register types this map has never heard of.
	 *
	 * @return array<string, string>
	 */
	private static function type_glosses() {
		return array(
			'text'              => 'a single line of free text',
			'name'              => 'a person’s name, with optional middle and last name parts',
			'email'             => 'an email address, validated as one',
			'number'            => 'a number, with optional min/max/step',
			'textarea'          => 'several lines of free text',
			'select'            => 'a dropdown the visitor picks one option from',
			'multiselect'       => 'a dropdown the visitor can pick several options from',
			'checkbox'          => 'tick boxes; the visitor can tick any number of them',
			'radio'             => 'visible choices the visitor picks exactly one of',
			'date'              => 'a single date, via a date picker',
			'time'              => 'a time of day',
			'tel'               => 'a telephone number',
			'url'               => 'a web address',
			'file'              => 'a file upload',
			'address'           => 'a full postal address — street, city, state, postcode and country in one field',
			'country'           => 'a country dropdown, pre-filled with every country',
			'password_field'    => 'a masked text entry, for a password the visitor chooses',
			'rich_text'         => 'multi-line text the visitor can format (bold, lists, links)',
			'numeric'           => 'a number with controlled decimals and thousands separators, for money and quantities',
			'date_range'        => 'a start date and an end date as one field, for bookings and stays',
			'star_rating'       => 'a 1-to-5 star rating',
			'nps'               => 'a 0-to-10 Net Promoter Score scale, for "how likely are you to recommend us"',
			'slider_range'      => 'a slider the visitor drags between a minimum and a maximum',
			'color'             => 'a colour picker',
			'signature'         => 'a box the visitor signs with a mouse or finger',
			'geolocation'       => 'captures the visitor’s geographic location',
			'terms_conditions'  => 'a single consent tick box, for agreeing to terms or a privacy policy',
			'custom_amount'     => 'the visitor types their own amount — use this for donations and pay-what-you-want',
			'order_summary'     => 'a running total of the payment fields above it; place it last',
			'section_break'     => 'a heading that divides a long form into labelled sections; collects no answer',
			'paragraph'         => 'a block of explanatory text for the visitor to read; collects no answer',
			'page_break'        => 'ends the current step and starts a new one; collects no answer',
		);
	}

	/**
	 * The available types, one per line, each with its gloss where we have one.
	 *
	 * @return string
	 */
	private function type_catalogue() {
		$glosses = self::type_glosses();
		$lines   = array();

		foreach ( $this->allowed_field_types() as $type ) {
			$lines[] = isset( $glosses[ $type ] )
				? '- `' . $type . '` — ' . $glosses[ $type ]
				: '- `' . $type . '`';
		}

		return implode( "\n", $lines );
	}

	/**
	 * System prompt describing the job and this install's field vocabulary.
	 *
	 * Kept stable (no timestamps, no per-request values) so provider-side prompt
	 * caching can take effect across generations.
	 *
	 * @return string
	 */
	private function system_prompt() {
		$catalogue = $this->type_catalogue();

		/*
		 * Built by concatenation rather than a heredoc: WordPress.org's Plugin
		 * Check rejects heredoc syntax outright, and this prompt is the one
		 * place in the plugin long enough to want one. The array-and-implode
		 * form keeps the line breaks meaningful — the model reads the section
		 * headings — without the syntax the directory disallows.
		 */
		$prompt = implode(
			"\n",
			array(
				'You design web forms for the ShapeForms WordPress form builder. The user describes',
				'the form they need; you return its structure.',
				'',
				'FIELD TYPES AVAILABLE ON THIS SITE',
				$catalogue,
				'',
				'Use only the types listed above, and prefer the most specific one that fits: an',
				'email address is `email`, not `text`; a phone number is `tel`; a postal address',
				'is `address` rather than four separate text fields.',
				'',
				'HOW MUCH FORM TO BUILD',
				'Match the ambition of the description. "A contact form" wants three or four',
				'fields and nothing else. "A charity fundraiser", "a conference registration" or',
				'"a patient intake form" are real-world processes — build what that process',
				'actually needs, including the parts the user did not think to list, and group it',
				'properly. Never pad a simple request; never under-build an elaborate one.',
				'',
				'LAYOUT',
				'- `width` places fields side by side. Consecutive fields are packed onto one row',
				'  while their widths still add up to 100%, so two 50% fields share a line and',
				'  four 25% fields make a row of four.',
				'- Pair fields that belong together: first/last name, city/postcode, start/end',
				'  date, card expiry/CVC. Give anything long — a textarea, an address, a file',
				'  upload — the full 100%.',
				'- Use `section_break` to head each group of a long form ("Your details",',
				'  "Payment"). Use `paragraph` for a sentence of explanation the visitor needs.',
				'',
				'STEPS',
				'- Use `page_break` to split a long form into steps. It ends one step and begins',
				'  the next, so N page breaks give N+1 steps. Put nothing else on that field',
				'  besides a label naming the step the visitor is moving on to.',
				'- Only split a form that is genuinely long — roughly ten fields or more, or one',
				'  with clearly distinct stages. A short form in three steps is worse than a',
				'  short form in one.',
				'',
				'CONDITIONAL FIELDS',
				'- Every field carries a `ref`: a short unique handle like `donation_frequency`.',
				'  It is never shown to anyone; it exists so other fields can point at it.',
				'- To show a field only in certain cases, set `show_if_ref` to an EARLIER field\'s',
				'  ref, plus `show_if_operator` and `show_if_value`. For a field that is always',
				'  visible — which is most of them — leave `show_if_ref` and `show_if_value`',
				'  empty and set `show_if_operator` to `is`; an empty ref is what marks the',
				'  field unconditional, and the operator is then ignored.',
				'- `show_if_value` must match one of the referenced field\'s `options` exactly,',
				'  character for character. Use `not_empty` and `empty` with an empty value.',
				'- This is what makes a form feel intelligent: ask "Are you attending?" and only',
				'  then ask about dietary needs. Use it wherever a question is genuinely',
				'  conditional, and nowhere else.',
				'',
				'FIELD DETAIL',
				'- Labels are short, human-readable and in sentence case ("Your email address",',
				'  not "EMAIL_ADDRESS").',
				'- Mark a field required only when the form genuinely cannot be processed without',
				'  it. A contact form needs an email; it does not need a company name.',
				'- `options` belongs to select, radio, checkbox and multiselect only. Every other',
				'  type gets an empty array.',
				'- `placeholder` only where an example genuinely helps, like a phone format.',
				'  Otherwise an empty string. Never restate the label.',
				'- `description` is one short line of help under the field — a constraint, a',
				'  reason, or a format note. Empty when the label already says it.',
				'- `min`, `max` and `step` apply to number, numeric and slider_range. Empty',
				'  strings everywhere else.',
				'- `default_value` is almost always an empty string. Set it only where one answer',
				'  is genuinely the common case.',
				'- Order fields the way a person would fill them in: who they are, then what they',
				'  want, then anything optional.',
				'- Do not add a submit button — the builder adds one.',
				'',
				'WHAT TO RETURN',
				'One JSON object and nothing else. No commentary before or after it, and no',
				'markdown code fence around it. It has exactly two keys:',
				'',
				'  "title"   A short name for the form itself, like "Job application" or',
				'            "Event registration". Not a sentence.',
				'  "fields"  An array of field objects, in the order they should appear.',
				'',
				'Every field object carries every key listed below, every time — including the',
				'ones that do not apply to it, which take an empty string or an empty array',
				'rather than being left out:',
				'',
				'  type, ref, label, required, placeholder, description, default_value,',
				'  options, width, min, max, step, show_if_ref, show_if_operator, show_if_value',
				'',
				'Do not invent keys, do not rename them, and do not wrap the object in another',
				'one. The reply is read by a program, not a person.',
			)
		);

		/**
		 * Filters the system prompt used for AI form generation.
		 *
		 * @param string $prompt The system prompt.
		 */
		return (string) apply_filters( 'shapeforms_ai_system_prompt', $prompt );
	}

	// =========================================================================
	// Validation
	// =========================================================================

	/**
	 * Re-validates the spec on our side before it reaches the builder.
	 *
	 * The schema already constrains the model, but the hosted transport is a
	 * separate service and its reply is still remote input — so the field type
	 * is checked against the registry here too, rather than trusted.
	 *
	 * @param mixed $fields Raw fields from the spec.
	 * @return array<int, array<string, mixed>> Clean fields.
	 */
	private function sanitize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$allowed = $this->allowed_field_types();
		$choice  = array( 'select', 'multiselect', 'radio', 'checkbox' );

		// These collect no answer, so an empty label is survivable rather than a
		// reason to drop the field — losing a page_break silently merges two
		// steps into one, which is a worse outcome than an unlabelled divider.
		$structural = array( 'page_break', 'section_break', 'paragraph' );

		$widths    = array( '100%', '50%', '33.33%', '25%' );
		$operators = array( 'is', 'is_not', 'contains', 'not_empty', 'empty', 'greater_than', 'less_than' );
		$valueless = array( 'not_empty', 'empty' );

		$clean = array();
		$refs  = array();
		$seen  = array();

		$capped = array_slice( $fields, 0, self::MAX_FIELDS );

		// Every ref the model used anywhere, collected before any is assigned.
		// A generated fallback must avoid this whole set, not just the refs
		// already taken: reusing a name the model claims LATER would silently
		// re-point that field's conditions at this one, and a condition aimed at
		// the wrong field is worse than no condition at all.
		$claimed = array();
		foreach ( $capped as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['ref'] ) ) {
				continue;
			}
			$key = sanitize_key( (string) $field['ref'] );
			if ( '' !== $key ) {
				$claimed[ $key ] = true;
			}
		}

		foreach ( $capped as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

			if ( ! in_array( $type, $allowed, true ) ) {
				continue;
			}

			$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';

			if ( '' === $label && ! in_array( $type, $structural, true ) ) {
				continue;
			}

			// Refs are ours to guarantee, not the model's. A duplicate would make
			// a condition point at whichever field happened to be found first, and
			// a missing one would make the field unreferenceable.
			//
			// The fallback counts past anything already taken AND anything the
			// model claims elsewhere, so a generated name can never shadow a real
			// one — see the $claimed scan above.
			$ref = isset( $field['ref'] ) ? sanitize_key( (string) $field['ref'] ) : '';

			if ( '' === $ref || isset( $seen[ $ref ] ) ) {
				$n = $index + 1;
				do {
					$ref = 'f' . $n;
					$n++;
				} while ( isset( $seen[ $ref ] ) || isset( $claimed[ $ref ] ) );
			}
			$seen[ $ref ] = true;

			$options = array();
			if ( in_array( $type, $choice, true ) && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( array_slice( $field['options'], 0, 50 ) as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
			}

			// A choice field with no options is unusable in the builder; give it
			// the same two starter options a hand-added field gets.
			if ( in_array( $type, $choice, true ) && empty( $options ) ) {
				$options = array(
					__( 'Option 1', 'shapeforms' ),
					__( 'Option 2', 'shapeforms' ),
				);
			}

			$width = isset( $field['width'] ) ? (string) $field['width'] : '100%';
			$width = in_array( $width, $widths, true ) ? $width : '100%';

			// Nothing that spans the form reads well in half a row, whatever the
			// model asked for.
			if ( in_array( $type, $structural, true ) || in_array( $type, array( 'textarea', 'address', 'rich_text', 'order_summary', 'signature' ), true ) ) {
				$width = '100%';
			}

			$condition = $this->sanitize_condition( $field, $refs, $operators, $valueless );

			$clean[] = array(
				'type'          => $type,
				'ref'           => $ref,
				'label'         => $label,
				'required'      => ! empty( $field['required'] ),
				'placeholder'   => isset( $field['placeholder'] ) ? sanitize_text_field( (string) $field['placeholder'] ) : '',
				'description'   => isset( $field['description'] ) ? sanitize_text_field( (string) $field['description'] ) : '',
				'default_value' => isset( $field['default_value'] ) ? sanitize_text_field( (string) $field['default_value'] ) : '',
				'options'       => $options,
				'width'         => $width,
				'min'           => isset( $field['min'] ) ? sanitize_text_field( (string) $field['min'] ) : '',
				'max'           => isset( $field['max'] ) ? sanitize_text_field( (string) $field['max'] ) : '',
				'step'          => isset( $field['step'] ) ? sanitize_text_field( (string) $field['step'] ) : '',
				'show_if'       => $condition,
			);

			// Only refs of fields that survived can be referenced, and only by
			// fields that come after them.
			$refs[ $ref ] = true;
		}

		return $clean;
	}

	/**
	 * Validates one field's conditional-visibility rule.
	 *
	 * Returns null for "always visible", which is the overwhelming majority.
	 *
	 * A ref is only accepted when it names a field that has already been kept.
	 * That single rule handles three failure modes at once: a forward reference
	 * (the builder evaluates conditions against answers already given), a
	 * self-reference (a field that hides itself can never be shown), and a
	 * reference to a field that was dropped for being an unbuildable type.
	 *
	 * @param array<string, mixed> $field     Raw field from the model.
	 * @param array<string, bool>  $refs      Refs of the fields kept so far.
	 * @param array<int, string>   $operators Allowed operators.
	 * @param array<int, string>   $valueless Operators that take no value.
	 * @return array<string, string>|null
	 */
	private function sanitize_condition( $field, $refs, $operators, $valueless ) {
		$ref = isset( $field['show_if_ref'] ) ? sanitize_key( (string) $field['show_if_ref'] ) : '';

		if ( '' === $ref || ! isset( $refs[ $ref ] ) ) {
			return null;
		}

		$operator = isset( $field['show_if_operator'] ) ? sanitize_key( (string) $field['show_if_operator'] ) : '';

		if ( ! in_array( $operator, $operators, true ) ) {
			return null;
		}

		$value = isset( $field['show_if_value'] ) ? sanitize_text_field( (string) $field['show_if_value'] ) : '';

		// Every other operator compares against something; with nothing to
		// compare against the rule would hide the field permanently.
		if ( '' === $value && ! in_array( $operator, $valueless, true ) ) {
			return null;
		}

		return array(
			'ref'      => $ref,
			'operator' => $operator,
			'value'    => in_array( $operator, $valueless, true ) ? '' : $value,
		);
	}

	// =========================================================================
	// Builder assets
	// =========================================================================

	/**
	 * Loads the builder card, modal and mapper.
	 *
	 * Gated on Lite's builder script actually being enqueued, so this never
	 * loads on an unrelated admin screen.
	 *
	 * @return void
	 */
	public function enqueue_builder_assets() {
		if ( ! wp_script_is( 'shapeforms-lite-builder', 'enqueued' ) ) {
			return;
		}

		/*
		 * Assets sit in Lite's own asset directories rather than a module folder
		 * of their own. `assets` is already in package.json's `files` array,
		 * which is the only thing that decides what ships — a new top-level
		 * directory would silently not be packaged, which has cost this plugin
		 * a shipped feature twice before.
		 */
		$css_rel = 'assets/css/ai-builder.css';
		$js_rel  = 'assets/js/ai-builder.js';
		$base    = SHAPEFORMS_LITE_PATH;
		$url     = SHAPEFORMS_LITE_URL;

		$css_ver = file_exists( $base . $css_rel ) ? (string) filemtime( $base . $css_rel ) : SHAPEFORMS_LITE_VERSION;
		$js_ver  = file_exists( $base . $js_rel ) ? (string) filemtime( $base . $js_rel ) : SHAPEFORMS_LITE_VERSION;

		wp_enqueue_style(
			'shapeforms-lite-ai-builder',
			$url . $css_rel,
			array( 'shapeforms-lite-builder' ),
			$css_ver
		);

		wp_enqueue_script(
			'shapeforms-lite-ai-builder',
			$url . $js_rel,
			array( 'jquery', 'shapeforms-lite-builder' ),
			$js_ver,
			true
		);

		wp_localize_script(
			'shapeforms-lite-ai-builder',
			'shapeformsLiteAI',
			array(
				'endpoint'     => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'maxChars'     => self::MAX_PROMPT_CHARS,
				'allowedTypes' => $this->allowed_field_types(),
				'suggestions'  => array(
					__( 'A contact form with name, email and message', 'shapeforms' ),
					__( 'A job application with a CV upload', 'shapeforms' ),
					__( 'An event registration form', 'shapeforms' ),
					__( 'A customer feedback survey with a rating', 'shapeforms' ),
				),
				'i18n'         => array(
					'cardTitle'    => __( 'Create with AI', 'shapeforms' ),
					'cardText'     => __( 'Describe the form you need and let AI build it for you.', 'shapeforms' ),
					'modalTitle'   => __( 'Describe your form', 'shapeforms' ),
					'modalIntro'   => __( 'Tell us what the form is for. The more specific you are, the better the result.', 'shapeforms' ),
					'placeholder'  => __( 'e.g. A job application form for a barista role, with name, email, phone, CV upload and availability.', 'shapeforms' ),
					'suggestLabel' => __( 'Need a starting point?', 'shapeforms' ),
					'generate'     => __( 'Generate form', 'shapeforms' ),
					'generating'   => __( 'Building your form…', 'shapeforms' ),
					'cancel'       => __( 'Cancel', 'shapeforms' ),
					'close'        => __( 'Close', 'shapeforms' ),
					'emptyPrompt'  => __( 'Describe the form you want before generating.', 'shapeforms' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'shapeforms' ),
					/* translators: %d: number of fields that could not be built. */
					'skipped'      => __( '%d field could not be built on this site and was skipped.', 'shapeforms' ),
					/* translators: %d: number of fields that could not be built. */
					'skippedMany'  => __( '%d fields could not be built on this site and were skipped.', 'shapeforms' ),
				),
			)
		);
	}
}
