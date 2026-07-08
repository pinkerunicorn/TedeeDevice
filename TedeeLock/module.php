<?php

declare(strict_types=1);

class TedeeLock extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyString('BridgeIP', '');
        $this->RegisterPropertyString('ApiToken', '');
        $this->RegisterPropertyInteger('UpdateInterval', 10);
        
        $this->RegisterAttributeInteger('LockID', 0);
        
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

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        if ($interval > 0) {
            $this->UpdateStatus();
        }
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
            
            // Fast poll to see changes immediately
            IPS_Sleep(1000);
            $this->UpdateStatus();
        }
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api_token: ' . $token,
            'accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                foreach ($data as $lock) {
                    if (isset($lock['id'])) {
                        $this->WriteAttributeInteger('LockID', $lock['id']);
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
                    break; // Just use the first lock
                }
                $this->SetStatus(102);
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
        $lockId = $this->ReadAttributeInteger('LockID');
        
        if (empty($ip) || empty($token) || $lockId === 0) {
            $this->SendDebug('SendCommand', 'Missing IP, Token or LockID', 0);
            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://{$ip}/v1.0/lock/{$lockId}/{$action}");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api_token: ' . $token,
            'accept: application/json',
            'Content-Length: 0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->SendDebug('SendCommand', "Action: $action, HTTP: $httpCode, Resp: $response", 0);
    }
}
