<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("Registers", json_encode($this->GetRegisters()));
        $this->RegisterPropertyString("SelectedRegisters", "[]");

        $this->RegisterPropertyString("WallboxUser", "");     
        $this->RegisterPropertyString("WallboxPassword", "");  
        $this->RegisterPropertyString("WallboxSerial", "");  
        $this->RegisterPropertyString("WallboxVariableMapping", "[]");
        $this->RegisterPropertyInteger("PollIntervalWB", 30);
        $this->RegisterPropertyInteger("PollIntervalWR", 5); 
        
        $this->RegisterTimer("PollerWR", 0, 'Goodwe_RequestRead(' . $this->InstanceID . ');');
        $this->RegisterTimer("PollerWB", 0, 'Goodwe_FetchWallboxData(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfile();

        $this->SetTimerInterval("PollerWR", $this->ReadPropertyInteger('PollIntervalWR') * 1000);
        $this->SetTimerInterval("PollerWB", $this->ReadPropertyInteger('PollIntervalWB') * 1000);
    
        // Wallbox-Benutzerinformationen lesen
        $user = $this->ReadPropertyString("WallboxUser");
        $password = $this->ReadPropertyString("WallboxPassword");
        $serial = $this->ReadPropertyString("WallboxSerial");

        // 1. Verarbeitung der Wallbox-Variablen nur, wenn Benutzername, Passwort und Seriennummer gesetzt sind
        $wbCurrentIdents = [];
        if (!empty($user) && !empty($password) && !empty($serial)) {
            $mapping = $this->GetWbVariables();

            foreach ($mapping as $variable) {
                if (!$variable['active']) {
                    continue; // Überspringen, wenn die Variable deaktiviert ist
                }

                $ident = "WB_" . $variable['key'];
                $wbCurrentIdents[] = $ident;

                $type = VARIABLETYPE_STRING;
                $profile = "";

                if (!empty($variable['unit'])) {
                    $details = $this->GetVariableDetails($variable['unit']);
                    if ($details !== null) {
                        $type = $details['type'];
                        $profile = $details['profile'];
                    }
                }

                if (!@$this->GetIDForIdent($ident)) {
                    switch ($type) {
                        case VARIABLETYPE_INTEGER:
                            $this->RegisterVariableInteger($ident, "WB - " . $variable['name'], $profile, $variable['pos']);
                            break;
                        case VARIABLETYPE_FLOAT:
                            $this->RegisterVariableFloat($ident, "WB - " . $variable['name'], $profile, $variable['pos']);
                            break;
                        case VARIABLETYPE_STRING:
                            $this->RegisterVariableString($ident, "WB - " . $variable['name'], $profile, $variable['pos']);
                            break;
                        case VARIABLETYPE_BOOLEAN:
                            $this->RegisterVariableBoolean($ident, "WB - " . $variable['name'], $profile, $variable['pos']);
                            break;
                    }
                    $this->SendDebug("ApplyChanges", "Wallbox-Variable erstellt: $ident mit Profil $profile.", 0);
                }
            }

            // Variablen mit Aktion für Start/Stopp, Ladeleistung und Modus
            $specialVariables = [
                ['ident' => 'WB_Charging', 'name' => 'WB - Ladevorgang', 'type' => VARIABLETYPE_BOOLEAN, 'profile' => '~Switch', 'pos' => 1],
                ['ident' => 'WB_ChargePower', 'name' => 'WB - Leistung Soll', 'type' => VARIABLETYPE_FLOAT, 'profile' => 'Goodwe.WB_Power', 'pos' => 2],
                ['ident' => 'WB_ChargeMode', 'name' => 'WB - Modus Soll', 'type' => VARIABLETYPE_INTEGER, 'profile' => 'Goodwe.WB_Mode', 'pos' => 3],
            ];

            foreach ($specialVariables as $var) {
                $wbCurrentIdents[] = $var['ident'];
                if (!@$this->GetIDForIdent($var['ident'])) {
                    switch ($var['type']) {
                        case VARIABLETYPE_BOOLEAN:
                            $this->RegisterVariableBoolean($var['ident'], $var['name'], $var['profile'], $var['pos']);
                            break;
                        case VARIABLETYPE_FLOAT:
                            $this->RegisterVariableFloat($var['ident'], $var['name'], $var['profile'], $var['pos']);
                            break;
                        case VARIABLETYPE_INTEGER:
                            $this->RegisterVariableInteger($var['ident'], $var['name'], $var['profile'], $var['pos']);
                            break;
                    }
                    $this->EnableAction($var['ident']);
                    $this->SendDebug("ApplyChanges", "Wallbox-Variable erstellt: {$var['ident']} mit Profil {$var['profile']}.", 0);
                }
            }
        } else {
            $this->SendDebug("ApplyChanges", "Wallbox-Variablen werden nicht erstellt, da Benutzername, Passwort oder Seriennummer fehlen.", 0);
        }

        // Nicht mehr benötigte Wallbox-Variablen löschen
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if (strpos($object['ObjectIdent'], 'WB_') === 0 && !in_array($object['ObjectIdent'], $wbCurrentIdents)) {
                $this->UnregisterVariable($object['ObjectIdent']);
                $this->SendDebug("ApplyChanges", "Wallbox-Variable mit Ident {$object['ObjectIdent']} gelöscht.", 0);
            }
        }

        // 2. Verarbeitung der Registervariablen
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $registerCurrentIdents = [];
    
        if (is_array($selectedRegisters)) {
            foreach ($selectedRegisters as &$selectedRegister) {
                if (is_string($selectedRegister['address'])) {
                    $decodedRegister = json_decode($selectedRegister['address'], true);
                    if ($decodedRegister !== null) {
                        $selectedRegister = array_merge($selectedRegister, $decodedRegister);
                    } else {
                        $this->SendDebug("ApplyChanges", "Ungültiger JSON-String für Address: " . $selectedRegister['address'], 0);
                        continue;
                    }
                }
    
                $variableDetails = $this->GetVariableDetails($selectedRegister['unit']);
                if ($variableDetails === null) {
                    $this->SendDebug("ApplyChanges", "Kein Profil oder Typ für Einheit {$selectedRegister['unit']} gefunden.", 0);
                    continue;
                }
    
                $ident = "Addr" . $selectedRegister['address'];
                $registerCurrentIdents[] = $ident;
    
                if (!@$this->GetIDForIdent($ident)) {
                    switch ($variableDetails['type']) {
                        case VARIABLETYPE_INTEGER:
                            $this->RegisterVariableInteger($ident, $selectedRegister['name'], $variableDetails['profile'], 0);
                            break;
                        case VARIABLETYPE_FLOAT:
                            $this->RegisterVariableFloat($ident, $selectedRegister['name'], $variableDetails['profile'], 0);
                            break;
                        case VARIABLETYPE_STRING:
                            $this->RegisterVariableString($ident, $selectedRegister['name'], $variableDetails['profile'], 0);
                            break;
                    }
                    $this->SendDebug("ApplyChanges", "Register-Variable erstellt: $ident mit Profil {$variableDetails['profile']}.", 0);
                }

                
                //Hier die Akttionsvariablen
                $this->EnableAction('Addr45358');
                $this->EnableAction('Addr45356');
                $this->EnableAction('Addr47511');
                $this->EnableAction('Addr47512');
    
                // Position setzen
                $variableID = $this->GetIDForIdent($ident);
                if ($variableID !== false) {
                    IPS_SetPosition($variableID, $selectedRegister['pos']);
                }
            }
        }
    
        // Nicht mehr benötigte Register-Variablen löschen
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if (strpos($object['ObjectIdent'], 'Addr') === 0 && !in_array($object['ObjectIdent'], $registerCurrentIdents)) {
                $this->UnregisterVariable($object['ObjectIdent']);
                $this->SendDebug("ApplyChanges", "Register-Variable mit Ident {$object['ObjectIdent']} gelöscht.", 0);
            }
        }
    }

    public function RequestAction($ident, $value)
    {
        // Debug-Ausgabe für Ident und Wert
        $this->SendDebug("RequestAction", "Aktion gestartet für Ident: $ident, Wert: $value", 0);
    
        // Logik für Register
        if (strpos($ident, 'Addr') === 0) { // Ident für Register-Variablen beginnt mit "Addr"
            $address = intval(substr($ident, 4)); // Extrahiere die Adresse aus dem Ident
            if ($this->WriteRegister($address, $value)) {
                SetValue($this->GetIDForIdent($ident), $value);
                $this->SendDebug("RequestAction", "Register $address erfolgreich geschrieben: $value", 0);
            } else {
                $this->SendDebug("RequestAction", "Fehler beim Schreiben von Register $address: $value", 0);
            }
            return;
        }
    
        // Logik für Wallbox-Aktionen
        $serial = $this->ReadPropertyString("WallboxSerial");
        if (empty($serial)) {
            $this->SendDebug("RequestAction", "Keine Seriennummer definiert. Aktion abgebrochen.", 0);
            IPS_LogMessage("Goodwe", "Keine Seriennummer definiert. Aktion abgebrochen.");
            return;
        }
    
        switch ($ident) {
            case 'WB_Charging':
                $endpoint = $value ? '/v4/EvCharger/StartCharging' : '/v4/EvCharger/StopCharging';
                $data = ['sn' => $serial];
                if ($value) {
                    $data['mode'] = GetValue($this->GetIDForIdent('WB_ChargeMode'));
                }
                $response = $this->SendWallboxRequest($data, $endpoint);
                if ($response !== null) {
                    SetValue($this->GetIDForIdent($ident), $value);
                }
                break;
    
            case 'WB_ChargeMode':
                SetValue($this->GetIDForIdent($ident), $value);
                if (GetValue($this->GetIDForIdent('WB_Charging'))) {
                    $data = [
                        'sn' => $serial,
                        'mode' => $value
                    ];
                    $response = $this->SendWallboxRequest($data, '/v4/EvCharger/StartCharging');
                    if ($response !== null) {
                        $this->SendDebug("RequestAction", "WB_ChargeMode geändert und Ladevorgang gestartet.", 0);
                    }
                } else {
                    $this->SendDebug("RequestAction", "WB_ChargeMode geändert, aber WB_Charging ist nicht aktiv. Kein Ladevorgang gestartet.", 0);
                }
                break;
    
            case 'WB_ChargePower':
                $chargePower = round($value, 1);
                $chargePower = max(4.2, min($chargePower, 11));
                $data = [
                    'sn' => $serial,
                    'charge_power' => $chargePower
                ];
                $response = $this->SendWallboxRequest($data, '/v3/EvCharger/SetChargeMode');
                if ($response !== null) {
                    SetValue($this->GetIDForIdent($ident), $chargePower);
                }
                break;
    
            default:
                throw new Exception("Ungültiger Ident: $ident");
        }
    }
    
    public function FetchAll()
    {
        $this->FetchWallboxData();
        $this->RequestRead();
    }

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("RequestRead", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }
    
        // Prüfen, ob eine Verbindung zum Parent besteht
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            $this->SendDebug("RequestRead", "Keine gültige Parent-Instanz verbunden.", 0);
            IPS_LogMessage("Goodwe", "Keine gültige Parent-Instanz verbunden. RequestRead abgebrochen.");
            return;
        }
    
        // Prüfen, ob der Parent geöffnet ist
        $parentStatus = IPS_GetInstance($parentID)['InstanceStatus'];
        if ($parentStatus !== IS_ACTIVE) {
            $this->SendDebug("RequestRead", "Parent-Instanz ist nicht aktiv. Status: $parentStatus", 0);
            IPS_LogMessage("Goodwe", "Parent-Instanz ist nicht aktiv. RequestRead abgebrochen.");
            return;
        }
    
        foreach ($selectedRegisters as &$register) {
            if (is_string($register['address'])) {
                $decodedRegister = json_decode($register['address'], true);
                if ($decodedRegister !== null) {
                    $register = array_merge($register, $decodedRegister);
                } else {
                    $this->SendDebug("RequestRead", "Ungültiger JSON-String für Address: " . $register['address'], 0);
                    continue;
                }
            }
    
            // Validierung der Felder
            if (!isset($register['address'], $register['type'], $register['scale'])) {
                $this->SendDebug("RequestRead", "Ungültiger Registereintrag: " . json_encode($register), 0);
                continue;
            }
    
            $ident = "Addr" . $register['address'];
            $quantity = ($register['type'] === "U32" || $register['type'] === "S32") ? 2 : 1;
    
            try {
                $response = $this->SendDataToParent(json_encode([
                    "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                    "Function" => 3,
                    "Address"  => $register['address'],
                    "Quantity" => $quantity,
                    "Data"     => ""
                ]));
    
                if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                    $this->SendDebug("RequestRead", "Keine oder unvollständige Antwort für Register {$register['address']}", 0);
                    continue;
                }
    
                $data = unpack("n*", substr($response, 2));
                $value = 0;
    
                switch ($register['type']) {
                    case "U16":
                        $value = $data[1];
                        break;
                    case "S16":
                        $value = ($data[1] & 0x8000) ? -((~$data[1] & 0xFFFF) + 1) : $data[1];
                        break;
                    case "U32":
                        $value = ($data[1] << 16) | $data[2];
                        break;
                    case "S32":
                        $combined = ($data[1] << 16) | $data[2];
                        $value = ($data[1] & 0x8000) ? -((~$combined & 0xFFFFFFFF) + 1) : $combined;
                        break;
                }
    
                if ($register['scale'] == 0) {
                    $this->SendDebug("RequestRead", "Division durch Null für Register {$register['address']}", 0);
                    continue;
                }
    
                $scaledValue = $value * $register['scale'];
    
                $variableID = @$this->GetIDForIdent($ident);
                if ($variableID === false) {
                    $this->SendDebug("RequestRead", "Variable mit Ident $ident nicht gefunden.", 0);
                    continue;
                }
    
                SetValue($variableID, $scaledValue);
                $this->SendDebug("RequestRead", "Wert für Register {$register['address']}: $scaledValue", 0);
            } catch (Exception $e) {
                $this->SendDebug("RequestRead", "Fehler bei Kommunikation mit Parent: " . $e->getMessage(), 0);
                IPS_LogMessage("Goodwe", "Fehler bei Kommunikation mit Parent: " . $e->getMessage());
            }
        }
    }

    private function WriteRegister(int $address, int $value): bool
    {
        // Daten für die Modbus-Kommunikation vorbereiten
        $data = [
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}", // Modbus Gateway GUID
            "Function" => 6, // Funktionscode für Schreiben eines Registers
            "Address"  => $address,
            "Quantity" => 1, // Schreibe genau ein Register (16-Bit)
            "Data"     => pack("n", $value), // 16-Bit unsigned Wert packen
        ];

        // Anfrage an Parent senden
        $response = $this->SendDataToParent(json_encode($data));

        if ($response === false) {
            $this->SendDebug("WriteRegister", "Fehler beim Schreiben in Register $address", 0);
            return false;
        }

        $this->SendDebug("WriteRegister", "Erfolgreich in Register $address geschrieben: $value", 0);
        return true;
    }

    public function FetchWallboxData()
    {
        $user = $this->ReadPropertyString("WallboxUser");
        $password = $this->ReadPropertyString("WallboxPassword");
        $serial = $this->ReadPropertyString("WallboxSerial");

        if (empty($user) || empty($password) || empty($serial)) {
            $this->SendDebug("FetchWallboxData", "Wallbox-Datenabruf übersprungen: Benutzername, Passwort oder Seriennummer fehlen.", 0);
            return;
        }

        $this->SendDebug("FetchWallboxData", "Starte Wallbox-Datenabruf...", 0);

    try {
        // Login und Datenabruf
        $loginResponse = $this->GoodweLogin($user, $password);
        if (!$loginResponse) {
            $this->SendDebug("FetchWallboxData", "Login fehlgeschlagen.", 0);
            return;
        }

        $apiResponse = $this->GoodweFetchData($serial);
        if (!$apiResponse) {
            $this->SendDebug("FetchWallboxData", "API-Datenabruf fehlgeschlagen.", 0);
            return;
        }

        $data = json_decode($apiResponse, true);
        if (!isset($data['data'])) {
            $this->SendDebug("FetchWallboxData", "Keine Daten im API-Response.", 0);
            return;
        }

        foreach ($data['data'] as $key => $value) {
            $ident = "WB_" . $key;
            $varID = @$this->GetIDForIdent($ident);

            if ($varID !== false) {
                SetValue($varID, $value);

                // Aktualisierung von WB_Charging basierend auf workstate
                if ($key === "workstate") {
                    $chargingState = ($value !== 0); // false, wenn 0, true bei allen anderen Werten
                    $chargingVarID = @$this->GetIDForIdent('WB_Charging');
                    if ($chargingVarID !== false) {
                        SetValue($chargingVarID, $chargingState);
                        $this->SendDebug("FetchWallboxData", "WB_Charging aktualisiert auf " . ($chargingState ? "true" : "false") . ".", 0);
                    }
                }
            } 
        }

        $this->SendDebug("FetchWallboxData", "Wallbox-Daten erfolgreich verarbeitet.", 0);
        } catch (Exception $e) {
            $this->SendDebug("FetchWallboxData", "Fehler beim Abruf der Wallbox-Daten: " . $e->getMessage(), 0);
        }
    }

    private function GoodweFetchData(string $serial): ?string
    {
        $this->SendDebug("GoodweFetchData", "Starte API-Datenabruf für Seriennummer: $serial", 0);

        $apiEndpoint = "/v4/EvCharger/GetEvChargerAloneViewBySn";
        $body = "str=%7B%22api%22%3A%22" . urlencode($apiEndpoint) . "%22%2C%22version%22%3A%224.0%22%2C%22param%22%3A%7B%22sn%22%3A%22" . urlencode($serial) . "%22%7D%7D";

        $headers = [
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.51 Safari/537.36",
        ];

        $ch = curl_init('https://eu.semsportal.com/GopsApi/Post?s=' . urlencode($apiEndpoint));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt'); // Cookies wiederverwenden

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->SendDebug("GoodweFetchData", "API-Datenabruf fehlgeschlagen. HTTP-Code: $httpCode, Antwort: $response", 0);
            return null;
        }

        $this->SendDebug("GoodweFetchData", "API-Daten erfolgreich abgerufen. Antwort: $response", 0);
        return $response;
    }

    private function GoodweLogin(string $email, string $password): bool
    {
        $this->SendDebug("GoodweLogin", "Starte Login-Vorgang...", 0);

        $headers = [
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.51 Safari/537.36",
        ];

        $body = http_build_query([
            "account" => $email,
            "pwd" => $password,
            "code" => "",
        ]);

        $ch = curl_init('https://eu.semsportal.com/Home/Login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt'); // Cookies speichern

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->SendDebug("GoodweLogin", "Login fehlgeschlagen. HTTP-Code: $httpCode, Antwort: $response", 0);
            return false;
        }

        $this->SendDebug("GoodweLogin", "Login erfolgreich. Antwort: $response", 0);
        return true;
    }

    public function StartCharging()
    {
        $serial = $this->ReadPropertyString("WallboxSerial");

        if (empty($serial)) {
            $this->SendDebug("StartCharging", "Keine Seriennummer angegeben.", 0);
            return;
        }

        $mode = GetValue($this->GetIDForIdent("ChargingMode")); // Aktuellen Modus auslesen

        $requestData = [
            "sn" => $serial,
            "mode" => $mode // Den ausgewählten Lade-Modus an die API übergeben
        ];

        $response = $this->SendWallboxRequest($requestData, "/v4/EvCharger/StartCharging");
        if ($response) {
            $this->SendDebug("StartCharging", "Ladevorgang gestartet mit Modus $mode.", 0);
            SetValue($this->GetIDForIdent("ChargingState"), true); // Ladezustand auf aktiv setzen
        } else {
            $this->SendDebug("StartCharging", "Fehler beim Starten des Ladevorgangs.", 0);
        }
    }

    public function StopCharging()
    {
        $serial = $this->ReadPropertyString("WallboxSerial");

        if (empty($serial)) {
            $this->SendDebug("StopCharging", "Keine Seriennummer angegeben.", 0);
            return;
        }

        $requestData = [
            "sn" => $serial
        ];

        $response = $this->SendWallboxRequest($requestData, "/v4/EvCharger/StopCharging");
        if ($response) {
            $this->SendDebug("StopCharging", "Ladevorgang gestoppt.", 0);
            SetValue($this->GetIDForIdent("ChargingState"), false); // Ladezustand auf inaktiv setzen
        } else {
            $this->SendDebug("StopCharging", "Fehler beim Stoppen des Ladevorgangs.", 0);
        }
    }
    
    public function SetChargingPower(float $power)
    {
        $serial = $this->ReadPropertyString("WallboxSerial");

        if (empty($serial)) {
            $this->SendDebug("SetChargingPower", "Keine Seriennummer angegeben.", 0);
            return;
        }

        $chargePowerKW = round($power / 1000, 1); // Watt in Kilowatt umrechnen

        $requestData = [
            "sn" => $serial,
            "charge_power" => $chargePowerKW
        ];

        $response = $this->SendWallboxRequest($requestData, "/v3/EvCharger/SetChargeMode");
        if ($response) {
            $this->SendDebug("SetChargingPower", "Ladeleistung auf {$chargePowerKW} kW gesetzt.", 0);
            SetValue($this->GetIDForIdent("ChargingPower"), $power);
        } else {
            $this->SendDebug("SetChargingPower", "Fehler beim Setzen der Ladeleistung.", 0);
        }
    }

    public function SetChargingMode(int $mode)
    {
        $serial = $this->ReadPropertyString("WallboxSerial");

        if (empty($serial)) {
            $this->SendDebug("SetChargingMode", "Keine Seriennummer angegeben.", 0);
            return;
        }

        $requestData = [
            "sn" => $serial,
            "mode" => $mode
        ];

        $response = $this->SendWallboxRequest($requestData, "/v3/EvCharger/SetChargeMode");
        if ($response) {
            $this->SendDebug("SetChargingMode", "Lademodus auf {$mode} gesetzt.", 0);
            SetValue($this->GetIDForIdent("ChargingMode"), $mode);
        } else {
            $this->SendDebug("SetChargingMode", "Fehler beim Setzen des Lademodus.", 0);
        }
    }

    private function SendWallboxRequest(array $data, string $endpoint): ?array
    {
        $email = $this->ReadPropertyString("WallboxUser");
        $password = $this->ReadPropertyString("WallboxPassword");
    
        if (empty($email) || empty($password)) {
            $this->SendDebug("SendWallboxRequest", "Benutzername oder Passwort fehlen.", 0);
            return null;
        }
    
        // Login zur Wallbox
        if (!$this->LoginToWallbox($email, $password)) {
            $this->SendDebug("SendWallboxRequest", "Login fehlgeschlagen. Anfrage abgebrochen.", 0);
            return null;
        }
    
        $headers = [
            "Content-Type: application/json",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
        ];
    
        $body = json_encode([
            "str" => json_encode([
                "api" => $endpoint,
                "param" => $data
            ])
        ]);
    
        $ch = curl_init('https://eu.semsportal.com/GopsApi/Post?s=' . urlencode($endpoint));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt'); // Cookies für Session-Reuse
    
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        if ($httpCode !== 200 || !$response) {
            $this->SendDebug("SendWallboxRequest", "API-Anfrage fehlgeschlagen. HTTP-Code: $httpCode", 0);
            return null;
        }
    
        $decodedResponse = json_decode($response, true);
    
        // API-Erfolgsprüfung
        if (!isset($decodedResponse['code']) || $decodedResponse['code'] !== "0") {
            $this->SendDebug("SendWallboxRequest", "Fehler in der API-Antwort: " . json_encode($decodedResponse), 0);
            return null;
        }
    
        $this->SendDebug("SendWallboxRequest", "Erfolgreiche API-Antwort: " . json_encode($decodedResponse), 0);
        return $decodedResponse;
    }
    
    private function LoginToWallbox(string $email, string $password): bool
    {
        $headers = [
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
        ];

        $body = http_build_query([
            "account" => $email,
            "pwd" => $password
        ]);

        $ch = curl_init('https://eu.semsportal.com/Home/Login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt'); // Cookies speichern
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->SendDebug("LoginToWallbox", "Login fehlgeschlagen. HTTP-Code: $httpCode", 0);
            return false;
        }

        $decodedResponse = json_decode($response, true);

        if (!isset($decodedResponse['code']) || $decodedResponse['code'] !== 0) {
            $this->SendDebug("LoginToWallbox", "Login fehlgeschlagen: " . json_encode($decodedResponse), 0);
            return false;
        }

        $this->SendDebug("LoginToWallbox", "Login erfolgreich.", 0);
        return true;
    }

    public function GetConfigurationForm()
    {
        // Aktuelle Liste der Register abrufen und in der Property aktualisieren
        $this->ApplyChanges();
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        // Optionen für die Auswahlliste
        $registerOptions = array_map(function ($register) {
            return [
                "caption" => "{$register['address']} - {$register['name']}",
                "value" => json_encode($register)
            ];
        }, $registers);
        
        return json_encode([
            "elements" => [
                [
                    "type"  => "List",
                    "name"  => "SelectedRegisters",
                    "caption" => "Ausgewählte Register",
                    "rowCount" => 15,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        [
                            "caption" => "Register auswählen",
                            "name" => "address",
                            "width" => "400px",
                            "add" => json_encode($registers[0] ?? ""),
                            "edit" => [
                                "type" => "Select",
                                "options" => $registerOptions
                            ]
                        ]
                    ],
                    "values" => $selectedRegisters
                ],
                [
                    "type"  => "IntervalBox",
                    "name"  => "PollIntervalWR",
                    "caption" => "Sekunden",
                    "suffix" => "s"
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "SEMS-API-Konfiguration (nur für Wallbox erforderlich)",
                    "items" => [
                        [
                            "type" => "ValidationTextBox",
                            "name" => "WallboxUser",
                            "caption" => "Benutzername",
                        ],
                        [
                            "type" => "ValidationTextBox",
                            "name" => "WallboxPassword",
                            "caption" => "Passwort",
                        ],
                        [
                            "type" => "ValidationTextBox",
                            "name" => "WallboxSerial",
                            "caption" => "Seriennummer",
                        ],
                        [
                            "type"  => "IntervalBox",
                            "name"  => "PollIntervalWB",
                            "caption" => "Sekunden",
                            "suffix" => "s"
                        ]
                    ]
                ]
            ],
            "actions" => [
                [
                    "type" => "Button",
                    "caption" => "Werte lesen",
                    "onClick" => 'Goodwe_FetchAll($id);'
                ],
                [
                    "type" => "Label",
                    "caption" => "Sag danke und unterstütze den Modulentwickler:"
                ],
                [
                    "type" => "RowLayout",
                    "items" => [
                        [
                            "type" => "Image",
                            "onClick" => "echo 'https://paypal.me/mbstern';",
                           "image" => "data:image/jpeg;base64,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"
                        ],
                        [
                            "type" => "Label",
                            "caption" => ""
                        ]
                    ]
                ]
            ]
        ]);
    }
    
    private function GetVariableDetails(string $unit): ?array
    {
        switch ($unit) {
            case "V":
                return ["profile" => "~Volt", "type" => VARIABLETYPE_FLOAT];
            case "A":
                return ["profile" => "~Ampere", "type" => VARIABLETYPE_FLOAT];
            case "W":
                return ["profile" => "Goodwe.Watt", "type" => VARIABLETYPE_INTEGER];
            case "dur":
                return ["profile" => "~Duration", "type" => VARIABLETYPE_INTEGER];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            case "kW":
                return ["profile" => "~Power", "type" => VARIABLETYPE_FLOAT];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "~Battery.100", "type" => VARIABLETYPE_INTEGER];
            case "ems":
                return ["profile" => "Goodwe.EMSPowerMode", "type" => VARIABLETYPE_INTEGER];
            case "mode":
                return ["profile" => "Goodwe.Mode", "type" => VARIABLETYPE_INTEGER];
            case "wb_mode":
                return ["profile" => "Goodwe.WB_Mode", "type" => VARIABLETYPE_INTEGER];
            case "wb_work":
                return ["profile" => "Goodwe.WB_Workstate", "type" => VARIABLETYPE_INTEGER];
            case "wb_state":
                return ["profile" => "Goodwe.WB_State", "type" => VARIABLETYPE_INTEGER];
            case "String":
                return ["profile" => "~String", "type" => VARIABLETYPE_STRING];
            default:
                return null; // Kein bekanntes Profil oder Typ
        }
    }

    private function CreateProfile()
    {
        if (!IPS_VariableProfileExists('Goodwe.EMSPowerMode')){
            IPS_CreateVariableProfile('Goodwe.EMSPowerMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '1', 'Automatikmodus', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '8', 'Batteriestandby', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '11', 'Zwangsladung', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.EMSPowerMode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_State')){
            IPS_CreateVariableProfile('Goodwe.WB_State', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.WB_State', '0', 'nicht gesteckt', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_State', '1', 'gesteckt', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_State', '2', 'gesteckt und lädt', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_State', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_Mode')){
            IPS_CreateVariableProfile('Goodwe.WB_Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Mode', '0', 'Schnell', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Mode', '1', 'PV-Priorität', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Mode', '2', 'PV  & Batterie', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_Mode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_Power')){
            IPS_CreateVariableProfile('Goodwe.WB_Power', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('Goodwe.WB_Power', 4.2, 11, 0.1); //Min, Max, Schritt
            IPS_SetVariableProfileDigits('Goodwe.WB_Power', 2); //Nachkommastellen
            IPS_SetVariableProfileText('Goodwe.WB_Power', "", " kW"); //Präfix, Suffix
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_Power', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Mode')){
            IPS_CreateVariableProfile('Goodwe.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '0', 'keine Batterie', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '1', 'Standby', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '2', 'entlädt', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '3', 'lädt', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '4', 'warten auf Laden', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '5', 'warten auf Entladen', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Mode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_Workstate')){
            IPS_CreateVariableProfile('Goodwe.WB_Workstate', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '0', 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '1', 'Ladevorgang startet', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '2', 'Ladevorgang läuft', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '3', 'Ladevorgang endet', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_Workstate', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Watt')){
            IPS_CreateVariableProfile('Goodwe.Watt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.Watt', '', ' W');
            IPS_SetVariableProfileDigits('Goodwe.Watt', 0);
            IPS_SetVariableProfileValues('Goodwe.Watt', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Watt', 0);
        }
    }

    private function GetWbVariables(): array
    {
        //$this->SendDebug("GetWbVariables", "Lese Property WallboxVariableMapping...", 0);
    
        // Standardwerte definieren
        $defaultMapping = [
            ["key" => "powerStationId", "name" => "Power Station ID", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "sn", "name" => "Seriennummer", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "name", "name" => "Name", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "state", "name" => "Ladekabel", "unit" => "wb_state", "pos" => 6, "active" => true],
            ["key" => "status", "name" => "Status", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "workstate", "name" => "Ladestatus", "unit" => "wb_work", "pos" => 7, "active" => true],
            ["key" => "workstatus", "name" => "Work Status", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "lastUpdate", "name" => "Letztes Update", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "model", "name" => "Modell", "unit" => "", "pos" => 0, "active" => false], 
            ["key" => "fireware", "name" => "Firmware", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "last_fireware", "name" => "Letzte Firmware", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "startStatus", "name" => "Start Status", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "chargeEnergy", "name" => "Energie akt. Ladevorgang", "unit" => "kWh", "pos" => 9, "active" => true],
            ["key" => "power", "name" => "Leistung Ist", "unit" => "kW", "pos" => 4, "active" => true],
            ["key" => "current", "name" => "Strom", "unit" => "A", "pos" => 8, "active" => true],
            ["key" => "time", "name" => "lädt seit (min)", "unit" => "dur", "pos" => 10, "active" => true],
            ["key" => "importPowerLimit", "name" => "Import Power Limit", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "chargeMode", "name" => "Modus Ist", "unit" => "wb_mode", "pos" => 5, "active" => true],
            ["key" => "scheduleMode", "name" => "Zeitplanmodus", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "schedule_hour", "name" => "Zeitplan Stunde", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "schedule_minute", "name" => "Zeitplan Minute", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "schedule_total_minute", "name" => "Zeitplan Gesamtzeit (Minuten)", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "max_charge_power", "name" => "Maximale Ladeleistung", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "min_charge_power", "name" => "Minimale Ladeleistung", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "unitType", "name" => "Einheitstyp", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "factor", "name" => "Faktor", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "set_charge_power", "name" => "Eingestellte Ladeleistung", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "soc", "name" => "State of Charge", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "maxEnergy", "name" => "Maximale Energie", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "minEnergy", "name" => "Minimale Energie", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "finishTime", "name" => "Beendigungszeit", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "chargedNow", "name" => "Aktuell Geladen", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "dynamicLoad", "name" => "Dynamische Last", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "currentLimit", "name" => "Stromlimit", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "ensureMinimumChargingPower", "name" => "Mindestladeleistung sicherstellen", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "lockChargingPlug", "name" => "Ladestecker sperren", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "phaseSwitch", "name" => "Phasenumschaltung", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "alwaysReInitiate", "name" => "Immer neu initialisieren", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "schedule_charge_mode", "name" => "Zeitplan Lademodus", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "schedule_charge_power_setted", "name" => "Eingestellte Zeitplan Ladeleistung", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "scheduleSOC", "name" => "Zeitplan SOC", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "scheduleMaxEnergy", "name" => "Zeitplan maximale Energie", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "scheduleMinEnergy", "name" => "Zeitplan minimale Energie", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "scheduleFinishTime", "name" => "Zeitplan Beendigungszeit", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "inverterConnectionStatus", "name" => "Inverterverbindungsstatus", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "midConnectionStatus", "name" => "MID-Verbindungsstatus", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "isPermission", "name" => "Erlaubnis", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "local_date", "name" => "Lokales Datum", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "timeSpan", "name" => "Zeitspanne", "unit" => "", "pos" => 0, "active" => false],
            ["key" => "timeZone", "name" => "Zeitzone", "unit" => "", "pos" => 0, "active" => false],
        ];

    // Aktuelles Mapping auslesen
    $currentMapping = json_decode($this->ReadPropertyString("WallboxVariableMapping"), true);

    // Falls Decoding fehlschlägt, Initialisiere mit Standardwerten
    if ($currentMapping === null || !is_array($currentMapping)) {
        $this->SendDebug("GetWbVariables", "Aktuelles Mapping ungültig, setze Standardwerte.", 0);
        $currentMapping = [];
    }

    // Vergleich der Mappings
    $newMapping = json_encode($defaultMapping);
    $currentMappingJson = json_encode($currentMapping);

    if ($currentMappingJson !== $newMapping) {
        $this->SendDebug("GetWbVariables", "Mapping hat sich geändert. Aktualisiere Property.", 0);
        IPS_SetProperty($this->InstanceID, "WallboxVariableMapping", $newMapping);
        IPS_ApplyChanges($this->InstanceID); // Führt ApplyChanges aus, aber nur bei tatsächlicher Änderung
    } else {
        //$this->SendDebug("GetWbVariables", "Mapping unverändert. Keine Aktion erforderlich.", 0);
    }

    return $defaultMapping;
    }
        
    private function GetRegisters()
    {
        return [
        // Smartmeter
        ["address" => 36019, "name" => "SM - Leistung PH1", "type" => "S32", "unit" => "W", "scale" => 1, "pos" => 15],
        ["address" => 36021, "name" => "SM - Leistung PH2", "type" => "S32", "unit" => "W", "scale" => 1, "pos" => 20],
        ["address" => 36023, "name" => "SM - Leistung PH3", "type" => "S32", "unit" => "W", "scale" => 1, "pos" => 30],
        ["address" => 36025, "name" => "SM - Leistung gesamt", "type" => "S32", "unit" => "W", "scale" => 1, "pos" => 40],
        // Batterie
        ["address" => 35182, "name" => "BAT - Leistung", "type" => "S32", "unit" => "W", "scale" => 1, "pos" => 50],
        ["address" => 35184, "name" => "BAT - Mode", "type" => "U16", "unit" => "mode", "scale" => 1, "pos" => 60],
        ["address" => 35206, "name" => "BAT - Laden", "type" => "U32", "unit" => "kWh", "scale" => 0.1, "pos" => 70],
        ["address" => 35209, "name" => "BAT - Entladen", "type" => "U32", "unit" => "kWh", "scale" => 0.1, "pos" => 80],
        ["address" => 37003, "name" => "BAT - Temperatur", "type" => "U16", "unit" => "°C", "scale" => 0.1, "pos" => 90],
        ["address" => 45356, "name" => "BAT - Min SOC online", "type" => "U16", "unit" => "%", "scale" => 1, "pos" => 100],
        ["address" => 45358, "name" => "BAT - Min SOC offline", "type" => "U16", "unit" => "%", "scale" => 1, "pos" => 110],
        ["address" => 47511, "name" => "BAT - EMSPowerMode", "type" => "U16", "unit" => "ems", "scale" => 1, "pos" => 120],
        ["address" => 47512, "name" => "BAT - EMSPowerSet", "type" => "U16", "unit" => "W", "scale" => 1, "pos" => 130],
        ["address" => 47903, "name" => "BAT - Laden Strom max", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 140],
        ["address" => 47905, "name" => "BAT - Entladen Strom max", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 150],
        ["address" => 47906, "name" => "BAT - Spannung", "type" => "S16", "unit" => "V", "scale" => 0.1, "pos" => 160],
        ["address" => 47907, "name" => "BAT - Strom", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 170],
        ["address" => 47908, "name" => "BAT - SOC", "type" => "S16", "unit" => "%", "scale" => 1, "pos" => 180],
        ["address" => 47909, "name" => "BAT - SOH", "type" => "S16", "unit" => "%", "scale" => 1, "pos" => 190],
        // Wechslerichter
        ["address" => 35103, "name" => "WR - Spannung String 1", "type" => "U16", "unit" => "V", "scale" => 0.1, "pos" => 200],
        ["address" => 35104, "name" => "WR - Strom String 1", "type" => "U16", "unit" => "A", "scale" => 0.1, "pos" => 210],
        ["address" => 35105, "name" => "WR - Leistung String 1", "type" => "S16", "unit" => "W", "scale" => 0.1, "pos" => 220],
        ["address" => 35107, "name" => "WR - Spannung String 2", "type" => "U16", "unit" => "V", "scale" => 0.1, "pos" => 230],
        ["address" => 35108, "name" => "WR - Strom String 2", "type" => "U16", "unit" => "A", "scale" => 0.1, "pos" => 240],
        ["address" => 35109, "name" => "WR - Leistung String 2", "type" => "S16", "unit" => "W", "scale" => 0.1, "pos" => 250],
        ["address" => 35174, "name" => "WR - Temperatur", "type" => "S16", "unit" => "°C", "scale" => 0.1, "pos" => 260],
        ["address" => 35191, "name" => "WR - Erzeugung Gesamt", "type" => "U32", "unit" => "kWh", "scale" => 0.1, "pos" => 270],
        ["address" => 35193, "name" => "WR - Erzeugung Tag", "type" => "U32", "unit" => "kWh", "scale" => 0.1, "pos" => 280],
        ["address" => 35301, "name" => "WR - Leistung Gesamt", "type" => "U32", "unit" => "W", "scale" => 1, "pos" => 290],
        ["address" => 35337, "name" => "WR - P MPPT1", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 300],
        ["address" => 35338, "name" => "WR - P MPPT2", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 310],
        ["address" => 35339, "name" => "WR - P MPPT3", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 320],
        ["address" => 35345, "name" => "WR - I MPPT1", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 330],
        ["address" => 35346, "name" => "WR - I MPPT2", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 340],
        ["address" => 35347, "name" => "WR - I MPPT3", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 350]
    
        ];
    }
}