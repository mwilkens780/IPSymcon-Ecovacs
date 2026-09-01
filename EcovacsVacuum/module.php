<?php

declare(strict_types=1);

/**
 * One physical Ecovacs robot vacuum. Owns no connection of its own -- reads
 * status through the shared account instance (ECO_ExecuteCommand), the same
 * pattern used by [[project_room_dashboard]]'s device modules of deriving
 * state from an already-authenticated source rather than every instance
 * logging in separately.
 *
 * Status display (battery, cleaning/charging state, fan speed) plus control
 * (start/pause/stop/dock/fan speed) -- control commands go over the same
 * REST command channel as the status queries (ECO_ExecuteCommand), see
 * EcovacsAccount for why no MQTT client is needed for either.
 */
class EcovacsSaugroboter extends IPSModule
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
        $this->RegisterVariableInteger('FanSpeed', $this->Translate('Saugstufe'), '', 4);
        $this->RegisterVariableString('LastClean', $this->Translate('Letzte Reinigung'), '', 5);

        // Internal (Ecovacs' own vocabulary, not translated) mirror of the
        // "State" variable -- Clean() needs to know if the robot is
        // currently paused (to resume instead of restart) without having to
        // parse the translated display string back out.
        $this->RegisterAttributeString('RawState', 'unknown');

        // Cached JSON array of the last few cleaning sessions (from
        // ECO_GetCleanLogs) for the tile's history list -- refreshed once
        // per cycle alongside status, not exposed as separate variables
        // since it's a variable-length list.
        $this->RegisterAttributeString('CleanLogsJson', '[]');

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
                $this->pushValue('__all__', $this->collectData());
                return;
            }
            if (isset($batteryData['value'])) {
                $this->SetValue('Battery', (int) $batteryData['value']);
            }

            $chargeData = $this->fetchCommand($accountId, $did, $resource, $class, 'getChargeState');
            $isCharging = $chargeData !== null && (int) ($chargeData['isCharging'] ?? 0) === 1;
            $this->SetValue('Charging', $isCharging);

            $cleanData = $this->fetchCommand($accountId, $did, $resource, $class, 'getCleanInfo');
            $rawState = $this->determineRawState($cleanData, $isCharging);
            $this->WriteAttributeString('RawState', $rawState);
            $this->SetValue('State', $this->stateLabel($rawState));

            $speedData = $this->fetchCommand($accountId, $did, $resource, $class, 'getSpeed');
            if ($speedData !== null && isset($speedData['speed'])) {
                $this->SetValue('FanSpeed', (int) $speedData['speed']);
            }

            $this->refreshCleanLogs($accountId, $did, $resource);

            $this->pushValue('__all__', $this->collectData());
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
                $this->WriteAttributeString('RawState', 'offline');
                $this->SetValue('State', $this->stateLabel('offline'));
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

    /** Snapshot of everything the tile needs, for both the initial render and the live push via UpdateVisualizationValue(). */
    private function collectData(): array
    {
        $logs = json_decode($this->ReadAttributeString('CleanLogsJson'), true);
        $historyLines = [];
        if (is_array($logs)) {
            foreach (array_slice($logs, 0, 5) as $entry) {
                if (is_array($entry)) {
                    $historyLines[] = $this->formatCleanLogEntry($entry);
                }
            }
        }

        return [
            'battery'  => (int) $this->GetValue('Battery'),
            'charging' => (bool) $this->GetValue('Charging'),
            'state'    => (string) $this->GetValue('State'),
            'fanSpeed' => (int) $this->GetValue('FanSpeed'),
            'history'  => $historyLines,
        ];
    }

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

    private function batteryColor(int $battery): string
    {
        return $battery <= 20 ? '#e05656' : ($battery <= 50 ? '#e0b356' : '#4caf7d');
    }

    /** Encodes a translated string for safe embedding as a JS literal. */
    private function jsStr(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE);
    }

    /** Fetches the last cleaning sessions (ECO_GetCleanLogs), caches them for the tile's history list and updates "Letzte Reinigung". */
    private function refreshCleanLogs(int $accountId, string $did, string $resource): void
    {
        $raw = ECO_GetCleanLogs($accountId, $did, $resource);
        $logs = json_decode($raw, true);
        if (!is_array($logs)) {
            return;
        }

        $this->WriteAttributeString('CleanLogsJson', json_encode($logs));
        if (count($logs) > 0) {
            $this->SetValue('LastClean', $this->formatCleanLogEntry($logs[0]));
        }
    }

    /** One cleaning session -> "28.08.2026, 18:32 Uhr – 42 Min, 24 m²". */
    private function formatCleanLogEntry(array $entry): string
    {
        $when = isset($entry['ts']) ? date('d.m.Y, H:i \U\h\r', (int) $entry['ts']) : '?';
        $minutes = isset($entry['last']) ? (int) round(((int) $entry['last']) / 60) : null;
        $area = isset($entry['area']) ? (int) $entry['area'] : null;

        $parts = [$when];
        $detail = [];
        if ($minutes !== null) {
            $detail[] = $minutes . ' Min';
        }
        if ($area !== null) {
            $detail[] = $area . ' m²';
        }
        if ($detail !== []) {
            $parts[] = implode(', ', $detail);
        }
        return implode(' – ', $parts);
    }

    /** Combines getCleanInfo's state/motionState with the charge state into one of Ecovacs' own (untranslated) state keys. */
    private function determineRawState(?array $cleanData, bool $isCharging): string
    {
        if ($cleanData === null) {
            return 'unknown';
        }

        $state = $cleanData['state'] ?? '';
        if ($state === 'clean' || $state === 'washing') {
            $motionState = $cleanData['cleanState']['motionState'] ?? '';
            switch ($motionState) {
                case 'pause':
                    return 'paused';
                case 'goCharging':
                    return 'returning';
                default:
                    return 'cleaning';
            }
        }
        if ($state === 'goCharging') {
            return 'returning';
        }
        if ($state === 'idle') {
            return $isCharging ? 'charging' : 'idle';
        }

        return 'unknown';
    }

    /** Translated display text for a determineRawState() key. */
    private function stateLabel(string $raw): string
    {
        switch ($raw) {
            case 'cleaning':
                return $this->Translate('Reinigt');
            case 'paused':
                return $this->Translate('Pausiert');
            case 'returning':
                return $this->Translate('Kehrt zur Basis zurück');
            case 'charging':
                return $this->Translate('Lädt');
            case 'idle':
                return $this->Translate('Bereit');
            case 'offline':
                return $this->Translate('Offline');
            default:
                return $this->Translate('Unbekannt');
        }
    }

    /** Fan speed level (Ecovacs' own encoding) -> display label. */
    private function fanSpeedLabel(int $speed): string
    {
        switch ($speed) {
            case 1000:
                return $this->Translate('Leise');
            case 0:
                return $this->Translate('Normal');
            case 1:
                return $this->Translate('Stark');
            case 2:
                return $this->Translate('Max+');
            default:
                return (string) $speed;
        }
    }

    // ─── IPS action handler (tile buttons) ─────────────────────────────────

    public function RequestAction($Ident, $Value): void
    {
        try {
            switch ($Ident) {
                case 'clean':
                    $this->Clean();
                    break;
                case 'pause':
                    $this->Pause();
                    break;
                case 'stop':
                    $this->Stop();
                    break;
                case 'dock':
                    $this->Dock();
                    break;
                case 'fanspeed':
                    $this->SetFanSpeed((int) $Value);
                    break;
                default:
                    $this->LogMessage('EcovacsSaugroboter RequestAction: unbekannter Ident ' . $Ident, KL_WARNING);
            }
        } catch (\Throwable $e) {
            $this->LogMessage('EcovacsSaugroboter RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /** Starts cleaning -- resumes instead of restarting if the robot is currently paused. */
    public function Clean(): void
    {
        if ($this->ReadAttributeString('RawState') === 'paused') {
            $this->sendControlCommand('clean', ['act' => 'resume']);
        } else {
            $this->sendControlCommand('clean', ['act' => 'start', 'type' => 'auto']);
        }
    }

    public function Pause(): void
    {
        $this->sendControlCommand('clean', ['act' => 'pause']);
    }

    public function Stop(): void
    {
        $this->sendControlCommand('clean', ['act' => 'stop']);
    }

    /** Sends the robot back to its charging dock. */
    public function Dock(): void
    {
        $this->sendControlCommand('charge', ['act' => 'go']);
    }

    /** $level: 1000 = Leise, 0 = Normal, 1 = Stark, 2 = Max+ (not every model supports Max+). */
    public function SetFanSpeed(int $level): void
    {
        $this->sendControlCommand('setSpeed', ['speed' => $level]);
    }

    /** Sends a control command through the account instance, then re-polls the status shortly after (the robot needs a moment to reflect the change). */
    private function sendControlCommand(string $cmdName, array $args): void
    {
        $accountId = $this->ReadPropertyInteger('account_instance');
        $did = $this->ReadPropertyString('device_id');
        $resource = $this->ReadPropertyString('resource');
        $class = $this->ReadPropertyString('device_class');
        if ($accountId <= 0 || $did === '' || !@IPS_InstanceExists($accountId)) {
            $this->LogMessage('EcovacsSaugroboter: Steuerbefehl ' . $cmdName . ' ohne gültige Konfiguration ignoriert', KL_WARNING);
            return;
        }

        $raw = ECO_ExecuteCommand($accountId, $did, $resource, $class, $cmdName, json_encode($args));
        $response = json_decode($raw, true);
        if (!is_array($response) || ($response['ret'] ?? '') !== 'ok') {
            $this->LogMessage('EcovacsSaugroboter: ' . $cmdName . ' fehlgeschlagen: ' . substr((string) json_encode($response), 0, 300), KL_ERROR);
            return;
        }

        IPS_Sleep(1500);
        $this->Refresh();
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    private function buildDashboardHTML(): string
    {
        $name = htmlspecialchars(IPS_GetName($this->InstanceID), ENT_QUOTES, 'UTF-8');
        $d = $this->collectData();

        $batteryColor = $this->batteryColor($d['battery']);
        $chargeIcon = $d['charging'] ? ' ⚡' : '';
        $stateEsc = htmlspecialchars($d['state'], ENT_QUOTES, 'UTF-8');

        $speedButtons = '';
        foreach ([1000, 0, 1, 2] as $level) {
            $label = htmlspecialchars($this->fanSpeedLabel($level), ENT_QUOTES, 'UTF-8');
            $activeCls = $d['fanSpeed'] === $level ? ' active' : '';
            $speedButtons .= "<button type=\"button\" id=\"speed_{$level}\" class=\"speed-btn{$activeCls}\" onclick=\"requestAction('fanspeed', {$level})\">{$label}</button>";
        }

        $historyInner = $this->renderHistory($d['history']);
        $initJson = json_encode($d);
        $verlaufJs = $this->jsStr($this->Translate('Verlauf'));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;background:#0d1520;color:#e8eef5;display:flex;flex-direction:column;padding:14px;gap:10px}
.header{font-size:14px;font-weight:600;opacity:.85}
.row{display:flex;align-items:center;justify-content:space-between}
.badge{font-size:13px;padding:4px 10px;border-radius:999px;background:#131f33;color:#9fd3ff}
.battery-text{font-size:13px}
.battery-bar{height:6px;border-radius:3px;background:#131f33;overflow:hidden}
.battery-bar>div{height:100%;transition:width .3s}
.controls{display:flex;gap:6px}
.ctrl-btn{flex:1;border:none;border-radius:8px;padding:8px 0;font-size:16px;cursor:pointer;background:#131f33;color:#e8eef5}
.ctrl-btn.primary{background:#2d5a8f;color:#fff}
.speed-row{display:flex;gap:4px}
.speed-btn{flex:1;border:none;border-radius:6px;padding:5px 0;font-size:11px;cursor:pointer;background:#131f33;color:#9fd3ff}
.speed-btn.active{background:#2d5a8f;color:#fff}
.history-title{font-size:11px;color:#6a89a8;margin-bottom:2px}
.history-row{font-size:11px;color:#9fb3c8;padding:3px 0;border-top:1px solid #131f33}
</style>
</head>
<body>
<div class="header">{$name}</div>
<div class="row">
  <div id="state-badge" class="badge">{$stateEsc}</div>
  <div id="battery-text" class="battery-text" style="color:{$batteryColor}">🔋 {$d['battery']}%{$chargeIcon}</div>
</div>
<div class="battery-bar"><div id="battery-fill" style="width:{$d['battery']}%;background:{$batteryColor}"></div></div>
<div class="controls">
  <button type="button" class="ctrl-btn primary" onclick="requestAction('clean', 1)" title="Start/Fortsetzen">▶️</button>
  <button type="button" class="ctrl-btn" onclick="requestAction('pause', 1)" title="Pause">⏸</button>
  <button type="button" class="ctrl-btn" onclick="requestAction('stop', 1)" title="Stopp">⏹</button>
  <button type="button" class="ctrl-btn" onclick="requestAction('dock', 1)" title="Zur Basis">🏠</button>
</div>
<div class="speed-row">{$speedButtons}</div>
<div id="history-block">{$historyInner}</div>

<script>
var state = {$initJson};
var verlaufLabel = {$verlaufJs};

function battColor(v) {
  return v <= 20 ? '#e05656' : (v <= 50 ? '#e0b356' : '#4caf7d');
}

function escapeHtml(s) {
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function renderHistory(lines) {
  if (!lines || lines.length === 0) return '';
  var html = '<div class="history-title">' + verlaufLabel + '</div>';
  for (var i = 0; i < lines.length; i++) {
    html += '<div class="history-row">' + escapeHtml(lines[i]) + '</div>';
  }
  return html;
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  if (msg.key !== '__all__') return;
  var val = msg.value;
  state = val;

  var badge = document.getElementById('state-badge');
  if (badge) badge.textContent = val.state;

  var color = battColor(val.battery);
  var battText = document.getElementById('battery-text');
  if (battText) {
    battText.textContent = '🔋 ' + val.battery + '%' + (val.charging ? ' ⚡' : '');
    battText.style.color = color;
  }
  var fill = document.getElementById('battery-fill');
  if (fill) {
    fill.style.width = val.battery + '%';
    fill.style.background = color;
  }

  [1000, 0, 1, 2].forEach(function(level) {
    var btn = document.getElementById('speed_' + level);
    if (btn) btn.classList.toggle('active', val.fanSpeed === level);
  });

  var hist = document.getElementById('history-block');
  if (hist) hist.innerHTML = renderHistory(val.history);
};
</script>
</body>
</html>
HTML;
    }

    private function renderHistory(array $lines): string
    {
        if ($lines === []) {
            return '';
        }
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<div class="history-row">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        return '<div class="history-title">' . $this->Translate('Verlauf') . '</div>' . $rows;
    }
}
