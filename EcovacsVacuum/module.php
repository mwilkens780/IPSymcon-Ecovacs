<?php

declare(strict_types=1);

/**
 * One physical Ecovacs robot vacuum. Owns no connection of its own -- reads
 * status through the shared account instance (ECO_ExecuteCommand), the same
 * pattern used by [[project_room_dashboard]]'s device modules of deriving
 * state from an already-authenticated source rather than every instance
 * logging in separately.
 *
 * Phase 1 (this version): status display only (battery, cleaning/charging
 * state). Control commands (start/pause/stop/dock/fan speed) are a planned
 * follow-up once this connection has been verified stable.
 */
class EcovacsVacuum extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('account_instance', 0);
        $this->RegisterPropertyString('device_id', '');
        $this->RegisterPropertyString('resource', '');
        $this->RegisterPropertyString('device_class', '');
        $this->RegisterPropertyInteger('update_interval', 60);

        $this->RegisterVariableInteger('Battery', $this->Translate('Batterie'), '~Battery.100', 1);
        $this->RegisterVariableBoolean('Charging', $this->Translate('Lädt'), '~Switch', 2);
        $this->RegisterVariableString('State', $this->Translate('Status'), '', 3);

        $this->RegisterTimer('UpdateTimer', 0, 'ECOV_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $accountId = $this->ReadPropertyInteger('account_instance');
        $did = $this->ReadPropertyString('device_id');
        if ($accountId <= 0 || $did === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->Refresh();
    }

    public function Refresh(): void
    {
        try {
            $accountId = $this->ReadPropertyInteger('account_instance');
            $did = $this->ReadPropertyString('device_id');
            $resource = $this->ReadPropertyString('resource');
            $class = $this->ReadPropertyString('device_class');
            if ($accountId <= 0 || $did === '' || !@IPS_InstanceExists($accountId)) {
                $this->SetStatus(201);
                return;
            }

            $batteryData = $this->fetchCommand($accountId, $did, $resource, $class, 'getBattery');
            if ($batteryData === null) {
                // offline/unreachable already logged+statused by fetchCommand
                return;
            }
            if (isset($batteryData['value'])) {
                $this->SetValue('Battery', (int) $batteryData['value']);
            }

            $chargeData = $this->fetchCommand($accountId, $did, $resource, $class, 'getChargeState');
            $isCharging = $chargeData !== null && (int) ($chargeData['isCharging'] ?? 0) === 1;
            $this->SetValue('Charging', $isCharging);

            $cleanData = $this->fetchCommand($accountId, $did, $resource, $class, 'getCleanInfo');
            $this->SetValue('State', $this->describeState($cleanData, $isCharging));

            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('EcovacsVacuum Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    /** Sends one status-query command via the account instance and returns its "data" object, or null on failure/offline. */
    private function fetchCommand(int $accountId, string $did, string $resource, string $class, string $cmdName): ?array
    {
        $raw = ECO_ExecuteCommand($accountId, $did, $resource, $class, $cmdName, '{}');
        $response = json_decode($raw, true);
        if (!is_array($response)) {
            $this->LogMessage('EcovacsVacuum: ungültige Antwort auf ' . $cmdName, KL_ERROR);
            $this->SetStatus(200);
            return null;
        }

        if (($response['ret'] ?? '') !== 'ok') {
            $errno = (int) ($response['errno'] ?? 0);
            if ($errno === 4200) {
                $this->SetValue('State', $this->Translate('Offline'));
                $this->SetStatus(104);
            } else {
                $this->LogMessage('EcovacsVacuum: ' . $cmdName . ' fehlgeschlagen: ' . json_encode($response), KL_ERROR);
                $this->SetStatus(200);
            }
            return null;
        }

        $body = $response['resp']['body'] ?? null;
        if (!is_array($body) || (isset($body['code']) && (int) $body['code'] !== 0)) {
            $this->LogMessage('EcovacsVacuum: ' . $cmdName . ' -- unerwartete Antwort: ' . json_encode($response), KL_ERROR);
            $this->SetStatus(200);
            return null;
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /** Combines getCleanInfo's state/motionState with the charge state into one readable status string. */
    private function describeState(?array $cleanData, bool $isCharging): string
    {
        if ($cleanData === null) {
            return $this->Translate('Unbekannt');
        }

        $state = $cleanData['state'] ?? '';
        if ($state === 'clean' || $state === 'washing') {
            $motionState = $cleanData['cleanState']['motionState'] ?? '';
            switch ($motionState) {
                case 'working':
                    return $this->Translate('Reinigt');
                case 'pause':
                    return $this->Translate('Pausiert');
                case 'goCharging':
                    return $this->Translate('Kehrt zur Basis zurück');
                default:
                    return $this->Translate('Reinigt');
            }
        }
        if ($state === 'goCharging') {
            return $this->Translate('Kehrt zur Basis zurück');
        }
        if ($state === 'idle') {
            return $isCharging ? $this->Translate('Lädt') : $this->Translate('Bereit');
        }

        return $this->Translate('Unbekannt');
    }

    public function GetVisualizationTile(): string
    {
        $name = htmlspecialchars($this->GetName(), ENT_QUOTES, 'UTF-8');
        $battery = $this->GetValue('Battery');
        $charging = $this->GetValue('Charging');
        $state = htmlspecialchars($this->GetValue('State'), ENT_QUOTES, 'UTF-8');

        $batteryColor = $battery <= 20 ? '#e05656' : ($battery <= 50 ? '#e0b356' : '#4caf7d');
        $chargeIcon = $charging ? ' ⚡' : '';

        return <<<HTML
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0d1520;color:#e8eef5;border-radius:12px;padding:14px;height:100%;box-sizing:border-box;display:flex;flex-direction:column;gap:10px">
  <div style="font-size:14px;font-weight:600;opacity:.85">{$name}</div>
  <div style="display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:13px;padding:4px 10px;border-radius:999px;background:#131f33;color:#9fd3ff">{$state}</div>
    <div style="font-size:13px;color:{$batteryColor}">🔋 {$battery}%{$chargeIcon}</div>
  </div>
  <div style="height:6px;border-radius:3px;background:#131f33;overflow:hidden">
    <div style="height:100%;width:{$battery}%;background:{$batteryColor}"></div>
  </div>
</div>
HTML;
    }
}
