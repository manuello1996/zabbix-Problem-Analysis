<?php declare(strict_types = 0);

namespace Modules\AnalistProblem\Actions;

use CController;
use CControllerResponseData;
use API;
use Exception;

class CControllerAnalistServicePopup extends CController {

	/**
	 * Cache for SLI data to avoid repeated API calls
	 */
	private static $sliCache = [];
	private static $cacheExpiry = 300; // Increased cache time to 5 minutes

	/**
	 * Initialize controller
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * Check user permissions
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess('ui.monitoring.problems');
	}

	/**
	 * Check input parameters
	 */
	protected function checkInput(): bool {
		$fields = [
			'hostname' => 'string',
			'hostid' => 'id',
			'eventid' => 'id',
			'triggerid' => 'id',
			'serviceid' => 'id',
			'output' => 'string',
			'selectParents' => 'string',
			'selectChildren' => 'string',
			'selectTags' => 'string',
			'selectProblemTags' => 'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse((new CControllerResponseData(['main_block' => json_encode([
				'success' => false,
				'error' => ['message' => implode(' ', array_column(get_and_clear_messages(), 'message'))]
			])]))->disableView());
		}
		return $ret;
	}

	/**
	 * Main action handler
	 */
	protected function doAction(): void {
		// Set JSON header
		header('Content-Type: application/json');
		
		$response = ['success' => false];

		try {
			// Get input parameters
			$params = [
				'hostname' => $this->getInput('hostname', ''),
				'hostid' => $this->getInput('hostid', ''),
				'eventid' => $this->getInput('eventid', ''),
				'triggerid' => $this->getInput('triggerid', ''),
				'serviceid' => $this->getInput('serviceid', '')
			];
			
			// Get event tags if eventid is provided
			$event_tags = [];
			if (!empty($params['eventid'])) {
				$event_tags = $this->getEventTags($params['eventid']);
			}

			if ($params['serviceid']) {
				// Get specific service details
				$response = $this->getServiceDetails($params['serviceid']);
			} else {
				// Get services list
				$response = $this->getServices($params, $event_tags);
			}

		} catch (Exception $e) {
			$response = [
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			];
		}

		$this->setResponse((new CControllerResponseData(['main_block' => json_encode($response)]))->disableView());
	}

	/**
	 * Get services list filtered by parameters and event tags
	 */
	private function getServices(array $params, array $event_tags = []): array {
		try {
			// Preparar parâmetros da API Service.get
			$service_params = [
				'output' => 'extend',
				'evaltype' => 2,
				'selectParents' => 'extend',
				'selectChildren' => 'extend',
				'selectTags' => 'extend',
				'selectProblemTags' => 'extend',
				'sortfield' => 'name',
				'sortorder' => 'ASC'
			];
			
			if (empty($params['eventid'])) {
				throw new Exception(_('A problem event is required.'));
			}
			if (!$event_tags) {
				return ['success' => true, 'data' => []];
			}

			// Filter candidates by tag name, then evaluate every service rule against the event.
			$service_params['problem_tags'] = [];
			foreach (array_unique(array_column($event_tags, 'tag')) as $tag) {
				$service_params['problem_tags'][] = ['tag' => $tag, 'operator' => TAG_OPERATOR_EXISTS];
			}
			$services = API::Service()->get($service_params);
			if ($services === false) {
				throw new Exception(_('Unable to load services.'));
			}
			$services = array_filter($services, function (array $service) use ($event_tags): bool {
				return $this->matchesEventTags($service['problem_tags'] ?? [], $event_tags);
			});

			// Enriquecer dados dos serviços com informações SLA e caminho completo
			$enriched_services = [];
			foreach ($services as $service) {
				// Buscar dados SLI para cada serviço (com cache)
				$sli_data = $this->getSLIDataOptimized($service['serviceid']);
				
				if ($sli_data) {
					$service['sli'] = $sli_data['sli'];
					$service['uptime'] = $sli_data['uptime'];
					$service['downtime'] = $sli_data['downtime'];
					$service['error_budget'] = $sli_data['error_budget'];
					$service['has_sla'] = $sli_data['has_sla'];
					$service['sla_id'] = $sli_data['sla_id'] ?? null;
					$service['sla_name'] = $sli_data['sla_name'] ?? null;
				} else {
					$service['sli'] = null;
					$service['uptime'] = null;
					$service['downtime'] = null;
					$service['error_budget'] = null;
					$service['has_sla'] = false;
					$service['sla_id'] = null;
					$service['sla_name'] = null;
				}
				
				// Buscar caminho completo da hierarquia
				$service['hierarchy_path'] = $this->getServiceHierarchyPath($service['serviceid']);
				
				$enriched_services[] = $service;
			}
			
			return [
				'success' => true,
				'data' => $enriched_services,
				'filters_applied' => [
					'event_tags' => $event_tags,
					'problem_tags_used' => $service_params['problem_tags'] ?? [],
					'eventid' => $params['eventid'] ?? null,
					'hostid' => $params['hostid'] ?? null,
					'hostname' => $params['hostname'] ?? null
				]
			];
			
		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			];
		}
	}

	/**
	 * Get detailed service information - OPTIMIZED
	 */
	private function getServiceDetails(string $serviceid): array {
		try {
			// Get service details
			$services = API::Service()->get([
				'output' => 'extend',
				'serviceids' => [$serviceid],
				'selectParents' => 'extend',
				'selectChildren' => 'extend',
				'selectTags' => 'extend',
				'selectProblemTags' => 'extend',
				'selectStatusRules' => 'extend'
			]);

			if (empty($services)) {
				throw new Exception(_('Service not found'));
			}

			$service = $services[0];

			// Get SLI data using optimized method
			$sli_data = $this->getSLIDataOptimized($serviceid);
			
			if ($sli_data) {
				// Use the actual data from SLA API
				$service['sli'] = $sli_data['sli'];
				$service['uptime'] = $sli_data['uptime'];
				$service['downtime'] = $sli_data['downtime'];
				$service['error_budget'] = $sli_data['error_budget'];
				$service['has_sla'] = $sli_data['has_sla'];
				$service['sla_id'] = $sli_data['sla_id'] ?? null;
				$service['sla_name'] = $sli_data['sla_name'] ?? null;
			} else {
				// No SLA data available
				$service['sli'] = null;
				$service['uptime'] = null;
				$service['downtime'] = null;
				$service['error_budget'] = null;
				$service['has_sla'] = false;
				$service['sla_id'] = null;
				$service['sla_name'] = null;
			}

			// Get SLA information if available  
			$service['sla_info'] = $this->getSLAInfo($serviceid);

			return [
				'success' => true,
				'data' => $service
			];

		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			];
		}
	}

	/**
	 * Get SLI data with optimized caching and fast failure
	 */
	private function getSLIDataOptimized(string $serviceid): ?array {
		// Check cache first
		$cacheKey = 'sli_' . $serviceid;
		if (isset(self::$sliCache[$cacheKey])) {
			$cached = self::$sliCache[$cacheKey];
			if ($cached['expiry'] > time()) {
				return $cached['data'];
			}
			unset(self::$sliCache[$cacheKey]);
		}

		try {
			// Fast SLA lookup
			$sli_data = $this->fastSlaLookup($serviceid);
			
			if ($sli_data) {
				// Cache successful result
				self::$sliCache[$cacheKey] = [
					'data' => $sli_data,
					'expiry' => time() + self::$cacheExpiry
				];
				return $sli_data;
			}
			
			// Cache null result (shorter time)
			self::$sliCache[$cacheKey] = [
				'data' => null,
				'expiry' => time() + 30
			];
			return null;
			
		} catch (Exception $e) {
			// Cache error result
			self::$sliCache[$cacheKey] = [
				'data' => null,
				'expiry' => time() + 15
			];
			return null;
		}
	}

	/**
	 * Fast SLA lookup with minimal API calls
	 */
	private function fastSlaLookup(string $serviceid): ?array {
		// Check if SLA API is available
		if (!class_exists('API') || !method_exists('API', 'SLA')) {
			return null;
		}

		try {
			// Get SLA for this service (optimized request)
			$slas = API::SLA()->get([
				'output' => ['slaid', 'name', 'slo'],
				'serviceids' => [$serviceid],
				'limit' => 1
			]);
			
			if (empty($slas)) {
				return null;
			}

			$sla = $slas[0];
			
			// Get SLI data
			$sli_response = API::SLA()->getSli([
				'slaid' => $sla['slaid'],
				'serviceids' => [(int)$serviceid],
				'periods' => 1,
				'period_from' => time()
			]);
			
			if (empty($sli_response) || 
				!isset($sli_response['serviceids'], $sli_response['sli'])) {
				return null;
			}

			$serviceids = $sli_response['serviceids'];
			$sli_data_array = $sli_response['sli'];
			
			$service_index = array_search((int)$serviceid, $serviceids);
			if ($service_index === false || 
				!isset($sli_data_array[$service_index]) || 
				empty($sli_data_array[$service_index])) {
				return null;
			}

			// Get most recent period data
			$period_data = end($sli_data_array[$service_index]);
			
			return [
				'sli' => $period_data['sli'] ?? null,
				'uptime' => $this->formatDuration($period_data['uptime'] ?? 0),
				'downtime' => $this->formatDuration($period_data['downtime'] ?? 0),
				'error_budget' => $this->formatDuration($period_data['error_budget'] ?? 0),
				'excluded_downtimes' => $period_data['excluded_downtimes'] ?? [],
				'has_sla' => true,
				'method' => 'optimized_sla_api',
				'sla_name' => $sla['name'],
				'sla_id' => $sla['slaid']
			];
			
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Format duration in seconds to human readable format
	 */
	private function formatDuration(int $seconds): string {
		// Handle negative values for error budget
		$is_negative = $seconds < 0;
		$abs_seconds = abs($seconds);
		
		if ($abs_seconds <= 0) {
			return '0s';
		}

		$days = floor($abs_seconds / 86400);
		$hours = floor(($abs_seconds % 86400) / 3600);
		$minutes = floor(($abs_seconds % 3600) / 60);
		$secs = $abs_seconds % 60;

		$parts = [];
		if ($days > 0) $parts[] = $days . 'd';
		if ($hours > 0) $parts[] = $hours . 'h';
		if ($minutes > 0) $parts[] = $minutes . 'm';
		if ($secs > 0 && empty($parts)) $parts[] = $secs . 's';

		$formatted = implode(' ', array_slice($parts, 0, 2)); // Show max 2 parts
		
		return $is_negative ? '-' . $formatted : $formatted;
	}

	/**
	 * Get SLA information for a service
	 */
	private function getSLAInfo(string $serviceid): array {
		try {
			return [
				'name' => 'Default SLA',
				'target' => '99.9%'
			];
		} catch (Exception $e) {
			return [];
		}
	}

	/**
	 * Get event tags for the specified event ID
	 */
	private function getEventTags(string $eventid): array {
		$events = API::Event()->get([
			'output' => ['eventid', 'source', 'object', 'value'],
			'selectTags' => ['tag', 'value'],
			'eventids' => [$eventid]
		]);
		if (!$events || (int) $events[0]['source'] !== EVENT_SOURCE_TRIGGERS
				|| (int) $events[0]['object'] !== EVENT_OBJECT_TRIGGER
				|| (int) $events[0]['value'] !== TRIGGER_VALUE_TRUE) {
			throw new Exception(_('Problem event not found or access denied.'));
		}
		return $events[0]['tags'] ?? [];
	}

	/** All problem-tag rules must match, as in the Zabbix service manager. */
	private function matchesEventTags(array $rules, array $event_tags): bool {
		if (!$rules) {
			return false;
		}
		foreach ($rules as $rule) {
			$matched = false;
			foreach ($event_tags as $tag) {
				if ($tag['tag'] !== $rule['tag']) {
					continue;
				}
				$value = (string) ($tag['value'] ?? '');
				$rule_value = (string) ($rule['value'] ?? '');
				$operator = (int) $rule['operator'];
				if (($operator === ZBX_SERVICE_PROBLEM_TAG_OPERATOR_EQUAL && $value === $rule_value)
						|| ($operator === ZBX_SERVICE_PROBLEM_TAG_OPERATOR_LIKE
							&& strpos($value, $rule_value) !== false)) {
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Get complete hierarchy path for a service (from root to service)
	 */
	private function getServiceHierarchyPath(string $serviceid): array {
		try {
			$path = [];
			$current_serviceid = $serviceid;
			$visited = []; // Evitar loops infinitos
			
			// Buscar recursivamente até o topo da hierarquia
			while ($current_serviceid && !in_array($current_serviceid, $visited)) {
				$visited[] = $current_serviceid;
				
				// Buscar serviço atual com seus parents
				$services = API::Service()->get([
					'output' => 'extend',
					'serviceids' => [$current_serviceid],
					'selectParents' => 'extend',
					'selectTags' => 'extend',
					'selectProblemTags' => 'extend'
				]);
				
				if (empty($services)) {
					break;
				}
				
				$service = $services[0];
				
				// Adicionar serviço atual ao início do caminho (para ficar: root -> ... -> service)
				array_unshift($path, [
					'serviceid' => $service['serviceid'],
					'name' => $service['name'],
					'status' => $service['status'],
					'algorithm' => $service['algorithm'],
					'tags' => $service['tags'],
					'problem_tags' => $service['problem_tags']
				]);
				
				// Próximo: parent (se existir)
				if (!empty($service['parents'])) {
					// Pegar o primeiro parent (assumindo que há apenas um caminho principal)
					$current_serviceid = $service['parents'][0]['serviceid'];
				} else {
					// Chegou ao topo da hierarquia
					break;
				}
			}
			
			return $path;
			
		} catch (Exception $e) {
			return [];
		}
	}
}
