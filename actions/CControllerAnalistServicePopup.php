<?php declare(strict_types = 0);

namespace Modules\AnalistProblem\Actions;

use CController;
use CControllerResponseData;
use API;
use Exception;

class CControllerAnalistServicePopup extends CController {

	/** Request-local cache; SLA visibility always belongs to the current user. */
	private $slaCache = [];

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
				$service = array_merge($service, $this->getSlaData((string) $service['serviceid']));

				// Buscar caminho completo da hierarquia
				$service['hierarchy_path'] = $this->getServiceHierarchyPath($service['serviceid']);
                // Preload visible hierarchy services using the same request-local SLA cache.
                foreach ($service['hierarchy_path'] as &$related_service) {
                    $related_service = array_merge($related_service,
                        $this->getSlaData((string) $related_service['serviceid']));
                }
                unset($related_service);
				
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

			$service = array_merge($service, $this->getSlaData($serviceid));

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

	/** Read every configured SLA and its current reporting period using native SLA boundaries. */
	private function getSlaData(string $serviceid): array {
		if (isset($this->slaCache[$serviceid])) return $this->slaCache[$serviceid];
		$result = ['has_sla' => null, 'sla_state' => 'unavailable', 'slas' => [],
			'sli' => null, 'uptime' => null, 'downtime' => null, 'error_budget' => null,
			'sla_id' => null, 'sla_name' => null, 'sla_info' => []];
		try {
			$configured = API::Sla()->get([
				'output' => ['slaid', 'name', 'slo', 'period', 'timezone', 'status', 'effective_date'],
				'serviceids' => [$serviceid], 'sortfield' => 'name', 'sortorder' => 'ASC'
			]);
			if ($configured === false || !is_array($configured)) throw new Exception('SLA lookup failed');
			$result['has_sla'] = (bool) $configured;
			$result['sla_state'] = $configured ? 'configured' : 'none';
			foreach ($configured as $sla) {
				$entry = ['slaid' => $sla['slaid'], 'name' => $sla['name'], 'slo' => $sla['slo'],
					'period' => $sla['period'], 'timezone' => $sla['timezone'], 'status' => $sla['status'],
					'effective_date' => $sla['effective_date'], 'sli_state' => 'unavailable', 'sli' => null,
					'uptime' => null, 'downtime' => null, 'error_budget' => null, 'reporting_period' => null];
				if ((int) $sla['status'] === ZBX_SLA_STATUS_ENABLED) {
					try {
						$response = API::Sla()->getSli(['slaid' => $sla['slaid'], 'serviceids' => [$serviceid], 'periods' => 1]);
						if (!is_array($response)) throw new Exception('SLI lookup failed');
						$index = array_search($serviceid, array_map('strval', $response['serviceids'] ?? []), true);
						$period_index = array_key_last($response['periods'] ?? []);
						$cell = $index !== false && $period_index !== null
							? ($response['sli'][$period_index][$index] ?? null) : null;
						$entry['reporting_period'] = $period_index !== null ? $response['periods'][$period_index] : null;
						if (is_array($cell) && is_numeric($cell['sli'] ?? null)
								&& is_finite((float) $cell['sli']) && $cell['sli'] >= 0 && $cell['sli'] <= 100) {
							$entry['sli_state'] = 'available';
							$entry['sli'] = (float) $cell['sli'];
							foreach (['uptime', 'downtime', 'error_budget'] as $field) {
								if (isset($cell[$field]) && is_numeric($cell[$field])) {
									$entry[$field] = $this->formatDuration((int) $cell[$field]);
								}
							}
						}
					} catch (Exception $e) {
						// Keep the configured name and target even when calculation fails or access changes.
					}
				} else {
					$entry['sli_state'] = 'disabled';
				}
				$result['slas'][] = $entry;
			}
			// Preserve legacy summary fields, while the UI renders every configured SLA independently.
			if ($result['slas']) {
				$primary = $result['slas'][0];
				foreach (['sli', 'uptime', 'downtime', 'error_budget'] as $field) $result[$field] = $primary[$field];
				$result['sla_id'] = $primary['slaid'];
				$result['sla_name'] = $primary['name'];
				$result['sla_info'] = ['name' => $primary['name'], 'target' => $primary['slo'].'%'];
			}
		} catch (Exception $e) {
			// A failed or denied lookup cannot establish that a service has no SLA.
		}
		return $this->slaCache[$serviceid] = $result;
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
