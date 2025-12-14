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
        $this->RegisterPropertyInteger("PollIntervalWB", 10);
        $this->RegisterPropertyInteger("PollIntervalWR", 10);
        $this->RegisterPropertyInteger("ChargePowerOffset", 0);

        $this->RegisterAttributeString("WallboxVariableMapping", "[]");

        $this->RegisterTimer('TimerWR', 0, 'Goodwe_FetchInverterData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerWB', 0, 'Goodwe_FetchWallboxData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerWBQueue', 0, 'Goodwe_ProcessWallboxQueue($_IPS[\'TARGET\']);');

    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfile();

        $this->SetTimerInterval('TimerWR', $this->ReadPropertyInteger('PollIntervalWR') * 1000);
        $this->SetTimerInterval('TimerWB', $this->ReadPropertyInteger('PollIntervalWB') * 1000);

        $user = $this->ReadPropertyString("WallboxUser");
        $password = $this->ReadPropertyString("WallboxPassword");
        $serial = $this->ReadPropertyString("WallboxSerial");

        // 1) Wallbox-Variablen nur, wenn Benutzername, Passwort und Seriennummer gesetzt sind
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
                ['ident' => 'WB_Charging',    'name' => 'WB - Ladevorgang',  'type' => VARIABLETYPE_BOOLEAN, 'profile' => '~Switch',            'pos' => 1],
                ['ident' => 'WB_ChargePower', 'name' => 'WB - Leistung Soll','type' => VARIABLETYPE_INTEGER, 'profile' => 'Goodwe.WB_Power_W', 'pos' => 2],
                ['ident' => 'WB_ChargeMode',  'name' => 'WB - Modus Soll',   'type' => VARIABLETYPE_INTEGER, 'profile' => 'Goodwe.WB_Mode',    'pos' => 3],
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

        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $registerCurrentIdents = [];

        foreach ($this->GetRegisters() as $mr) {
            $masterIndex[(string)$mr['address']] = $mr;
        }

        if (is_array($selectedRegisters)) {
            foreach ($selectedRegisters as &$r) {
                if (is_string($r)) {
                    $tmp = json_decode($r, true);
                    if (is_array($tmp)) {
                        $r = $tmp;
                    } else {
                        $this->SendDebug("ApplyChanges", "Eintrag ist kein Array – übersprungen: " . json_encode($r), 0);
                        continue;
                    }
                }

                if (isset($r['selected']) && !$r['selected']) {
                    continue;
                }

                if (!isset($r['address']) && isset($r['addr'])) {
                    $r['address'] = $r['addr'];
                }

                if (isset($r['address']) && is_string($r['address']) && str_starts_with(trim($r['address']), "{")) {
                    $decoded = json_decode($r['address'], true);
                    if (is_array($decoded)) {
                        $r = array_replace($r, $decoded);
                    }
                }

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

                foreach (['address','name','type','unit','scale','pos'] as $need) {
                    if (!array_key_exists($need, $r)) {
                        $this->SendDebug("ApplyChanges", "Fehlendes Feld '$need' für $addrKey", 0);
                        continue 2;
                    }
                }

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
                        case VARIABLETYPE_BOOLEAN:
                            $this->RegisterVariableBoolean($ident, $r['name'], $details['profile'], (int)$r['pos']);
                            break;
                    }
                    $this->SendDebug("ApplyChanges", "Register-Variable erstellt: $ident ({$r['name']}) Profil={$details['profile']}", 0);
                }
            }

            foreach (['Addr45358','Addr45356','Addr47511','Addr47512'] as $writeIdent) {
                if (@$this->GetIDForIdent($writeIdent)) {
                    $this->EnableAction($writeIdent);
                }
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if (strpos($obj['ObjectIdent'], 'Addr') === 0 && !in_array($obj['ObjectIdent'], $registerCurrentIdents)) {
                $this->UnregisterVariable($obj['ObjectIdent']);
                $this->SendDebug("ApplyChanges", "Register-Variable entfernt: {$obj['ObjectIdent']}", 0);
            }
        }

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
        $this->SendDebug("RequestAction", "Aktion gestartet für Ident: $ident, Wert: " . json_encode($value), 0);

        // Register bleibt wie gehabt
        if (strpos($ident, 'Addr') === 0) {
            $address = intval(substr($ident, 4));
            if ($this->WriteRegister($address, (int)$value)) {
                $this->SetValueIfChanged($ident, (int)$value);
                $this->SendDebug("RequestAction", "Register $address erfolgreich geschrieben: $value", 0);
            } else {
                $this->SendDebug("RequestAction", "Fehler beim Schreiben von Register $address: $value", 0);
            }
            return;
        }

        // ----------------------------
        // Wallbox
        // ----------------------------
        $serial = $this->ReadPropertyString("WallboxSerial");
        if ($serial === '') {
            $this->SendDebug("RequestAction", "Keine WallboxSerial gesetzt – Abbruch.", 0);
            return;
        }

        switch ($ident) {

            case 'WB_Charging': {
                $on = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($on === null) {
                    $on = ((int)$value === 1);
                }

                // Optimistisch setzen
                $this->SetValueIfChanged('WB_Charging', (bool)$on);

                // 5 Minuten warten auf Bestätigung via ChargeInfo
                $this->SetWbPending('WB_Charging', (bool)$on, 300);

                // 1× senden (über Queue)
                $this->QueueWallboxChange(
                    'WB_Charging',
                    ['sn' => $serial, 'on' => (bool)$on],
                    'charging'
                );

                $this->SendDebug("RequestAction", "WB_Charging queued -> " . ($on ? "ON" : "OFF"), 0);
                break;
            }

            case 'WB_ChargeMode': {
                $mode = (int)$value;

                // Optimistisch setzen
                $this->SetValueIfChanged('WB_ChargeMode', $mode);

                // Pending für Mode (ebenfalls 5 Minuten, damit es nicht zurückspringt)
                $this->SetWbPending('WB_ChargeMode', $mode, 300);

                // Optional: aktuelle Soll-Leistung mitschicken, wenn vorhanden
                $chargePowerKW = null;
                $powerID = @$this->GetIDForIdent('WB_ChargePower');
                if ($powerID !== false) {
                    $w = (int)GetValue($powerID);
                    if ($w > 0) {
                        $chargePowerKW = round($w / 1000, 1);
                    }
                }

                $this->QueueWallboxChange(
                    'WB_ChargeMode',
                    ['sn' => $serial, 'type' => $mode, 'charge_power' => $chargePowerKW],
                    'setmode'
                );

                $this->SendDebug("RequestAction", "WB_ChargeMode queued -> type=$mode, charge_power=" . json_encode($chargePowerKW), 0);
                break;
            }

            case 'WB_ChargePower': {
                $offset = (int)$this->ReadPropertyInteger('ChargePowerOffset');

                // runden auf 100W + Offset
                $val = (int)(round(((int)$value) / 100) * 100 + $offset);
                $val = min(max($val, 4200), 9700);

                // Optimistisch setzen
                $this->SetValueIfChanged('WB_ChargePower', $val);

                // Wenn du weiterhin willst: beim Setzen der Power automatisch Schnellmodus (0)
                $this->SetValueIfChanged('WB_ChargeMode', 0);

                // Pending für Power (und Mode, weil du ihn mitsendest)
                $this->SetWbPending('WB_ChargePower', $val, 300);

                $kw = round($val / 1000, 1);

                $this->QueueWallboxChange(
                    'WB_ChargePower',
                    ['sn' => $serial, 'type' => 0, 'charge_power' => $kw],
                    'setmode'
                );

                $this->SendDebug("RequestAction", "WB_ChargePower queued -> type=0, charge_power={$kw}kW", 0);
                break;
            }

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
            $this->SendDebug("FetchInverterData", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            $this->SendDebug("FetchInverterData", "Keine gültige Parent-Instanz verbunden.", 0);
            $this->LogMessage("Goodwe", "Keine gültige Parent-Instanz verbunden. FetchInverterData abgebrochen.");
            return;
        }
        $parentStatus = IPS_GetInstance($parentID)['InstanceStatus'];
        if ($parentStatus !== IS_ACTIVE) {
            $this->SendDebug("FetchInverterData", "Parent-Instanz ist nicht aktiv. Status: $parentStatus", 0);
            $this->LogMessage("Goodwe", "Parent-Instanz ist nicht aktiv. FetchInverterData abgebrochen.");
            return;
        }

        $masterIndex = [];
        foreach ($this->GetRegisters() as $mr) {
            $masterIndex[(string)$mr['address']] = $mr;
        }

        // Sammel-Array für alle gelesenen/gesetzten Werte (immer!)
        $values = [];

        foreach ($selectedRegisters as &$r) {
            if (is_string($r)) {
                $tmp = json_decode($r, true);
                if (is_array($tmp)) {
                    $r = $tmp;
                } else {
                    $this->SendDebug("FetchInverterData", "Eintrag ist kein Array – übersprungen: " . json_encode($r), 0);
                    continue;
                }
            }

            if (isset($r['selected']) && !$r['selected']) {
                continue;
            }

            if (!isset($r['address']) && isset($r['addr'])) {
                $r['address'] = $r['addr'];
            }

            if (isset($r['address']) && is_string($r['address']) && str_starts_with(trim($r['address']), "{")) {
                $decoded = json_decode($r['address'], true);
                if (is_array($decoded)) {
                    $r = array_replace($r, $decoded);
                }
            }

            if (!isset($r['address'])) {
                $this->SendDebug("FetchInverterData", "Kein 'address' im Eintrag: " . json_encode($r), 0);
                continue;
            }

            $addrKey = (string)$r['address'];
            if (isset($masterIndex[$addrKey])) {
                $r = array_merge($masterIndex[$addrKey], $r);
            } else {
                $this->SendDebug("FetchInverterData", "Adresse $addrKey nicht in Masterliste gefunden.", 0);
                continue;
            }

            foreach (['address', 'type', 'scale'] as $need) {
                if (!array_key_exists($need, $r)) {
                    $this->SendDebug("FetchInverterData", "Ungültiger Registereintrag (fehlend: $need): " . json_encode($r), 0);
                    continue 2;
                }
            }

            $ident    = "Addr" . $addrKey;
            $quantity = (in_array($r['type'], ["U32", "S32"], true)) ? 2 : 1;

            try {
                $response = $this->SendDataToParent(json_encode([
                    "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                    "Function" => 3,
                    "Address"  => (int)$r['address'],
                    "Quantity" => $quantity,
                    "Data"     => ""
                ]));

                if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                    $this->SendDebug("FetchInverterData", "Keine/zu kurze Antwort für Register {$r['address']}", 0);
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
                    default:
                        $this->SendDebug("FetchInverterData", "Unbekannter Typ '{$r['type']}' für {$r['address']}", 0);
                        continue 2;
                }

                $scale = (float)$r['scale'];
                if ($scale == 0.0) {
                    $this->SendDebug("FetchInverterData", "Scale = 0 (keine Skalierung möglich) für {$r['address']}", 0);
                    continue;
                }

                $scaledValue = $value * $scale;

                $varID = @$this->GetIDForIdent($ident);
                if ($varID === false) {
                    $this->SendDebug("FetchInverterData", "Variable mit Ident $ident nicht gefunden.", 0);
                    continue;
                }

                // Typgerecht runden/konvertieren
                $var = IPS_GetVariable($varID);
                switch ($var['VariableType']) {
                    case VARIABLETYPE_INTEGER:
                        $finalValue = (int)round($scaledValue);
                        break;

                    case VARIABLETYPE_FLOAT:
                        $scaleStr = rtrim(rtrim(number_format($scale, 10, '.', ''), '0'), '.');
                        $dotPos   = strpos($scaleStr, '.');
                        $decimals = ($dotPos === false) ? 0 : (strlen($scaleStr) - $dotPos - 1);
                        $finalValue = round((float)$scaledValue, $decimals);
                        break;

                    case VARIABLETYPE_STRING:
                        $finalValue = (string)$scaledValue;
                        break;

                    case VARIABLETYPE_BOOLEAN:
                        $finalValue = ((int)round($scaledValue)) !== 0;
                        break;

                    default:
                        $finalValue = $scaledValue;
                        break;
                }

                // In IPS nur setzen, wenn geändert
                $this->SetValueIfChanged($ident, $finalValue);

                // Für das JSON immer merken (auch wenn unverändert)
                $values[$ident] = $finalValue;

            } catch (Exception $e) {
                $this->SendDebug("FetchInverterData", "Fehler Parent-Kommunikation: " . $e->getMessage(), 0);
                $this->LogMessage("Goodwe", "Fehler Parent: " . $e->getMessage());
            }
        }

        // Immer eine Zeile Debug mit JSON der aktuellen Werte (inkl. Quelle)
        ksort($values);
        $log = [
            'source' => 'WR_Modbus',
            'values' => $values
        ];
        $this->SendDebug(
            "FetchInverterData",
            json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $this->CalculateMaxPower();
    }

    private function WriteRegister(int $address, int $value): bool
    {
        $data = [
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 6, // Funktionscode für Schreiben eines Registers
            "Address"  => $address,
            "Quantity" => 1, // 1 Register (16-Bit)
            "Data"     => utf8_encode(pack("n", $value)), // 16-Bit unsigned packen
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
        $serial = $this->ReadPropertyString("WallboxSerial");
        if ($serial === '') {
            $this->SendDebug("FetchWallboxData", "Seriennummer fehlt.", 0);
            return;
        }

        $view = $this->SemsGetWallboxStatus($serial);
        if (!is_array($view)) {
            $this->SendDebug("FetchWallboxData", "Ungültige Antwort / keine data.", 0);
            return;
        }

        $statusJson = [];

        // Rohwerte setzen
        foreach ($view as $key => $value) {
            $ident = "WB_" . $key;
            $varID = @$this->GetIDForIdent($ident);
            if ($varID === false || $value === null) {
                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $value = (strpos($value, '.') !== false) ? (float)$value : (int)$value;
            }

            // power kommt teils in kW -> W
            if ($key === 'power') {
                $value = (int)round(((float)$value) * 1000);
            }

            $this->SetValueIfChanged($ident, $value);
            $statusJson[$ident] = GetValue($varID);
        }

        // ----------------------------
        // Pending-Auswertung (5 Minuten Vertrauen)
        // ----------------------------
        $pending = $this->GetWbPending();

        // 1) WB_Charging aus ChargeInfo ableiten (aber Pending respektieren)
        $isChargingNow = null;
        $chargingFromView = $this->IsChargingFromView($view);
        if ($chargingFromView !== null) {
            $isChargingNow = (bool)$chargingFromView;
        }

        $blockChargingUpdate = false;

        if (is_array($pending) && ($pending['ident'] ?? '') === 'WB_Charging') {
            $expected = (bool)($pending['expected'] ?? false);
            $since    = (int)($pending['since'] ?? 0);
            $timeout  = (int)($pending['timeout'] ?? 300);

            // Innerhalb des Timeout: nicht überschreiben
            if ($since > 0 && (time() - $since) < $timeout) {
                $blockChargingUpdate = true;

                // Wenn ChargeInfo bereits passt -> Pending löschen (früher fertig)
                if ($isChargingNow !== null && $isChargingNow === $expected) {
                    $this->ClearWbPending();
                    $blockChargingUpdate = false;
                }
            } else {
                // Timeout: jetzt MUSS ChargeInfo passen, sonst Fehler
                if ($isChargingNow !== null && $isChargingNow === $expected) {
                    $this->ClearWbPending();
                } else {
                    $this->SetWbCommandError("Timeout nach {$timeout}s: WB_Charging wurde nicht bestätigt. Erwartet=" . (int)$expected . ", Ist=" . json_encode($isChargingNow));
                    $this->ClearWbPending();
                }
                $blockChargingUpdate = false;
            }
        }

        if (!$blockChargingUpdate && $isChargingNow !== null) {
            $this->SetValueIfChanged('WB_Charging', $isChargingNow);
        }

        $cid = @$this->GetIDForIdent('WB_Charging');
        if ($cid !== false) {
            $statusJson['WB_Charging'] = GetValue($cid);
        }

        // 2) chargeMode Ist -> WB_ChargeMode (Pending respektieren)
        if (isset($view['chargeMode'])) {
            $modeNow = is_numeric($view['chargeMode']) ? (int)$view['chargeMode'] : null;

            $blockModeUpdate = false;
            $pending = $this->GetWbPending(); // ggf. oben gelöscht → neu holen

            if (is_array($pending) && ($pending['ident'] ?? '') === 'WB_ChargeMode') {
                $expected = (int)($pending['expected'] ?? 0);
                $since    = (int)($pending['since'] ?? 0);
                $timeout  = (int)($pending['timeout'] ?? 300);

                if ($since > 0 && (time() - $since) < $timeout) {
                    $blockModeUpdate = true;
                    if ($modeNow !== null && $modeNow === $expected) {
                        $this->ClearWbPending();
                        $blockModeUpdate = false;
                    }
                } else {
                    if ($modeNow !== null && $modeNow === $expected) {
                        $this->ClearWbPending();
                    } else {
                        $this->SetWbCommandError("Timeout nach {$timeout}s: WB_ChargeMode wurde nicht bestätigt. Erwartet={$expected}, Ist=" . json_encode($modeNow));
                        $this->ClearWbPending();
                    }
                    $blockModeUpdate = false;
                }
            }

            if (!$blockModeUpdate && $modeNow !== null) {
                $this->SetValueIfChanged('WB_ChargeMode', $modeNow);
            }

            $mid = @$this->GetIDForIdent('WB_ChargeMode');
            if ($mid !== false) {
                $statusJson['WB_ChargeMode'] = GetValue($mid);
            }
        }

        ksort($statusJson);
        $this->SendDebug("FetchWallboxData", json_encode([
            'source' => 'SEMS_API_ZIP',
            'values' => $statusJson,
            'workstate_raw' => $view['workstate'] ?? null
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
    }

    private function QueueWallboxChange(string $ident, array $data, string $action): void
    {
        $queue = json_decode($this->GetBuffer('WallboxQueue'), true);
        if (!is_array($queue)) {
            $queue = [];
        }

        // Coalescing: nur den letzten pro ident behalten
        $newQueue = [];
        foreach ($queue as $cmd) {
            if (!is_array($cmd) || ($cmd['ident'] ?? '') !== $ident) {
                $newQueue[] = $cmd;
            }
        }

        $cmd = [
            'ident'  => $ident,
            'action' => $action, // 'charging' | 'setmode'
            'data'   => $data,
            'time'   => time()
        ];
        $newQueue[] = $cmd;

        $this->SetBuffer('WallboxQueue', json_encode($newQueue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Timer starten (kurz, einmalig abarbeiten)
        if ($this->GetTimerInterval('TimerWBQueue') == 0) {
            $this->SetTimerInterval('TimerWBQueue', 1000);
        }

        $this->SendDebug('QueueWallboxChange', 'Befehl in Queue: ' . json_encode($cmd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
    }

    public function ProcessWallboxQueue()
    {
        $queue = json_decode($this->GetBuffer('WallboxQueue'), true);
        if (!is_array($queue)) {
            $queue = [];
        }

        if (count($queue) === 0) {
            $this->SetTimerInterval('TimerWBQueue', 0);
            $this->SendDebug('ProcessWallboxQueue', 'Queue leer – Timer gestoppt.', 0);
            return;
        }

        $cmd = array_shift($queue);
        $this->SetBuffer('WallboxQueue', json_encode($queue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->SendDebug('ProcessWallboxQueue', 'Sende Command (1x): ' . json_encode($cmd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $ok = false;
        try {
            if (!is_array($cmd) || !isset($cmd['action']) || !isset($cmd['data']) || !is_array($cmd['data'])) {
                $this->SendDebug('ProcessWallboxQueue', 'Ungültiger Queue-Eintrag – skip', 0);
            } else {
                $action = (string)$cmd['action'];
                $data   = $cmd['data'];

                switch ($action) {
                    case 'charging': {
                        $sn = (string)($data['sn'] ?? '');
                        $on = (bool)($data['on'] ?? false);
                        if ($sn !== '') {
                            $ok = $this->SemsSetCharging($sn, $on);
                        }
                        break;
                    }

                    case 'setmode': {
                        $sn   = (string)($data['sn'] ?? '');
                        $type = (int)($data['type'] ?? 0);

                        $cp = $data['charge_power'] ?? null;
                        if ($cp === '' || $cp === false) {
                            $cp = null;
                        }
                        if ($cp !== null) {
                            $cp = (float)$cp;
                        }

                        if ($sn !== '') {
                            $ok = $this->SemsSetChargeMode($sn, $type, $cp);
                        }
                        break;
                    }

                    default:
                        $this->SendDebug('ProcessWallboxQueue', 'Unbekannte action: ' . $action, 0);
                        break;
                }
            }
        } catch (Exception $e) {
            $this->SendDebug('ProcessWallboxQueue', 'Exception: ' . $e->getMessage(), 0);
            $ok = false;
        }

        // Wichtig: wir wiederholen NICHT. Wir vertrauen auf Zustandsabgleich via FetchWallboxData.
        $pending = $this->GetWbPending();
        if (is_array($pending)) {
            $pending['sent'] = true;
            $pending['lastResult'] = [
                'time' => time(),
                'ok'   => $ok,
                'cmd'  => $cmd['ident'] ?? '',
                'action' => $cmd['action'] ?? ''
            ];
            $this->UpdateWbPending($pending);
        }

        $this->SendDebug('ProcessWallboxQueue', $ok ? 'SENT (HTTP ok)' : 'SENT (no HTTP ok / timeout / unknown) - no retry', 0);

        // Timer: wenn leer stop, sonst weiter
        $queueLeft = json_decode($this->GetBuffer('WallboxQueue'), true);
        if (!is_array($queueLeft) || count($queueLeft) === 0) {
            $this->SetTimerInterval('TimerWBQueue', 0);
            $this->SendDebug('ProcessWallboxQueue', 'Queue nun leer – Timer gestoppt.', 0);
        } else {
            $this->SetTimerInterval('TimerWBQueue', 1000);
        }
    }

    public function CalculateMaxPower()
    {
        if ($this->ReadPropertyBoolean("Entladen_Max")) {
            $entladenID = @$this->GetIDForIdent("MaxEntladen");
            if ($entladenID !== false) {
                $spannung = $this->ReadRegisterValue(47904, 0.1);
                $strom    = $this->ReadRegisterValue(47905, 0.1);
                if ($spannung !== null && $strom !== null) {
                    $leistung = (int)($spannung * $strom);
                    $this->SetValueIfChanged("MaxEntladen", $leistung);
                    $this->SendDebug("CalculateMaxPower", "Entladen Max: $spannung V * $strom A = $leistung W", 0);
                }
            }
        }

        if ($this->ReadPropertyBoolean("Laden_Max")) {
            $ladenID = @$this->GetIDForIdent("MaxLaden");
            if ($ladenID !== false) {
                $spannung = $this->ReadRegisterValue(47902, 0.1);
                $strom    = $this->ReadRegisterValue(47903, 0.1);
                if ($spannung !== null && $strom !== null) {
                    $leistung = (int)($spannung * $strom);
                    $this->SetValueIfChanged("MaxLaden", $leistung);
                    $this->SendDebug("CalculateMaxPower", "Laden Max: $spannung V * $strom A = $leistung W", 0);
                }
            }
        }
    }

    // ------------------------
    // Token (HA-Style)
    // ------------------------
    private function SemsGetToken(bool $forceRenew = false): ?array
    {
        $email    = $this->ReadPropertyString("WallboxUser");
        $password = $this->ReadPropertyString("WallboxPassword");

        if ($email === '' || $password === '') {
            $this->SendDebug("SemsGetToken", "User/Pass fehlt.", 0);
            return null;
        }

        // Cache: 55min
        $cached = json_decode($this->GetBuffer("SemsToken"), true);
        $ts     = (int)$this->GetBuffer("SemsTokenTs");
        $age    = time() - $ts;

        if (!$forceRenew && is_array($cached) && $ts > 0 && $age < 55 * 60) {
            return $cached;
        }

        $url = $this->SemsLoginUrl();

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            // wie HA: token header muss beim Login schon da sein
            "token: " . '{"version":"","client":"ios","language":"en"}',
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
        ];

        $body = json_encode([
            "account" => $email,
            "pwd"     => $password
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        [$http, $raw, $decoded] = $this->CurlJsonHttp($url, $headers, $body, 20);

        $this->SendDebug("SemsGetToken", json_encode([
            'httpCode' => $http,
            'response' => $decoded ?? $raw
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        if ($http !== 200 || !is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return null;
        }

        // TokenDict = data + api (wie HA)
        $token = $decoded['data'];
        if (isset($decoded['api'])) {
            $token['api'] = $decoded['api'];
        }

        $this->SetBuffer("SemsToken", json_encode($token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->SetBuffer("SemsTokenTs", (string)time());

        return $token;
    }

    // ------------------------
    // SemsPost (liefert [http, raw, decoded])
    // ------------------------
    private function SemsPost(string $url, array $payload, bool $renewToken = false, int $maxRetries = 2): ?array
    {
        if ($maxRetries <= 0) {
            $this->SendDebug("SemsPost", "MaxRetries erreicht für $url", 0);
            return null;
        }

        $token = $this->SemsGetToken($renewToken);
        if ($token === null) {
            $this->SendDebug("SemsPost", "Kein Token.", 0);
            return null;
        }

        // HA: base = token["api"] (endet meist auf /api/)
        $base = $token["api"] ?? "https://eu.semsportal.com/api/";
        if (!is_string($base) || $base === "") {
            $base = "https://eu.semsportal.com/api/";
        }

        // Falls URL nicht absolut ist: an base hängen
        if (stripos($url, "http://") !== 0 && stripos($url, "https://") !== 0) {
            $url = rtrim($base, "/") . "/" . ltrim($url, "/");
        }

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            "token: " . json_encode($token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        [$http, $raw, $decoded] = $this->CurlJsonHttp($url, $headers, $body, 20);

        $this->SendDebug("SemsPost", json_encode([
            'url'        => $url,
            'renewToken' => $renewToken,
            'payload'    => $payload,
            'httpCode'   => $http,
            'response'   => $decoded ?? $raw
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        // Netzwerk/HTTP-Problem -> Token erneuern und retry
        if ($http === 0 || $raw === false || $raw === '') {
            return $this->SemsPost($url, $payload, true, $maxRetries - 1);
        }

        // Wenn SEMS z.B. "code" != 0 liefert, kann es Token sein -> retry
        if (is_array($decoded) && isset($decoded['code']) && !($decoded['code'] === 0 || $decoded['code'] === "0")) {
            return $this->SemsPost($url, $payload, true, $maxRetries - 1);
        }

        // Wir geben IMMER array zurück, weil wir für Control nur httpCode brauchen
        return [
            'httpCode' => $http,
            'raw'      => $raw,
            'decoded'  => $decoded
        ];
    }

    // ------------------------
    // ZIP-Style API Funktionen
    // ------------------------
    private function SemsGetWallboxStatus(string $sn): ?array
    {
        $url = $this->SemsWallboxUrl();
        $resp = $this->SemsPost($url, ["sn" => $sn], false, 2);
        if (!is_array($resp)) {
            return null;
        }

        $decoded = $resp['decoded'] ?? null;
        if (!is_array($decoded) || !isset($decoded['data']) || $decoded['data'] === null) {
            $this->SendDebug("SemsGetWallboxStatus", "Keine data: " . json_encode($decoded ?? $resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            return null;
        }

        return is_array($decoded['data']) ? $decoded['data'] : null;
    }

    private function SemsSetCharging(string $sn, bool $on): bool
    {
        $url = $this->SemsChargingUrl();
        $payload = [
            "sn"     => $sn,
            "status" => $on ? "1" : "0"
        ];

        // Für Control ruhig länger warten (HCA G1 reagiert manchmal zäh)
        $resp = $this->SemsPost($url, $payload, false, 2 /*retries*/);
        $http = is_array($resp) ? (int)($resp['httpCode'] ?? 0) : 0;

        $decoded = (is_array($resp) ? ($resp['decoded'] ?? null) : null);
        $code = (is_array($decoded) && array_key_exists('code', $decoded)) ? $decoded['code'] : null;
        $msg  = (is_array($decoded) && array_key_exists('msg',  $decoded)) ? $decoded['msg']  : null;
        $data = (is_array($decoded) && array_key_exists('data', $decoded)) ? $decoded['data'] : null;

        // Für Diagnose: wir loggen alles – ok bleibt erstmal HTTP 200 wie im Referenzprojekt
        $ok = ($http === 200);

        $this->SendDebug("SemsSetCharging", json_encode([
            'sn'      => $sn,
            'status'  => $payload['status'],
            'http'    => $http,
            'ok_http' => $ok,
            'code'    => $code,
            'msg'     => $msg,
            'data'    => $data,
            'raw'     => $resp['raw'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        return $ok;
    }

    private function SemsSetChargeMode(string $sn, int $type, ?float $chargePowerKw = null): bool
    {
        $url = $this->SemsSetModeUrl();
        $payload = [
            "sn"   => $sn,
            "type" => $type
        ];
        if ($chargePowerKw !== null) {
            $payload["charge_power"] = $chargePowerKw;
        }

        $resp = $this->SemsPost($url, $payload, false, 2);
        $http = is_array($resp) ? (int)($resp['httpCode'] ?? 0) : 0;

        // HA-Style: Erfolg = HTTP 200
        $ok = ($http === 200);

        $this->SendDebug("SemsSetChargeMode", json_encode([
            'sn'           => $sn,
            'type'         => $type,
            'charge_power' => $chargePowerKw,
            'http'         => $http,
            'ok'           => $ok,
            'resp'         => $resp['decoded'] ?? $resp['raw'] ?? $resp
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        return $ok;
    }

    // ------------------------
    // SEMS Endpoints
    // ------------------------
    private function SemsLoginUrl(): string      { return "https://eu.semsportal.com/api/v2/Common/CrossLogin"; }
    private function SemsWallboxUrl(): string    { return "https://eu.semsportal.com/api/v3/EvCharger/GetCurrentChargeinfo"; }
    private function SemsSetModeUrl(): string    { return "https://eu.semsportal.com/api/v3/EvCharger/SetChargeMode"; }
    private function SemsChargingUrl(): string   { return "https://eu.semsportal.com/api/v3/EvCharger/Charging"; }

    private function CurlJsonHttp(string $url, array $headers, string $body, int $timeout = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => ''
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) $decoded = $tmp;
        }

        // Body maskieren (Passwort)
        $bodyForLog = $body;
        $tmpBody = json_decode($body, true);
        if (is_array($tmpBody) && isset($tmpBody['pwd'])) {
            $tmpBody['pwd'] = '***';
            $bodyForLog = json_encode($tmpBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->SendDebug("CurlJsonHttp", json_encode([
            'url'      => $url,
            'httpCode' => $http,
            'curlErr'  => $err,
            'body'     => $bodyForLog,
            'response' => $decoded ?? $raw
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        return [$http, $raw, $decoded];
    }

    private function IsChargingFromView(array $view): ?bool
    {
        // Liefert true/false wenn erkennbar, sonst null (unbekannt)

        // workstate kann numerisch ODER String sein
        if (isset($view['workstate'])) {
            $ws = $view['workstate'];

            // numerisch?
            if (is_int($ws) || (is_string($ws) && ctype_digit($ws))) {
                $wsInt = (int)$ws;
                // nach deinem Mapping: 2 = läuft
                return ($wsInt === 2);
            }

            // String-Codes (Beispiele aus SEMS: "...Stat01" waiting, "...Stat02" charging, "...Stat03" ending)
            if (is_string($ws)) {
                if (stripos($ws, 'Stat02') !== false) return true;
                if (stripos($ws, 'Stat01') !== false) return false;
                if (stripos($ws, 'Stat03') !== false) return false;
                // falls andere Codes kommen: unbekannt
            }
        }

        // Fallback: manchmal gibt es ein Feld, das Charging direkt ausdrückt (nicht garantiert)
        if (isset($view['status']) && is_string($view['status'])) {
            if (stripos($view['status'], 'charging') !== false) return true;
            if (stripos($view['status'], 'stop') !== false) return false;
        }

        return null;
    }

    private function SetWbPending(string $ident, $expected, int $timeoutSec = 300): void
    {
        $this->SetBuffer('WB_Pending', json_encode([
            'ident'       => $ident,
            'expected'    => $expected,
            'since'       => time(),
            'timeout'     => $timeoutSec,
            'sent'        => false,      // wird nach dem 1× Senden gesetzt
            'lastResult'  => null         // rein fürs Debug
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Fehler beim neuen Command löschen
        if (@$this->GetIDForIdent('WB_CommandError') !== false) {
            $this->SetValueIfChanged('WB_CommandError', false);
        }
        if (@$this->GetIDForIdent('WB_CommandErrorText') !== false) {
            $this->SetValueIfChanged('WB_CommandErrorText', '');
        }
    }

    private function GetWbPending(): ?array
    {
        $p = json_decode($this->GetBuffer('WB_Pending'), true);
        return is_array($p) ? $p : null;
    }

    private function UpdateWbPending(array $pending): void
    {
        $this->SetBuffer('WB_Pending', json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ClearWbPending(): void
    {
        $this->SetBuffer('WB_Pending', '');
    }

    private function SetWbCommandError(string $text): void
    {
        if (@$this->GetIDForIdent('WB_CommandError') !== false) {
            $this->SetValueIfChanged('WB_CommandError', true);
        }
        if (@$this->GetIDForIdent('WB_CommandErrorText') !== false) {
            $this->SetValueIfChanged('WB_CommandErrorText', $text);
        }
        $this->SendDebug('WB_CommandError', $text, 0);
    }









    private function ReadRegisterValue(int $address, float $scale = 1.0)
    {
        $quantity = 1;

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

        $data  = unpack("n*", substr($response, 2));
        $value = $data[1];

        if ($value & 0x8000) {
            $value = -((~$value & 0xFFFF) + 1);
        }

        return $value * $scale;
    }

    public function GetConfigurationForm()
    {
        $all = $this->GetRegisters();

        $selected = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedMap = [];
        foreach ($selected as $sr) {
            if (is_string($sr)) {
                $tmp = json_decode($sr, true);
                if (is_array($tmp)) {
                    $sr = $tmp;
                }
            }
            if (is_array($sr)) {
                if (isset($sr['address']) && is_string($sr['address']) && str_starts_with(trim($sr['address']), '{')) {
                    $tmp = json_decode($sr['address'], true);
                    if (is_array($tmp) && isset($tmp['address'])) {
                        $sr['address'] = $tmp['address'];
                    }
                }

                if (isset($sr['addr'])) {
                    $selectedMap[(string)$sr['addr']] = true;
                } elseif (isset($sr['address'])) {
                    $selectedMap[(string)$sr['address']] = true;
                }
            }
        }

        $values = array_map(function ($r) use ($selectedMap) {
            $addr = (string)$r['address'];
            return [
                "selected"        => isset($selectedMap[$addr]),
                "addr"            => $addr,      
                "address_display" => $addr,     
                "name"            => $r['name'],
            ];
        }, $all);

        return json_encode([
            "elements" => [
                [
                    "type"     => "List",
                    "name"     => "SelectedRegisters",
                    "caption"  => "Register auswählen",
                    "rowCount" => 15,
                    "add"      => false,
                    "delete"   => false,
                    "columns"  => [
                        [ "caption" => "",          "name" => "addr",             "width" => "0px",  "visible" => false, "edit" => [ "type" => "ValidationTextBox" ] ],
                        [ "caption" => "Auswählen", "name" => "selected",         "width" => "120px","edit" => [ "type" => "CheckBox" ] ],
                        [ "caption" => "Adresse",   "name" => "address_display",  "width" => "110px" ],
                        [ "caption" => "Name",      "name" => "name",             "width" => "auto" ],
                    ],
                    "values" => $values
                ],
                [
                    "type"    => "IntervalBox",
                    "name"    => "PollIntervalWR",
                    "caption" => "Sekunden",
                    "suffix"  => "s"
                ],
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "SEMS-API-Konfiguration (nur für Wallbox der 1. Generation erforderlich)",
                    "items"   => [
                        [ "type" => "ValidationTextBox", "name" => "WallboxUser",       "caption" => "Benutzername" ],
                        [ "type" => "ValidationTextBox", "name" => "WallboxPassword",   "caption" => "Passwort" ],
                        [ "type" => "ValidationTextBox", "name" => "WallboxSerial",     "caption" => "Seriennummer Wallbox" ],
                        [ "type" => "IntervalBox",       "name" => "PollIntervalWB",    "caption" => "Sekunden", "suffix" => "s" ],
                        [ "type" => "NumberSpinner",     "name" => "ChargePowerOffset", "caption" => "Soll-Ladeleistung erhöhen", "suffix" => "W" ]
                    ]
                ],
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "Zusätzliche Werte berechnen",
                    "items"   => [
                        [ "type" => "CheckBox", "name" => "Entladen_Max", "caption" => "Maximal mögliche Leistung für das Entladen des Speichers berechnen" ],
                        [ "type" => "CheckBox", "name" => "Laden_Max",    "caption" => "Maximal mögliche Leistung für das Laden des Speichers berechnen" ],
                    ]
                ],
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

        private function SetValueIfChanged(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false) {
            return;
        }

        $var = IPS_GetVariable($vid);
        switch ($var['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $new = (bool)$value;
                break;
            case VARIABLETYPE_INTEGER:
                $new = (int)$value;
                break;
            case VARIABLETYPE_FLOAT:
                $new = (float)$value;
                break;
            case VARIABLETYPE_STRING:
            default:
                $new = (string)$value;
                break;
        }

        if (GetValue($vid) !== $new) {
            SetValue($vid, $new);
        }
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
                return null;
        }
    }

    private function CreateProfile()
    {
        if (!IPS_VariableProfileExists('Goodwe.EMSPowerMode')){
            IPS_CreateVariableProfile('Goodwe.EMSPowerMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '0',  'Stoped',           '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '1',  'Auto',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '2',  'Charge-PV',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '3',  'Discharge+PV',     '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '4',  'Import-AC',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '5',  'Export-AC',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '6',  'Conserve',         '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '7',  'Off-Grid',         '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '8',  'Battery-Standby',  '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '9',  'Buy-Power',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '10', 'Sell-Power',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '11', 'Charge-BAT',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '12', 'Discharge-BAT',    '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.EMSPowerMode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_State')){
            IPS_CreateVariableProfile('Goodwe.WB_State', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.WB_State', '0', 'nicht gesteckt',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_State', '1', 'gesteckt',            '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_State', '2', 'gesteckt und lädt',   '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_State', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_Mode')){
            IPS_CreateVariableProfile('Goodwe.WB_Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Mode', '0', 'Schnell',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Mode', '1', 'PV-Priorität',   '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Mode', '2', 'PV  & Batterie', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_Mode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_Power_W')){
            IPS_CreateVariableProfile('Goodwe.WB_Power_W', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Goodwe.WB_Power_W', 4200, 9700, 100); 
            IPS_SetVariableProfileDigits('Goodwe.WB_Power_W', 0);               
            IPS_SetVariableProfileText('Goodwe.WB_Power_W', "", " W");          
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WB_Power_W', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Mode')){
            IPS_CreateVariableProfile('Goodwe.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '0', 'keine Batterie',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '1', 'Standby',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '2', 'entlädt',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '3', 'lädt',                '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '4', 'warten auf Laden',    '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '5', 'warten auf Entladen', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Mode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WB_Workstate')){
            IPS_CreateVariableProfile('Goodwe.WB_Workstate', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '0', 'Aus',                   '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '1', 'Ladevorgang startet',   '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '2', 'Ladevorgang läuft',     '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.WB_Workstate', '3', 'Ladevorgang endet',     '', -1);
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
            ["address" => 36019, "name" => "SM - Leistung PH1",       "type" => "S32", "unit" => "W",  "scale" => 1,   "pos" => 15],
            ["address" => 36021, "name" => "SM - Leistung PH2",       "type" => "S32", "unit" => "W",  "scale" => 1,   "pos" => 20],
            ["address" => 36023, "name" => "SM - Leistung PH3",       "type" => "S32", "unit" => "W",  "scale" => 1,   "pos" => 30],
            ["address" => 36025, "name" => "SM - Leistung gesamt",    "type" => "S32", "unit" => "W",  "scale" => 1,   "pos" => 40],
            // Batterie
            ["address" => 35182, "name" => "BAT - Leistung",          "type" => "S32", "unit" => "W",  "scale" => 1,   "pos" => 50],
            ["address" => 35184, "name" => "BAT - Mode",              "type" => "U16", "unit" => "mode","scale" => 1,  "pos" => 60],
            ["address" => 35206, "name" => "BAT - Laden",             "type" => "U32", "unit" => "kWh","scale" => 0.1, "pos" => 70],
            ["address" => 35209, "name" => "BAT - Entladen",          "type" => "U32", "unit" => "kWh","scale" => 0.1, "pos" => 80],
            ["address" => 37003, "name" => "BAT - Temperatur",        "type" => "U16", "unit" => "°C", "scale" => 0.1, "pos" => 90],
            ["address" => 45356, "name" => "BAT - Min SOC online",    "type" => "U16", "unit" => "%",  "scale" => 1,   "pos" => 100],
            ["address" => 45358, "name" => "BAT - Min SOC offline",   "type" => "U16", "unit" => "%",  "scale" => 1,   "pos" => 110],
            ["address" => 47511, "name" => "BAT - EMSPowerMode",      "type" => "U16", "unit" => "ems","scale" => 1,   "pos" => 120],
            ["address" => 47512, "name" => "BAT - EMSPowerSet",       "type" => "U16", "unit" => "watt_ems","scale" => 1,"pos" => 130],
            ["address" => 47902, "name" => "BAT - Laden Spannung max","type" => "S16", "unit" => "V",  "scale" => 0.1, "pos" => 141],
            ["address" => 47903, "name" => "BAT - Laden Strom max",   "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 140],
            ["address" => 47904, "name" => "BAT - Entladen Spannung max","type" => "S16","unit" => "V","scale" => 0.1, "pos" => 151],
            ["address" => 47905, "name" => "BAT - Entladen Strom max","type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 150],
            ["address" => 47906, "name" => "BAT - Spannung",          "type" => "S16", "unit" => "V",  "scale" => 0.1, "pos" => 160],
            ["address" => 47907, "name" => "BAT - Strom",             "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 170],
            ["address" => 47908, "name" => "BAT - SOC",               "type" => "S16", "unit" => "%",  "scale" => 1,   "pos" => 180],
            ["address" => 47909, "name" => "BAT - SOH",               "type" => "S16", "unit" => "%",  "scale" => 1,   "pos" => 190],
            // Wechselrichter
            ["address" => 35103, "name" => "WR - Spannung String 1",  "type" => "U16", "unit" => "V",  "scale" => 0.1, "pos" => 200],
            ["address" => 35104, "name" => "WR - Strom String 1",     "type" => "U16", "unit" => "A",  "scale" => 0.1, "pos" => 210],
            ["address" => 35105, "name" => "WR - Leistung String 1",  "type" => "U32", "unit" => "W",  "scale" => 1,   "pos" => 220],
            ["address" => 35107, "name" => "WR - Spannung String 2",  "type" => "U16", "unit" => "V",  "scale" => 0.1, "pos" => 230],
            ["address" => 35108, "name" => "WR - Strom String 2",     "type" => "U16", "unit" => "A",  "scale" => 0.1, "pos" => 240],
            ["address" => 35109, "name" => "WR - Leistung String 2",  "type" => "U32", "unit" => "W",  "scale" => 1,   "pos" => 250],
            ["address" => 35111, "name" => "WR - Spannung String 3",  "type" => "U16", "unit" => "V",  "scale" => 0.1, "pos" => 251],
            ["address" => 35112, "name" => "WR - Strom String 3",     "type" => "U16", "unit" => "A",  "scale" => 0.1, "pos" => 252],
            ["address" => 35113, "name" => "WR - Leistung String 3",  "type" => "U32", "unit" => "W",  "scale" => 1,   "pos" => 253],
            ["address" => 35115, "name" => "WR - Spannung String 4",  "type" => "U16", "unit" => "V",  "scale" => 0.1, "pos" => 254],
            ["address" => 35116, "name" => "WR - Strom String 4",     "type" => "U16", "unit" => "A",  "scale" => 0.1, "pos" => 255],
            ["address" => 35117, "name" => "WR - Leistung String 4",  "type" => "U32", "unit" => "W",  "scale" => 1,   "pos" => 256],
            ["address" => 35174, "name" => "WR - Temperatur",         "type" => "S16", "unit" => "°C", "scale" => 0.1, "pos" => 260],
            ["address" => 35191, "name" => "WR - Erzeugung Gesamt",   "type" => "U32", "unit" => "kWh","scale" => 0.1, "pos" => 270],
            ["address" => 35193, "name" => "WR - Erzeugung Tag",      "type" => "U32", "unit" => "kWh","scale" => 0.1, "pos" => 280],
            ["address" => 35301, "name" => "WR - Leistung Gesamt",    "type" => "U32", "unit" => "W",  "scale" => 1,   "pos" => 290],
            ["address" => 35337, "name" => "WR - P MPPT1",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 300],
            ["address" => 35338, "name" => "WR - P MPPT2",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 310],
            ["address" => 35339, "name" => "WR - P MPPT3",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 320],
            ["address" => 35340, "name" => "WR - P MPPT4",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 321],
            ["address" => 35341, "name" => "WR - P MPPT5",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 322],
            ["address" => 35342, "name" => "WR - P MPPT6",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 323],
            ["address" => 35343, "name" => "WR - P MPPT7",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 324],
            ["address" => 35344, "name" => "WR - P MPPT8",            "type" => "S16", "unit" => "W",  "scale" => 1,   "pos" => 325],
            ["address" => 35345, "name" => "WR - I MPPT1",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 330],
            ["address" => 35346, "name" => "WR - I MPPT2",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 340],
            ["address" => 35347, "name" => "WR - I MPPT3",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 350],
            ["address" => 35348, "name" => "WR - I MPPT4",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 351],
            ["address" => 35349, "name" => "WR - I MPPT5",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 352],
            ["address" => 35350, "name" => "WR - I MPPT6",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 353],
            ["address" => 35351, "name" => "WR - I MPPT7",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 354],
            ["address" => 35352, "name" => "WR - I MPPT8",            "type" => "S16", "unit" => "A",  "scale" => 0.1, "pos" => 355],
            ["address" => 35365, "name" => "WR - Isolationswiderstand","type" => "U16","unit" => "KΩ","scale" => 1,   "pos" => 370],
        ];
    }
}