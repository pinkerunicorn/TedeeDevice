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
        $this->RegisterPropertyInteger('LockID', 0);
        $this->RegisterPropertyString('SymconBaseURL', 'http://10.1.60.150:3777');
        
        $this->RegisterAttributeInteger('DetectedLockID', 0);

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
        $this->RegisterHook("Tedee_" . $this->InstanceID);

        // Fetch initial status once upon apply
        $this->UpdateStatus();

        // Auto-Register Webhook at Bridge if URL is provided
        $baseUrl = $this->ReadPropertyString('SymconBaseURL');
        if (!empty($baseUrl)) {
            $this->RegisterWebhookAtBridge();
        }
    }



    protected function ProcessHookData(): void
    {
        $payload = file_get_contents('php://input');
        $this->SendDebug('Webhook', 'Empfange Webhook: ' . $payload, 0);

        if (empty($payload)) return;

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['event'])) return;

        $targetLockId = $this->GetActiveLockID();

        $data = $event['data'] ?? [];
        if (!isset($data['deviceId'])) return;

        $lockId = (int)$data['deviceId'];
        
        // Only process if it matches the configured LockID (or if 0, update the attribute and use it)
        if ($targetLockId !== 0 && $lockId !== $targetLockId) {
            return;
        }

        if ($targetLockId === 0) {
            $this->WriteAttributeInteger('DetectedLockID', $lockId);
        }

        // Handle lock state changes
        if ($event['event'] === 'lock-status-changed') {
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
        }
        
        // Handle battery events (bridge might send them under a different event name, catching fallback)
        if (isset($data['batteryLevel'])) {
            $this->SetValue('BatteryLevel', $data['batteryLevel']);
        }
        if (isset($data['isCharging'])) {
            $this->SetValue('IsCharging', $data['isCharging']);
        }
    }

    public function RegisterWebhookAtBridge(): void
    {
        $ip = $this->ReadPropertyString('BridgeIP');
        $token = $this->ReadPropertyString('ApiToken');
        $baseUrl = rtrim($this->ReadPropertyString('SymconBaseURL'), "/");
        $webhookUrl = $baseUrl . "/hook/Tedee_" . $this->InstanceID;

        if (empty($ip) || empty($token) || empty($baseUrl)) {
            $this->SendDebug('Webhook', 'Fehlende Daten für Webhook-Registrierung', 0);
            return;
        }

        // --- STEP 1: GET ALL CALLBACKS ---
        $apiToken = $token;
        if ($this->ReadPropertyBoolean('UseEncryptedToken')) {
            $timestamp = (string)round(microtime(true) * 1000);
            $hash = hash('sha256', $token . $timestamp);
            $apiToken = $hash . $timestamp;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "api_token: $apiToken\r\nAccept: application/json\r\n",
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents("http://{$ip}/v1.0/callback", false, $context);
        $this->SendDebug('Webhook-List', "Existing: " . $response, 0);

        $callbacks = json_decode($response, true);
        if (is_array($callbacks)) {
            foreach ($callbacks as $cb) {
                // Delete ONLY old webhooks for THIS specific instance
                if (isset($cb['id']) && isset($cb['url']) && strpos($cb['url'], '/hook/Tedee_' . $this->InstanceID) !== false) {
                    sleep(1);
                    
                    $delToken = $token;
                    if ($this->ReadPropertyBoolean('UseEncryptedToken')) {
                        $timestamp = (string)round(microtime(true) * 1000);
                        $hash = hash('sha256', $token . $timestamp);
                        $delToken = $hash . $timestamp;
                    }

                    $delOpts = [
                        'http' => [
                            'method' => 'DELETE',
                            'header' => "api_token: $delToken\r\n",
                            'timeout' => 5,
                            'ignore_errors' => true
                        ]
                    ];
                    $delContext = stream_context_create($delOpts);
                    $delResponse = @file_get_contents("http://{$ip}/v1.0/callback/" . $cb['id'], false, $delContext);
                    $this->SendDebug('Webhook-Delete', "Deleted ID " . $cb['id'] . ": " . $delResponse, 0);
                }
            }
        }

        // --- STEP 3: REGISTER NEW CALLBACK ---
        sleep(1);
        
        $regToken = $token;
        if ($this->ReadPropertyBoolean('UseEncryptedToken')) {
            $timestamp = (string)round(microtime(true) * 1000);
            $hash = hash('sha256', $token . $timestamp);
            $regToken = $hash . $timestamp;
        }

        $payload = json_encode([
            "url" => $webhookUrl,
            "method" => "POST",
            "headers" => []
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "api_token: $regToken\r\n" .
                            "Content-Type: application/json\r\n" .
                            "Content-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($opts);
        $response = @file_get_contents("http://{$ip}/v1.0/callback", false, $context);
        
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            if (preg_match('/HTTP\/[\d\.]+ (\d+)/', $http_response_header[0], $matches)) {
                $httpCode = (int)$matches[1];
            }
        }

        $this->SendDebug('Webhook', "Registrierung an Bridge HTTP: $httpCode | Resp: $response", 0);
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

    protected function LogMessage($Message, $KL_MESSAGE = KL_MESSAGE)
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'TedeeLock: ' . $Message);
    }
}

