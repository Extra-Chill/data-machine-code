<?php
// phpcs:disable -- Multiple namespaces and empty method bodies are intentional in analysis stubs.
/**
 * PHPStan contracts for Data Machine runtime APIs consumed by this plugin.
 *
 * @package DataMachineCode\Stubs
 */

namespace DataMachine\Core {

	class ExecutionContext {
		public function log( string $level, string $message, array $extra = array() ): void {
		}
	}
}

namespace DataMachine\Api {

	class WebhookVerificationResult {
		public const BAD_SIGNATURE     = 'bad_signature';
		public const MISSING_SIGNATURE = 'missing_signature';
		public const NO_ACTIVE_SECRET  = 'no_active_secret';
		public const PAYLOAD_TOO_LARGE = 'payload_too_large';

		public static function ok( ?string $secret_id = null, ?int $timestamp = null, ?int $skew = null ): self {
			return new self();
		}

		public static function fail( string $reason, ?string $detail = null, ?int $timestamp = null, ?int $skew = null ): self {
			return new self();
		}
	}
}

namespace DataMachine\Core\Steps\Settings {

	abstract class SettingsHandler {
		abstract public static function get_fields(): array;
	}
}

namespace DataMachine\Core\Steps\Fetch\Handlers {

	use DataMachine\Core\ExecutionContext;
	use DataMachine\Core\Steps\Settings\SettingsHandler;

	abstract class FetchHandler {
		protected string $handler_type;

		public function __construct( string $handler_type ) {
			$this->handler_type = $handler_type;
		}

		abstract protected function executeFetch( array $config, ExecutionContext $context ): array;

		protected function applyTimeframeFilter( int $timestamp, string $timeframe_limit ): bool {
			return true;
		}

		public function applyKeywordSearch( string $text, string $search ): bool {
			return true;
		}

		public function applyExcludeKeywords( string $text, string $exclude_keywords ): bool {
			return true;
		}
	}

	abstract class FetchHandlerSettings extends SettingsHandler {
		public static function get_common_fields(): array {
			return array();
		}
	}
}

namespace DataMachine\Core\Steps\Publish\Handlers {

	abstract class PublishHandler {
		protected string $handler_type;

		public function __construct( string $handler_type ) {
			$this->handler_type = $handler_type;
		}

		protected function successResponse( array $data ): array {
			return $data;
		}

		protected function errorResponse( string $error_message, ?array $context = null, string $severity = 'warning' ): array {
			return array();
		}

		protected function log( string $level, string $message, array $context = array() ): void {
		}
	}
}

namespace DataMachine\Core\Steps\Upsert\Handlers {

	abstract class UpsertHandler {
		abstract protected function executeUpsert( array $parameters, array $handler_config ): array;

		protected function errorResponse( string $message, array $context = array(), string $level = 'error' ): array {
			return array();
		}
	}
}

namespace DataMachine\Engine\AI\Tools {

	abstract class BaseTool {
		protected function registerTool( string $tool_name, array|callable $tool_definition, array $modes = array(), array $meta = array() ): void {
		}

		protected function buildErrorResponse( string $error, string $tool_name ): array {
			return array();
		}
	}
}

namespace AgentsAPI\AI\Tools {

	interface WP_Agent_Tool_Executor {
		public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array;
	}
}
