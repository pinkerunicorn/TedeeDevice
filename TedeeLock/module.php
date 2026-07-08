<?php

declare(strict_types=1);

class TedeeLock extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyString('BridgeIP', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyBoolean('UseEncryptedToken', true);
        $this->RegisterPropertyInteger('UpdateInterval', 10);
        $this->RegisterPropertyInteger('LockID', 0);
        $this->RegisterPropertyString('SymconBaseURL', 'http://10.1.60.150:3777');
        
        $this->RegisterAttributeInteger('DetectedLockID', 0);
        
        $this->RegisterTimer('UpdateTimer', 0, 'TEDEE_UpdateStatus($_IPS[\'TARGET\']);');

        $this->RegisterVariableInteger('LockState', 'Schloss Status', '', 1);
        $this->RegisterVariableInteger('BatteryLevel', 'Batterie', '~Battery.100', 2);
        $this->RegisterVariableBoolean('IsCharging', 'Wird geladen', '~Switch', 3);
        
        // Control variable
        $this->RegisterVariableInteger('LockControl', 'Steuerung', '', 0);
        $this->EnableAction('LockControl');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Create profiles if not exist
        if (!IPS_VariableProfileExists('Tedee.LockState')) {
            IPS_CreateVariableProfile('Tedee.LockState', 1);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 0, 'Unkalibriert', 'Warning', 0xFF0000);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 1, 'Kalibriert...', 'TurnLeft', 0x00FF00);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 2, 'Entriegelt', 'LockOpen', 0x00FF00);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 3, 'Halb-Verriegelt', 'Warning', 0xFFA500);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 4, 'Entriegelt...', 'LockOpen', 0x00FF00);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 5, 'Verriegelt...', 'LockClosed', 0xFF0000);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 6, 'Verriegelt', 'LockClosed', 0xFF0000);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 7, 'Falle gezogen', 'Door', 0x0000FF);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 8, 'Falle zieht...', 'Door', 0x0000FF);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 9, 'Unbekannt', 'Information', -1);
            IPS_SetVariableProfileAssociation('Tedee.LockState', 18, 'Update...', 'Gear', 0x00FF00);
        }
        
        if (!IPS_VariableProfileExists('Tedee.LockControl')) {
            IPS_CreateVariableProfile('Tedee.LockControl', 1);
            IPS_SetVariableProfileAssociation('Tedee.LockControl', 0, 'Entriegeln', 'LockOpen', -1);
            IPS_SetVariableProfileAssociation('Tedee.LockControl', 1, 'Verriegeln', 'LockClosed', -1);
            IPS_SetVariableProfileAssociation('Tedee.LockControl', 2, 'Falle ziehen', 'Door', -1);
        }

        $this->MaintainVariable('LockState', 'Schloss Status', 1, 'Tedee.LockState', 1, true);
        $this->MaintainVariable('LockControl', 'Steuerung', 1, 'Tedee.LockControl', 0, true);

        // Register Webhook Endpoint in Symcon
        $this->RegisterHook("/hook/Tedee_" . $this->InstanceID);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        if ($interval > 0) {
            $this->UpdateStatus();
        }

        // Auto-Register Webhook at Bridge if Polling is disabled or URL is provided
        $baseUrl = $this->ReadPropertyString('SymconBaseURL');
        if (!empty($baseUrl)) {
            $this->RegisterWebhookAtBridge();
        }
    }

    protected function RegisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if (sizeof($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $WebHook, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    protected function ProcessHookData()
    {
        $payload = file_get_contents('php://input');
        $this->SendDebug('Webhook', 'Empfange Webhook: ' . $payload, 0);

        if (empty($payload)) return;

        $events = json_decode($payload, true);
        if (!is_array($events)) return;

        $targetLockId = $this->GetActiveLockID();

        foreach ($events as $event) {
            if (!isset($event['deviceId'])) continue;

            $lockId = (int)$event['deviceId'];
            
            // Only process if it matches the configured LockID (or if 0, update the attribute and use it)
            if ($targetLockId !== 0 && $lockId !== $targetLockId) {
                continue;
            }

            if ($targetLockId === 0) {
                $this->WriteAttributeInteger('DetectedLockID', $lockId);
            }

            if (isset($event['event']) && $event['event'] === 'device-state-changed') {
                $data = $event['data'] ?? [];
                
                if (isset($data['state'])) {
                    $this->SetValue('LockState', $data['state']);
                    
                    $controlValue = -1;
                    if ($data['state'] == 2) {
                        $controlValue = 0;
                    } elseif ($data['state'] == 6) {
                        $controlValue = 1;
                    }
                    if ($controlValue !== -1 && GetValue($this->GetIDForIdent('LockControl')) != $controlValue) {
                        $this->SetValue('LockControl', $controlValue);
                    }
                }
                if (isset($data['batteryLevel'])) {
                    $this->SetValue('BatteryLevel', $data['batteryLevel']);
                }
                if (isset($data['isCharging'])) {
                    $this->SetValue('IsCharging', $data['isCharging']);
                }
            }
        }
    }

    public function RegisterWebhookAtBridge(): void
    {
        $ip = $this->ReadPropertyString('BridgeIP');
        $token = $this->ReadPropertyString('ApiToken');
        $baseUrl = $this->ReadPropertyString('SymconBaseURL');
        
        if (empty($ip) || empty($token) || empty($baseUrl)) {
            $this->SendDebug('Webhook', 'Fehlende Daten für Webhook-Registrierung', 0);
            return;
        }

        $baseUrl = rtrim($baseUrl, "/");
        $webhookUrl = $baseUrl . "/hook/Tedee_" . $this->InstanceID;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://{$ip}/v1.0/callback");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $headers = $this->GetAuthHeaders();
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $payload = json_encode([
            "url" => $webhookUrl
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->SendDebug('Webhook', "Registrierung an Bridge HTTP: $httpCode | URL: $webhookUrl | Resp: $response", 0);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident === 'LockControl') {
            if ($Value == 0) {
                $this->SendCommand('unlock');
            } elseif ($Value == 1) {
                $this->SendCommand('lock');
            } elseif ($Value == 2) {
                $this->SendCommand('pull');
            }
            
            // Fast poll to see changes immediately if polling is active
            if ($this->ReadPropertyInteger('UpdateInterval') > 0) {
                IPS_Sleep(1000);
                $this->UpdateStatus();
            }
        }
    }

    private function GetActiveLockID(): int
    {
        $configId = $this->ReadPropertyInteger('LockID');
        if ($configId > 0) {
            return $configId;
        }
        return $this->ReadAttributeInteger('DetectedLockID');
    }

    public function UpdateStatus(): void
    {
        $ip = $this->ReadPropertyString('BridgeIP');
        $token = $this->ReadPropertyString('ApiToken');
        
        if (empty($ip) || empty($token)) return;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://{$ip}/v1.0/lock");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->GetAuthHeaders());

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                $targetLockId = $this->ReadPropertyInteger('LockID');
                $found = false;
                
                foreach ($data as $lock) {
                    $lockId = (int)($lock['id'] ?? 0);
                    
                    // Match specific lock if configured, otherwise use first
                    if ($targetLockId > 0 && $lockId !== $targetLockId) {
                        continue;
                    }

                    $found = true;
                    if ($targetLockId === 0) {
                        $this->WriteAttributeInteger('DetectedLockID', $lockId);
                    }
                    
                    if (isset($lock['state'])) {
                        $this->SetValue('LockState', $lock['state']);
                        
                        // Map state to control variable to keep UI in sync
                        $controlValue = -1;
                        if ($lock['state'] == 2) { // Unlocked
                            $controlValue = 0;
                        } elseif ($lock['state'] == 6) { // Locked
                            $controlValue = 1;
                        }
                        if ($controlValue !== -1 && GetValue($this->GetIDForIdent('LockControl')) != $controlValue) {
                            $this->SetValue('LockControl', $controlValue);
                        }
                    }
                    if (isset($lock['batteryLevel'])) {
                        $this->SetValue('BatteryLevel', $lock['batteryLevel']);
                    }
                    if (isset($lock['isCharging'])) {
                        $this->SetValue('IsCharging', $lock['isCharging']);
                    }
                    break;
                }
                
                if ($found) {
                    $this->SetStatus(102);
                } else {
                    $this->SetStatus(201); // Error state
                    $this->SendDebug('UpdateStatus', "Schloss mit ID $targetLockId wurde von der Bridge nicht gemeldet.", 0);
                }
            }
        } else {
            $this->SetStatus(201); // Error state
            $this->SendDebug('UpdateStatus', "Error HTTP $httpCode: $response", 0);
        }
    }

    private function SendCommand(string $action): void
    {
        $ip = $this->ReadPropertyString('BridgeIP');
        $token = $this->ReadPropertyString('ApiToken');
        $lockId = $this->GetActiveLockID();
        
        if (empty($ip) || empty($token) || $lockId === 0) {
            $this->SendDebug('SendCommand', 'Missing IP, Token or LockID', 0);
            return;
        }

        $headers = $this->GetAuthHeaders();
        $headers[] = 'Content-Length: 0';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://{$ip}/v1.0/lock/{$lockId}/{$action}");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->SendDebug('SendCommand', "Action: $action, HTTP: $httpCode, Resp: $response", 0);
    }

    private function GetAuthHeaders(): array
    {
        $token = $this->ReadPropertyString('ApiToken');
        if ($this->ReadPropertyBoolean('UseEncryptedToken')) {
            $timestamp = (string)round(microtime(true) * 1000);
            $hash = hash('sha256', $token . $timestamp);
            $apiToken = $hash . $timestamp;
        } else {
            $apiToken = $token;
        }

        return [
            'api_token: ' . $apiToken,
            'accept: application/json'
        ];
    }
}
