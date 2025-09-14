<?php

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("SelectedRegisters", "[]");

        $this->RegisterPropertyBoolean("Entladen_Max", false);
        $this->RegisterPropertyBoolean("Laden_Max", false);
        $this->RegisterPropertyString("WallboxUser", "");     
        $this->RegisterPropertyString("WallboxPassword", "");  
        $this->RegisterPropertyString("WallboxSerial", "");  
        $this->RegisterPropertyInteger("PollIntervalWB", 0);
        $this->RegisterPropertyInteger("PollIntervalWR", 5); 
        $this->RegisterPropertyInteger("ChargePowerOffset", 0);

        $this->RegisterAttributeString("WallboxVariableMapping", "[]");

        $this->RegisterTimer('TimerWR', 0, 'Goodwe_FetchInverterData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerWB', 0, 'Goodwe_FetchWallboxData($_IPS[\'TARGET\']);');
    }

public function ApplyChanges()
{
    parent::ApplyChanges();

    $this->CreateProfile();

    $this->SetTimerInterval('TimerWR', $this->ReadPropertyInteger('PollIntervalWR') * 1000);
    $this->SetTimerInterval('TimerWB', $this->ReadPropertyInteger('PollIntervalWB') * 1000);

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
                    continue;
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
                ['ident' => 'WB_ChargePower', 'name' => 'WB - Leistung Soll', 'type' => VARIABLETYPE_INTEGER, 'profile' => 'Goodwe.WB_Power_W', 'pos' => 2],
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

    // -------- Register: robustes Anlegen + Aufräumen --------
    $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    $registerCurrentIdents = [];

    // Masterindex: liefert fehlende Felder nach
    $masterIndex = [];
    foreach ($this->GetRegisters() as $mr) {
        $masterIndex[(string)$mr['address']] = $mr;
    }

    if (is_array($selectedRegisters)) {
        foreach ($selectedRegisters as &$r) {
            if (!is_array($r)) {
                $this->SendDebug("ApplyChanges", "Eintrag ist kein Array – übersprungen: " . json_encode($r), 0);
                continue;
            }

            // Nur ausgewählte verarbeiten (wenn Spalte vorhanden)
            if (isset($r['selected']) && !$r['selected']) {
                continue;
            }

            // Fallback: wenn 'address' fehlt, nimm die unsichtbare 'addr'
            if (!isset($r['address']) && isset($r['addr'])) {
                $r['address'] = $r['addr'];
            }

            // Historisches Format (JSON in 'address') tolerieren
            if (isset($r['address']) && is_string($r['address']) && str_starts_with(trim($r['address']), "{")) {
                $decoded = json_decode($r['address'], true);
                if (is_array($decoded)) {
                    $r = array_merge($decoded, $r);
                }
            }

            // Aus Master vervollständigen
            if (!isset($r['address'])) {
                $this->SendDebug("ApplyChanges", "Kein 'address' im Eintrag: " . json_encode($r), 0);
                continue;
            }
            $addrKey = (string)$r['address'];
            if (isset($masterIndex[$addrKey])) {
                $r = array_merge($masterIndex[$addrKey], $r);
            } else {
                $this->SendDebug("ApplyChanges", "Adresse $addrKey nicht in Masterliste gefunden.", 0);
                continue;
            }

            // Pflichtfelder vorhanden?
            foreach (['address','name','type','unit','scale','pos'] as $need) {
                if (!array_key_exists($need, $r)) {
                    $this->SendDebug("ApplyChanges", "Fehlendes Feld '$need' für $addrKey", 0);
                    continue 2;
                }
            }

            // Profil/Typ zur Einheit
            $details = $this->GetVariableDetails((string)$r['unit']);
            if ($details === null) {
                $this->SendDebug("ApplyChanges", "Keine Details/Profil für Einheit '{$r['unit']}' (Addr $addrKey).", 0);
                continue;
            }

            $ident = "Addr" . $addrKey;
            $registerCurrentIdents[] = $ident;

            if (!@$this->GetIDForIdent($ident)) {
                switch ($details['type']) {
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterVariableInteger($ident, $r['name'], $details['profile'], (int)$r['pos']);
                        break;
                    case VARIABLETYPE_FLOAT:
                        $this->RegisterVariableFloat($ident, $r['name'], $details['profile'], (int)$r['pos']);
                        break;
                    case VARIABLETYPE_STRING:
                        $this->RegisterVariableString($ident, $r['name'], $details['profile'], (int)$r['pos']);
                        break;
                }
                $this->SendDebug("ApplyChanges", "Register-Variable erstellt: $ident ({$r['name']}) Profil={$details['profile']}", 0);
            }
        }

        // ggf. Schreib-Aktionen aktivieren (nur wenn vorhanden)
        foreach (['Addr45358','Addr45356','Addr47511','Addr47512'] as $writeIdent) {
            if (@$this->GetIDForIdent($writeIdent)) {
                $this->EnableAction($writeIdent);
            }
        }
    }

    // Nicht mehr benötigte Register-Variablen entfernen
    foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
        $obj = IPS_GetObject($childID);
        if (strpos($obj['ObjectIdent'], 'Addr') === 0 && !in_array($obj['ObjectIdent'], $registerCurrentIdents)) {
            $this->UnregisterVariable($obj['ObjectIdent']);
            $this->SendDebug("ApplyChanges", "Register-Variable entfernt: {$obj['ObjectIdent']}", 0);
        }
    }

        // Max-Entladen-Variable für Speicher anlegen oder löschen:
        if ($this->ReadPropertyBoolean("Entladen_Max")) {
            if (!@$this->GetIDForIdent("MaxEntladen")) {
                $this->RegisterVariableInteger("MaxEntladen", "BAT - Entladen Leistung max", "Goodwe.Watt", 152);
            }
        } else {
            if (@$this->GetIDForIdent("MaxEntladen") !== false) {
                $this->UnregisterVariable("MaxEntladen");
                $this->SendDebug("ApplyChanges", "MaxEntladen-Variable entfernt, da Entladen_Max deaktiviert.", 0);
            }
        }

        // Max-Laden-Variable für Speicher anlegen oder löschen:
        if ($this->ReadPropertyBoolean("Laden_Max")) {
            if (!@$this->GetIDForIdent("MaxLaden")) {
                $this->RegisterVariableInteger("MaxLaden", "BAT - Laden Leistung max", "Goodwe.Watt", 142);
            }
        } else {
            if (@$this->GetIDForIdent("MaxLaden") !== false) {
                $this->UnregisterVariable("MaxLaden");
                $this->SendDebug("ApplyChanges", "MaxLaden-Variable entfernt, da Laden_Max deaktiviert.", 0);
            }
        }
    }

    public function RequestAction($ident, $value)
    {
        $this->SendDebug("RequestAction", "Aktion gestartet für Ident: $ident, Wert: $value", 0);

        // Für Register
        if (strpos($ident, 'Addr') === 0) {
            $address = intval(substr($ident, 4));
            if ($this->WriteRegister($address, $value)) {
                SetValue($this->GetIDForIdent($ident), $value);
                $this->SendDebug("RequestAction", "Register $address erfolgreich geschrieben: $value", 0);
            } else {
                $this->SendDebug("RequestAction", "Fehler beim Schreiben von Register $address: $value", 0);
            }
            return;
        }

        // Für Wallbox
        $serial = $this->ReadPropertyString("WallboxSerial");
        if (empty($serial)) {
            $this->SendDebug("RequestAction", "Keine Seriennummer vorhanden – Abbruch.", 0);
            return;
        }

        switch ($ident) {
            case 'WB_Charging':
                SetValue($this->GetIDForIdent($ident), $value);
                $endpoint = $value ? '/v4/EvCharger/StartCharging' : '/v4/EvCharger/StopCharging';
                $data = ['sn' => $serial];
                if ($value) {
                    $data['mode'] = GetValue($this->GetIDForIdent('WB_ChargeMode'));
                }
                $this->SendWallboxRequest($data, $endpoint);
                break;

            case 'WB_ChargeMode':
                SetValue($this->GetIDForIdent($ident), $value);
                $data = ['sn' => $serial, 'mode' => $value];
                $this->SendWallboxRequest($data, '/v3/EvCharger/SetChargeMode');
                break;

            case 'WB_ChargePower':
            $offset = $this->ReadPropertyInteger('ChargePowerOffset');
            $val = round($value / 100) * 100 + $offset;
            $val = min(max($val, 4200), 9700); // Begrenzung

            // zuerst Variablen mit korrigiertem Wert setzen
            SetValue($this->GetIDForIdent($ident), $val);
            SetValue($this->GetIDForIdent('WB_ChargeMode'), 0);

            $kw = round($val / 1000, 1);
            $data = ['sn' => $serial, 'charge_power' => $kw];
            $this->SendWallboxRequest($data, '/v3/EvCharger/SetChargeMode');
            break;

            default:
                throw new Exception("Ungültiger Ident: $ident");
        }
    }
    
    public function FetchAll()
    {
        $this->FetchWallboxData();
        $this->FetchInverterData();
        $this->CalculateMaxPower();
    }

    public function FetchInverterData()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("RequestRead", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }

        // Parent prüfen
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            $this->SendDebug("RequestRead", "Keine gültige Parent-Instanz verbunden.", 0);
            $this->LogMessage("Goodwe", "Keine gültige Parent-Instanz verbunden. RequestRead abgebrochen.");
            return;
        }
        $parentStatus = IPS_GetInstance($parentID)['InstanceStatus'];
        if ($parentStatus !== IS_ACTIVE) {
            $this->SendDebug("RequestRead", "Parent-Instanz ist nicht aktiv. Status: $parentStatus", 0);
            $this->LogMessage("Goodwe", "Parent-Instanz ist nicht aktiv. RequestRead abgebrochen.");
            return;
        }

        // Masterindex
        $masterIndex = [];
        foreach ($this->GetRegisters() as $mr) {
            $masterIndex[(string)$mr['address']] = $mr;
        }

        foreach ($selectedRegisters as &$r) {
            if (!is_array($r)) {
                $this->SendDebug("RequestRead", "Eintrag ist kein Array – übersprungen: " . json_encode($r), 0);
                continue;
            }
            if (isset($r['selected']) && !$r['selected']) {
                continue;
            }

            // Fallback addr -> address
            if (!isset($r['address']) && isset($r['addr'])) {
                $r['address'] = $r['addr'];
            }

            // Historisches JSON im Feld 'address' tolerieren
            if (isset($r['address']) && is_string($r['address']) && str_starts_with(trim($r['address']), "{")) {
                $decoded = json_decode($r['address'], true);
                if (is_array($decoded)) {
                    $r = array_merge($decoded, $r);
                }
            }

            if (!isset($r['address'])) {
                $this->SendDebug("RequestRead", "Kein 'address' im Eintrag: " . json_encode($r), 0);
                continue;
            }

            $addrKey = (string)$r['address'];
            if (isset($masterIndex[$addrKey])) {
                $r = array_merge($masterIndex[$addrKey], $r);
            } else {
                $this->SendDebug("RequestRead", "Adresse $addrKey nicht in Masterliste gefunden.", 0);
                continue;
            }

            // Pflichtfelder
            foreach (['address','type','scale'] as $need) {
                if (!array_key_exists($need, $r)) {
                    $this->SendDebug("RequestRead", "Ungültiger Registereintrag (fehlend: $need): " . json_encode($r), 0);
                    continue 2;
                }
            }

            $ident    = "Addr" . $addrKey;
            $quantity = (in_array($r['type'], ["U32","S32"], true)) ? 2 : 1;

            try {
                $response = $this->SendDataToParent(json_encode([
                    "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                    "Function" => 3,
                    "Address"  => (int)$r['address'],
                    "Quantity" => $quantity,
                    "Data"     => ""
                ]));

                if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                    $this->SendDebug("RequestRead", "Keine/zu kurze Antwort für Register {$r['address']}", 0);
                    continue;
                }

                $data  = unpack("n*", substr($response, 2));
                $value = 0;

                switch ($r['type']) {
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

                if ((float)$r['scale'] == 0.0) {
                    $this->SendDebug("RequestRead", "Scale = 0 (Division/Multiplikation nicht möglich) für {$r['address']}", 0);
                    continue;
                }

                $scaledValue = $value * (float)$r['scale'];

                $varID = @$this->GetIDForIdent($ident);
                if ($varID === false) {
                    $this->SendDebug("RequestRead", "Variable mit Ident $ident nicht gefunden.", 0);
                    continue;
                }

                if (GetValue($varID) !== $scaledValue) {
                    SetValue($varID, $scaledValue);
                }

                $this->SendDebug("RequestRead", "Wert für {$r['address']} ({$r['name']}): $scaledValue", 0);
            } catch (Exception $e) {
                $this->SendDebug("RequestRead", "Fehler Parent-Kommunikation: " . $e->getMessage(), 0);
                $this->LogMessage("Goodwe", "Fehler Parent: " . $e->getMessage());
            }
        }

        $this->CalculateMaxPower();
    }

    private function WriteRegister(int $address, int $value): bool
    {
        // Daten für die Modbus-Kommunikation vorbereiten
        $data = [
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}", // Modbus Gateway GUID
            "Function" => 6, // Funktionscode für Schreiben eines Registers
            "Address"  => $address,
            "Quantity" => 1, // Schreibe genau ein Register (16-Bit)
            "Data"     => utf8_encode(pack("n", $value)), // 16-Bit unsigned Wert packen
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
                if ($key === 'power') {
                    $value = $value * 1000; // kW → W
                }
        
                SetValue($varID, $value);
        
                if ($key === "workstate") {
                    $chargingState = ($value !== 0); // true = lädt, false = lädt nicht
                    $chargingVarID = @$this->GetIDForIdent('WB_Charging');
                
                    $pending = json_decode($this->GetBuffer("WallboxChanges"), true);
                    $isPending = is_array($pending) && array_key_exists('WB_Charging', $pending);
                
                    $holdUntil = intval($this->GetBuffer("ChargingHoldUntil"));
                    $now = time();
                    $isBlocked = ($holdUntil > $now);
                
                    if ($chargingVarID !== false && !$isPending && !$isBlocked) {
                        SetValue($chargingVarID, $chargingState);
                        $this->SendDebug("FetchWallboxData", "WB_Charging aktualisiert auf " . ($chargingState ? "true" : "false"), 0);
                    } elseif ($isBlocked) {
                        $this->SendDebug("FetchWallboxData", "WB_Charging nicht aktualisiert – Rückmeldung blockiert bis " . date('H:i:s', $holdUntil), 0);
                    } elseif ($isPending) {
                        $this->SendDebug("FetchWallboxData", "WB_Charging nicht aktualisiert – eigene Änderung steht noch aus.", 0);
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

    public function CalculateMaxPower() //Berechnen der maximal möglichen Leistung des Speichers
    {
        if ($this->ReadPropertyBoolean("Entladen_Max")) {
            $entladenID = @$this->GetIDForIdent("MaxEntladen");
            if ($entladenID !== false) {
                $spannung = $this->ReadRegisterValue(47904, 0.1);
                $strom = $this->ReadRegisterValue(47905, 0.1);
                if ($spannung !== null && $strom !== null) {
                    $leistung = intval($spannung * $strom);
                    SetValue($entladenID, $leistung);
                    $this->SendDebug("CalculateMaxPower", "Entladen Max: $spannung V * $strom A = $leistung W", 0);
                }
            }
        }
    
        if ($this->ReadPropertyBoolean("Laden_Max")) {
            $ladenID = @$this->GetIDForIdent("MaxLaden");
            if ($ladenID !== false) {
                $spannung = $this->ReadRegisterValue(47902, 0.1);
                $strom = $this->ReadRegisterValue(47903, 0.1);
                if ($spannung !== null && $strom !== null) {
                    $leistung = intval($spannung * $strom);
                    SetValue($ladenID, $leistung);
                    $this->SendDebug("CalculateMaxPower", "Laden Max: $spannung V * $strom A = $leistung W", 0);
                }
            }
        }
    }    

    private function ReadRegisterValue(int $address, float $scale = 1.0) //Auslesen der Register für Zusätzliche Werte
    {
        $quantity = 1; // 1 Register (16 Bit)
    
        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity,
            "Data"     => ""
        ]));
    
        if ($response === false || strlen($response) < 4) {
            $this->SendDebug("ReadRegisterValue", "Keine Antwort oder zu kurze Antwort für Register $address", 0);
            return null;
        }
    
        $data = unpack("n*", substr($response, 2));
        $value = $data[1];
    
        // Umwandlung für signed S16:
        if ($value & 0x8000) {
            $value = -((~$value & 0xFFFF) + 1);
        }
    
        // Skalierung:
        return $value * $scale;
    }

    public function GetConfigurationForm()
    {
        $all = $this->GetRegisters();

        // Bisher gespeicherte Auswahl laden (Array von Arrays oder ggf. JSON-Strings)
        $selected = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selected)) {
            $selected = [];
        }

        // Bereits ausgewählte Adressen herausfinden (tolerant)
        $selectedMap = [];
        foreach ($selected as $sr) {
            if (is_string($sr)) {
                $tmp = json_decode($sr, true);
                if (is_array($tmp)) {
                    if (isset($tmp['address'])) {
                        $selectedMap[(string)$tmp['address']] = true;
                    } elseif (isset($tmp['addr'])) {
                        $selectedMap[(string)$tmp['addr']] = true;
                    }
                }
            } elseif (is_array($sr)) {
                if (isset($sr['address'])) {
                    $selectedMap[(string)$sr['address']] = true;
                } elseif (isset($sr['addr'])) {
                    $selectedMap[(string)$sr['addr']] = true;
                }
            }
        }

        // Zeilen für die Liste aufbauen
        $values = array_map(function($r) use ($selectedMap) {
            return [
                "selected" => isset($selectedMap[(string)$r['address']]),
                "addr"     => (string)$r['address'],  // <- unsichtbar, aber editierbar -> wird gespeichert
                "address"  => $r['address'],
                "name"     => $r['name'],
            ];
        }, $all);

        return json_encode([
            "elements" => [
                [
                    "type"     => "List",
                    "name"     => "SelectedRegisters",
                    "caption"  => "Register auswählen (Häkchen setzen)",
                    "rowCount" => 15,
                    "add"      => false,
                    "delete"   => false,
                    "columns"  => [
                        // Unsichtbare, aber editierbare Spalte – Symcon speichert so sicher die Adresse
                        [ "caption" => "", "name" => "addr", "width" => "0px", "visible" => false, "edit" => [ "type" => "ValidationTextBox" ] ],

                        [ "caption" => "Auswählen", "name" => "selected", "width" => "120px", "edit" => [ "type" => "CheckBox" ] ],
                        [ "caption" => "Adresse",   "name" => "address",  "width" => "110px" ],
                        [ "caption" => "Name",      "name" => "name",     "width" => "auto"  ],
                    ],
                    "values"   => $values
                ],
                [
                    "type"  => "IntervalBox",
                    "name"  => "PollIntervalWR",
                    "caption" => "Sekunden",
                    "suffix" => "s"
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "SEMS-API-Konfiguration (nur für Wallbox der 1. Generation erforderlich)",
                    "items" => [
                        [ "type" => "ValidationTextBox", "name" => "WallboxUser",     "caption" => "Benutzername" ],
                        [ "type" => "ValidationTextBox", "name" => "WallboxPassword", "caption" => "Passwort" ],
                        [ "type" => "ValidationTextBox", "name" => "WallboxSerial",   "caption" => "Seriennummer Wallbox" ],
                        [ "type" => "IntervalBox",       "name" => "PollIntervalWB",  "caption" => "Sekunden", "suffix" => "s" ],
                        [ "type" => "NumberSpinner",     "name" => "ChargePowerOffset", "caption" => "Soll-Ladeleistung erhöhen", "suffix" => "W" ]
                    ]
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Zusätzliche Werte berechnen",
                    "items" => [
                        [ "type" => "CheckBox", "name" => "Entladen_Max", "caption" => "Maximal mögliche Leistung für das Entladen des Speichers berechnen" ],
                        [ "type" => "CheckBox", "name" => "Laden_Max",    "caption" => "Maximal mögliche Leistung für das Laden des Speichers berechnen" ]
                    ]
                ]
            ],
            "actions" => [
                [ "type" => "Button", "caption" => "Werte lesen", "onClick" => 'Goodwe_FetchAll($id);' ]
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
            case "KΩ":
                return ["profile" => "Goodwe.kOhm", "type" => VARIABLETYPE_INTEGER];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "Goodwe.Percent", "type" => VARIABLETYPE_INTEGER];
            case "ems":
                return ["profile" => "Goodwe.EMSPowerMode", "type" => VARIABLETYPE_INTEGER];
            case "watt_ems":
                return ["profile" => "Goodwe.WattEMS", "type" => VARIABLETYPE_INTEGER];
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
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '0', 'Stoped', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '1', 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '2', 'Charge-PV', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '3', 'Discharge+PV', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '4', 'Import-AC', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '5', 'Export-AC', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '6', 'Conserve', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '7', 'Off-Grid', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '8', 'Battery-Standby', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '9', 'Buy-Power', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '10', 'Sell-Power', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '11', 'Charge-BAT', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '12', 'Discharge-BAT', '', -1);
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
        if (!IPS_VariableProfileExists('Goodwe.WB_Power_W')){
            IPS_CreateVariableProfile('Goodwe.WB_Power_W', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Goodwe.WB_Power_W', 4200, 9700, 100); //Min, Max, Schritt
            IPS_SetVariableProfileDigits('Goodwe.WB_Power_W', 0); //Nachkommastellen
            IPS_SetVariableProfileText('Goodwe.WB_Power_W', "", " W"); //Präfix, Suffix
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_Power_W', 0);
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
        if (!IPS_VariableProfileExists('Goodwe.WattEMS')){
            IPS_CreateVariableProfile('Goodwe.WattEMS', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.WattEMS', '', ' W');
            IPS_SetVariableProfileDigits('Goodwe.WattEMS', 0);
            IPS_SetVariableProfileValues('Goodwe.WattEMS', 0, 10000, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WattEMS', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Percent')){
            IPS_CreateVariableProfile('Goodwe.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.Percent', '', ' %');
            IPS_SetVariableProfileDigits('Goodwe.Percent', 0);
            IPS_SetVariableProfileValues('Goodwe.Percent', 0, 100, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Percent', 0);
        }
         if (!IPS_VariableProfileExists('Goodwe.kOhm')){
            IPS_CreateVariableProfile('Goodwe.kOhm', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.kOhm', '', ' KΩ');
            IPS_SetVariableProfileDigits('Goodwe.kOhm', 0);
            IPS_SetVariableProfileValues('Goodwe.kOhm', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.kOhm', 0);
        }       
    }

    private function GetWbVariables(): array
    {
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
            ["key" => "power", "name" => "Leistung Ist", "unit" => "W", "pos" => 4, "active" => true],
            ["key" => "current", "name" => "Strom", "unit" => "A", "pos" => 8, "active" => true],
            ["key" => "time", "name" => "lädt seit (sek)", "unit" => "dur", "pos" => 10, "active" => true],
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
        ["address" => 47512, "name" => "BAT - EMSPowerSet", "type" => "U16", "unit" => "watt_ems", "scale" => 1, "pos" => 130],
        ["address" => 47902, "name" => "BAT - Laden Spannung max", "type" => "S16", "unit" => "V", "scale" => 0.1, "pos" => 141],
        ["address" => 47903, "name" => "BAT - Laden Strom max", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 140],
        ["address" => 47904, "name" => "BAT - Entladen Spannung max", "type" => "S16", "unit" => "V", "scale" => 0.1, "pos" => 151],
        ["address" => 47905, "name" => "BAT - Entladen Strom max", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 150],
        ["address" => 47906, "name" => "BAT - Spannung", "type" => "S16", "unit" => "V", "scale" => 0.1, "pos" => 160],
        ["address" => 47907, "name" => "BAT - Strom", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 170],
        ["address" => 47908, "name" => "BAT - SOC", "type" => "S16", "unit" => "%", "scale" => 1, "pos" => 180],
        ["address" => 47909, "name" => "BAT - SOH", "type" => "S16", "unit" => "%", "scale" => 1, "pos" => 190],
        // Wechselrichter
        ["address" => 35103, "name" => "WR - Spannung String 1", "type" => "U16", "unit" => "V", "scale" => 0.1, "pos" => 200],
        ["address" => 35104, "name" => "WR - Strom String 1", "type" => "U16", "unit" => "A", "scale" => 0.1, "pos" => 210],
        ["address" => 35105, "name" => "WR - Leistung String 1", "type" => "U32", "unit" => "W", "scale" => 1, "pos" => 220],
        ["address" => 35107, "name" => "WR - Spannung String 2", "type" => "U16", "unit" => "V", "scale" => 0.1, "pos" => 230],
        ["address" => 35108, "name" => "WR - Strom String 2", "type" => "U16", "unit" => "A", "scale" => 0.1, "pos" => 240],
        ["address" => 35109, "name" => "WR - Leistung String 2", "type" => "U32", "unit" => "W", "scale" => 1, "pos" => 250],
        ["address" => 35111, "name" => "WR - Spannung String 3", "type" => "U16", "unit" => "V", "scale" => 0.1, "pos" => 251],
        ["address" => 35112, "name" => "WR - Strom String 3", "type" => "U16", "unit" => "A", "scale" => 0.1, "pos" => 252],
        ["address" => 35113, "name" => "WR - Leistung String 3", "type" => "U32", "unit" => "W", "scale" => 1, "pos" => 253],
        ["address" => 35115, "name" => "WR - Spannung String 4", "type" => "U16", "unit" => "V", "scale" => 0.1, "pos" => 254],
        ["address" => 35116, "name" => "WR - Strom String 4", "type" => "U16", "unit" => "A", "scale" => 0.1, "pos" => 255],
        ["address" => 35117, "name" => "WR - Leistung String 4", "type" => "U32", "unit" => "W", "scale" => 1, "pos" => 256],
        ["address" => 35174, "name" => "WR - Temperatur", "type" => "S16", "unit" => "°C", "scale" => 0.1, "pos" => 260],
        ["address" => 35191, "name" => "WR - Erzeugung Gesamt", "type" => "U32", "unit" => "kWh", "scale" => 0.1, "pos" => 270],
        ["address" => 35193, "name" => "WR - Erzeugung Tag", "type" => "U32", "unit" => "kWh", "scale" => 0.1, "pos" => 280],
        ["address" => 35301, "name" => "WR - Leistung Gesamt", "type" => "U32", "unit" => "W", "scale" => 1, "pos" => 290],
        ["address" => 35337, "name" => "WR - P MPPT1", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 300],
        ["address" => 35338, "name" => "WR - P MPPT2", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 310],
        ["address" => 35339, "name" => "WR - P MPPT3", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 320],
        ["address" => 35340, "name" => "WR - P MPPT4", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 321],
        ["address" => 35341, "name" => "WR - P MPPT5", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 322],
        ["address" => 35342, "name" => "WR - P MPPT6", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 323],
        ["address" => 35343, "name" => "WR - P MPPT7", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 324],
        ["address" => 35344, "name" => "WR - P MPPT8", "type" => "S16", "unit" => "W", "scale" => 1, "pos" => 325],
        ["address" => 35345, "name" => "WR - I MPPT1", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 330],
        ["address" => 35346, "name" => "WR - I MPPT2", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 340],
        ["address" => 35347, "name" => "WR - I MPPT3", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 350],
        ["address" => 35348, "name" => "WR - I MPPT4", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 351],
        ["address" => 35349, "name" => "WR - I MPPT5", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 352],
        ["address" => 35350, "name" => "WR - I MPPT6", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 353],
        ["address" => 35351, "name" => "WR - I MPPT7", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 354],
        ["address" => 35352, "name" => "WR - I MPPT8", "type" => "S16", "unit" => "A", "scale" => 0.1, "pos" => 355],
        ["address" => 35365, "name" => "WR - Isolationswiderstand", "type" => "U16", "unit" => "KΩ", "scale" => 1, "pos" => 370],
        ];
    }
}