<?php

class Goodwe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString("SelectedRegisters", "[]");

        $this->RegisterPropertyInteger("PollIntervalWR", 10);

        $this->RegisterPropertyBoolean("Entladen_Max", false);
        $this->RegisterPropertyBoolean("Laden_Max", false);
        $this->RegisterPropertyBoolean("Entladen_Max_2", false);
        $this->RegisterPropertyBoolean("Laden_Max_2", false);

        $this->RegisterTimer('TimerWR', 0, 'Goodwe_FetchInverterData($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        // Modbus-Gateway
        return '{"type": "connect", "moduleIDs": ["{A5F663AB-C400-4FE5-B207-4D67CC030564}"]}';
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $rawSelected = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($rawSelected)) {
            $rawSelected = [];
        }

        $selectedMap = [];
        foreach ($rawSelected as $r) {
            if (is_string($r)) {
                $tmp = json_decode($r, true);
                if (is_array($tmp)) {
                    $r = $tmp;
                } else {
                    continue;
                }
            }

            if (!is_array($r)) {
                continue;
            }

            $addr = null;
            if (isset($r['addr'])) {
                $addr = (string)$r['addr'];
            } elseif (isset($r['address'])) {
                $addr = (string)$r['address'];
            }

            if ($addr === null || $addr === '') {
                continue;
            }

            $selectedMap[$addr] = (bool)($r['selected'] ?? false);
        }

        $normalized = [];
        foreach ($this->GetRegisters() as $r) {
            $addr = (string)$r['address'];
            $normalized[] = [
                'addr'     => $addr,
                'selected' => $selectedMap[$addr] ?? false
            ];
        }

        $currentJson = json_encode($rawSelected);
        $normalizedJson = json_encode($normalized);

        if ($currentJson !== $normalizedJson) {
            IPS_SetProperty($this->InstanceID, "SelectedRegisters", $normalizedJson);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->CreateProfile();

        $this->SetTimerInterval('TimerWR', $this->ReadPropertyInteger('PollIntervalWR') * 1000);

        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $registerCurrentIdents = [];
        $selectedMap = [];

        if (is_array($selectedRegisters)) {
            foreach ($selectedRegisters as $r) {
                if (!is_array($r)) {
                    continue;
                }

                $addr = isset($r['addr']) ? (string)$r['addr'] : null;
                if ($addr === null || $addr === '') {
                    continue;
                }

                if (!empty($r['selected'])) {
                    $selectedMap[$addr] = true;
                }
            }
        }

        foreach ($this->GetRegisters() as $r) {
            $addrKey = (string)$r['address'];

            if (!isset($selectedMap[$addrKey])) {
                continue;
            }

            foreach (['address', 'name', 'type', 'unit', 'scale', 'pos'] as $need) {
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

        foreach (['Addr45358', 'Addr45356', 'Addr47511', 'Addr47512', 'Addr45381', 'Addr45383'] as $writeIdent) {
            if (@$this->GetIDForIdent($writeIdent)) {
                $this->EnableAction($writeIdent);
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if (strpos($obj['ObjectIdent'], 'Addr') === 0 && !in_array($obj['ObjectIdent'], $registerCurrentIdents)) {
                $this->UnregisterVariable($obj['ObjectIdent']);
                $this->SendDebug("ApplyChanges", "Register-Variable entfernt: {$obj['ObjectIdent']}", 0);
            }
        }

        // Berechnung für Batterie 1
        if ($this->ReadPropertyBoolean("Entladen_Max")) {
            if (!@$this->GetIDForIdent("MaxEntladen")) {
                $this->RegisterVariableInteger("MaxEntladen", "BAT - Entladen Leistung max", "Goodwe.Watt", 225);
            }
        } else {
            if (@$this->GetIDForIdent("MaxEntladen") !== false) {
                $this->UnregisterVariable("MaxEntladen");
                $this->SendDebug("ApplyChanges", "MaxEntladen-Variable entfernt, da Entladen_Max deaktiviert.", 0);
            }
        }

        if ($this->ReadPropertyBoolean("Laden_Max")) {
            if (!@$this->GetIDForIdent("MaxLaden")) {
                $this->RegisterVariableInteger("MaxLaden", "BAT - Laden Leistung max", "Goodwe.Watt", 205);
            }
        } else {
            if (@$this->GetIDForIdent("MaxLaden") !== false) {
                $this->UnregisterVariable("MaxLaden");
                $this->SendDebug("ApplyChanges", "MaxLaden-Variable entfernt, da Laden_Max deaktiviert.", 0);
            }
        }

        // Berechnung für Batterie 2
        if ($this->ReadPropertyBoolean("Entladen_Max_2")) {
            if (!@$this->GetIDForIdent("MaxEntladen2")) {
                $this->RegisterVariableInteger("MaxEntladen2", "BAT2 - Entladen Leistung max", "Goodwe.Watt", 425);
            }
        } else {
            if (@$this->GetIDForIdent("MaxEntladen2") !== false) {
                $this->UnregisterVariable("MaxEntladen2");
                $this->SendDebug("ApplyChanges", "MaxEntladen-Bat2-Variable entfernt, da Entladen_Max deaktiviert.", 0);
            }
        }

        if ($this->ReadPropertyBoolean("Laden_Max_2")) {
            if (!@$this->GetIDForIdent("MaxLaden2")) {
                $this->RegisterVariableInteger("MaxLaden2", "BAT2 - Laden Leistung max", "Goodwe.Watt", 405);
            }
        } else {
            if (@$this->GetIDForIdent("MaxLaden2") !== false) {
                $this->UnregisterVariable("MaxLaden2");
                $this->SendDebug("ApplyChanges", "MaxLaden-Bat2-Variable entfernt, da Laden_Max deaktiviert.", 0);
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->SendDebug("RequestAction", "Aktion gestartet für Ident: $Ident, Wert: " . json_encode($Value), 0);

        if (strpos($Ident, 'Addr') === 0) {
            $address = (int)substr($Ident, 4);

            if ($this->WriteRegister($address, (int)$Value)) {
                $this->SetValueIfChanged($Ident, (int)$Value);
                $this->SendDebug("RequestAction", "Register $address erfolgreich geschrieben: $Value", 0);
            } else {
                $this->SendDebug("RequestAction", "Fehler beim Schreiben von Register $address: $Value", 0);
            }
            return;
        }

        throw new Exception("Ungültiger Ident: $Ident");
    }

    public function FetchInverterData()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("FetchInverterData", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }

        $selectedMap = [];
        foreach ($selectedRegisters as $r) {
            if (!is_array($r)) {
                continue;
            }

            $addr = isset($r['addr']) ? (string)$r['addr'] : null;
            if ($addr === null || $addr === '') {
                continue;
            }

            if (!empty($r['selected'])) {
                $selectedMap[$addr] = true;
            }
        }

        if (count($selectedMap) === 0) {
            $this->SendDebug("FetchInverterData", "Keine Register ausgewählt.", 0);
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

        $values = [];

        foreach ($this->GetRegisters() as $r) {
            $addrKey = (string)$r['address'];

            if (!isset($selectedMap[$addrKey])) {
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
                if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == IS_DELETING)
                    break;
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

                $this->SetValueIfChanged($ident, $finalValue);
                $values[$ident] = $finalValue;

            } catch (Exception $e) {
                $this->SendDebug("FetchInverterData", "Fehler Parent-Kommunikation: " . $e->getMessage(), 0);
                $this->LogMessage("Goodwe", "Fehler Parent: " . $e->getMessage());
            }
        }

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
            "Function" => 6,
            "Address"  => $address,
            "Quantity" => 1,
            "Data"     => bin2hex(pack("n", $value)),
        ];

        $response = $this->SendDataToParent(json_encode($data));

        if ($response === false) {
            $this->SendDebug("WriteRegister", "Fehler beim Schreiben in Register $address", 0);
            return false;
        }

        $this->SendDebug("WriteRegister", "Erfolgreich in Register $address geschrieben: $value", 0);
        return true;
    }

    public function CalculateMaxPower()
    {
        if ($this->ReadPropertyBoolean("Entladen_Max")) {
            if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == IS_DELETING)
                break;
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
            if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == IS_DELETING)
                break;
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

        if ($this->ReadPropertyBoolean("Entladen_Max_2")) {
            if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == IS_DELETING)
                break;
            $entladenID = @$this->GetIDForIdent("MaxEntladen2");
            if ($entladenID !== false) {
                $spannung = $this->ReadRegisterValue(47922, 0.1);
                $strom    = $this->ReadRegisterValue(47923, 0.1);
                if ($spannung !== null && $strom !== null) {
                    $leistung = (int)($spannung * $strom);
                    $this->SetValueIfChanged("MaxEntladen2", $leistung);
                    $this->SendDebug("CalculateMaxPower_BAT2", "Entladen Max: $spannung V * $strom A = $leistung W", 0);
                }
            }
        }

        if ($this->ReadPropertyBoolean("Laden_Max_2")) {
            if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] == IS_DELETING)
                break;
            $ladenID = @$this->GetIDForIdent("MaxLaden2");
            if ($ladenID !== false) {
                $spannung = $this->ReadRegisterValue(47920, 0.1);
                $strom    = $this->ReadRegisterValue(47921, 0.1);
                if ($spannung !== null && $strom !== null) {
                    $leistung = (int)($spannung * $strom);
                    $this->SetValueIfChanged("MaxLaden2", $leistung);
                    $this->SendDebug("CalculateMaxPower_BAT2", "Laden Max: $spannung V * $strom A = $leistung W", 0);
                }
            }
        }
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

    public function GetConfigurationForm(): string
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

            if (!is_array($sr)) {
                continue;
            }

            $addr = null;
            if (isset($sr['addr'])) {
                $addr = (string)$sr['addr'];
            } elseif (isset($sr['address'])) {
                $addr = (string)$sr['address'];
            }

            if ($addr === null || $addr === '') {
                continue;
            }

            $selectedMap[$addr] = (bool)($sr['selected'] ?? false);
        }

        $values = array_map(function ($r) use ($selectedMap) {
            $addr = (string)$r['address'];
            return [
                "addr"            => $addr,
                "selected"        => $selectedMap[$addr] ?? false,
                "address_display" => $addr,
                "name"            => $r['name'],
            ];
        }, $all);

        return json_encode([
            "elements" => [
                [
                    "type"                        => "List",
                    "name"                        => "SelectedRegisters",
                    "caption"                     => "Register auswählen",
                    "rowCount"                    => 15,
                    "add"                         => false,
                    "delete"                      => false,
                    "loadValuesFromConfiguration" => false,
                    "columns"                     => [
                        [
                            "caption" => "",
                            "name"    => "addr",
                            "width"   => "0px",
                            "visible" => false,
                            "save"    => true
                        ],
                        [
                            "caption" => "Auswählen",
                            "name"    => "selected",
                            "width"   => "120px",
                            "save"    => true,
                            "edit"    => ["type" => "CheckBox"]
                        ],
                        [
                            "caption" => "Adresse",
                            "name"    => "address_display",
                            "width"   => "110px"
                        ],
                        [
                            "caption" => "Name",
                            "name"    => "name",
                            "width"   => "auto"
                        ]
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
                    "caption" => "Zusätzliche Werte berechnen",
                    "items"   => [
                        ["type" => "CheckBox", "name" => "Entladen_Max",   "caption" => "Maximal mögliche Leistung für das Entladen des Speichers 1 berechnen"],
                        ["type" => "CheckBox", "name" => "Laden_Max",      "caption" => "Maximal mögliche Leistung für das Laden des Speichers 1 berechnen"],
                        ["type" => "CheckBox", "name" => "Entladen_Max_2", "caption" => "Maximal mögliche Leistung für das Entladen des Speichers 2 berechnen"],
                        ["type" => "CheckBox", "name" => "Laden_Max_2",    "caption" => "Maximal mögliche Leistung für das Laden des Speichers 2 berechnen"]
                    ]
                ]
            ],
            "actions" => [
                [
                    "type"    => "Button",
                    "caption" => "Werte lesen",
                    "onClick" => 'Goodwe_FetchInverterData($id);'
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

    private function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        $vid = @$this->GetIDForIdent($Ident);
        if ($vid === false) {
            return;
        }

        $var = IPS_GetVariable($vid);

        switch ($var['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $new = (bool)$Value;
                $old = (bool)GetValue($vid);
                break;

            case VARIABLETYPE_INTEGER:
                $new = (int)$Value;
                $old = (int)GetValue($vid);
                break;

            case VARIABLETYPE_FLOAT:
                $new = (float)$Value;
                $old = (float)GetValue($vid);
                break;

            case VARIABLETYPE_STRING:
            default:
                $new = (string)$Value;
                $old = (string)GetValue($vid);
                break;
        }

        if ($old !== $new) {
            $this->SetValue($Ident, $new);
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
            case "String":
                return ["profile" => "~String", "type" => VARIABLETYPE_STRING];
            default:
                return null;
        }
    }

    private function CreateProfile()
    {
        if (!IPS_VariableProfileExists('Goodwe.EMSPowerMode')) {
            IPS_CreateVariableProfile('Goodwe.EMSPowerMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '0',  'Stoped',          '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '1',  'Auto',            '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '2',  'Charge-PV',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '3',  'Discharge+PV',    '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '4',  'Import-AC',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '5',  'Export-AC',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '6',  'Conserve',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '7',  'Off-Grid',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '8',  'Battery-Standby', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '9',  'Buy-Power',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '10', 'Sell-Power',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '11', 'Charge-BAT',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '12', 'Discharge-BAT',   '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.EMSPowerMode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Mode')) {
            IPS_CreateVariableProfile('Goodwe.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '0', 'keine Batterie',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '1', 'Standby',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '2', 'entlädt',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '3', 'lädt',                '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '4', 'warten auf Laden',    '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '5', 'warten auf Entladen', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Mode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Watt')) {
            IPS_CreateVariableProfile('Goodwe.Watt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.Watt', '', ' W');
            IPS_SetVariableProfileDigits('Goodwe.Watt', 0);
            IPS_SetVariableProfileValues('Goodwe.Watt', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Watt', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WattEMS')) {
            IPS_CreateVariableProfile('Goodwe.WattEMS', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.WattEMS', '', ' W');
            IPS_SetVariableProfileDigits('Goodwe.WattEMS', 0);
            IPS_SetVariableProfileValues('Goodwe.WattEMS', 0, 10000, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WattEMS', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Percent')) {
            IPS_CreateVariableProfile('Goodwe.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.Percent', '', ' %');
            IPS_SetVariableProfileDigits('Goodwe.Percent', 0);
            IPS_SetVariableProfileValues('Goodwe.Percent', 0, 100, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Percent', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.kOhm')) {
            IPS_CreateVariableProfile('Goodwe.kOhm', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.kOhm', '', ' KΩ');
            IPS_SetVariableProfileDigits('Goodwe.kOhm', 0);
            IPS_SetVariableProfileValues('Goodwe.kOhm', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.kOhm', 0);
        }
    }

    private function GetRegisters()
    {
        return [
            // Smartmeter
            ["address" => 36019, "name" => "SM - Leistung PH1",            "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 15],
            ["address" => 36021, "name" => "SM - Leistung PH2",            "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 20],
            ["address" => 36023, "name" => "SM - Leistung PH3",            "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 30],
            ["address" => 36025, "name" => "SM - Leistung gesamt",         "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 40],

            // Batterie 1
            ["address" => 35182, "name" => "BAT - Leistung",               "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 100],
            ["address" => 35184, "name" => "BAT - Mode",                   "type" => "U16", "unit" => "mode",     "scale" => 1,   "pos" => 110],
            ["address" => 35206, "name" => "BAT - Laden",                  "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 120],
            ["address" => 35209, "name" => "BAT - Entladen",               "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 130],
            ["address" => 37003, "name" => "BAT - Temperatur",             "type" => "U16", "unit" => "°C",       "scale" => 0.1, "pos" => 140],
            ["address" => 45356, "name" => "BAT - Min SOC online",         "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 150],
            ["address" => 45358, "name" => "BAT - Min SOC offline",        "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 160],
            ["address" => 47511, "name" => "BAT - EMSPowerMode",           "type" => "U16", "unit" => "ems",      "scale" => 1,   "pos" => 170],
            ["address" => 47512, "name" => "BAT - EMSPowerSet",            "type" => "U16", "unit" => "watt_ems", "scale" => 1,   "pos" => 180],
            ["address" => 47902, "name" => "BAT - Laden Spannung max",     "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 190],
            ["address" => 47903, "name" => "BAT - Laden Strom max",        "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 200],
            ["address" => 47904, "name" => "BAT - Entladen Spannung max",  "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 210],
            ["address" => 47905, "name" => "BAT - Entladen Strom max",     "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 220],
            ["address" => 47906, "name" => "BAT - Spannung",               "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 230],
            ["address" => 47907, "name" => "BAT - Strom",                  "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 240],
            ["address" => 47908, "name" => "BAT - SOC",                    "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 250],
            ["address" => 47909, "name" => "BAT - SOH",                    "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 260],

            // Batterie 2
            ["address" => 35264, "name" => "BAT2 - Leistung",              "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 300],
            ["address" => 35266, "name" => "BAT2 - Mode",                  "type" => "U16", "unit" => "mode",     "scale" => 1,   "pos" => 310],
            ["address" => 39001, "name" => "BAT2 - Temperatur",            "type" => "U16", "unit" => "°C",       "scale" => 0.1, "pos" => 340],
            ["address" => 45381, "name" => "BAT2 - Min SOC online",        "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 350],
            ["address" => 45383, "name" => "BAT2 - Min SOC offline",       "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 360],
            ["address" => 47920, "name" => "BAT2 - Laden Spannung max",    "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 390],
            ["address" => 47921, "name" => "BAT2 - Laden Strom max",       "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 400],
            ["address" => 47922, "name" => "BAT2 - Entladen Spannung max", "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 410],
            ["address" => 47923, "name" => "BAT2 - Entladen Strom max",    "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 420],
            ["address" => 47924, "name" => "BAT2 - Spannung",              "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 430],
            ["address" => 47925, "name" => "BAT2 - Strom",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 440],
            ["address" => 47926, "name" => "BAT2 - SOC",                   "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 450],
            ["address" => 47927, "name" => "BAT2 - SOH",                   "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 460],

            // Wechselrichter
            ["address" => 35103, "name" => "WR - Spannung String 1",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 500],
            ["address" => 35104, "name" => "WR - Strom String 1",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 510],
            ["address" => 35105, "name" => "WR - Leistung String 1",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 520],
            ["address" => 35107, "name" => "WR - Spannung String 2",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 530],
            ["address" => 35108, "name" => "WR - Strom String 2",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 540],
            ["address" => 35109, "name" => "WR - Leistung String 2",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 550],
            ["address" => 35111, "name" => "WR - Spannung String 3",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 560],
            ["address" => 35112, "name" => "WR - Strom String 3",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 570],
            ["address" => 35113, "name" => "WR - Leistung String 3",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 580],
            ["address" => 35115, "name" => "WR - Spannung String 4",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 590],
            ["address" => 35116, "name" => "WR - Strom String 4",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 600],
            ["address" => 35117, "name" => "WR - Leistung String 4",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 610],
            ["address" => 35304, "name" => "WR - Spannung String 5",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 611],
            ["address" => 35305, "name" => "WR - Strom String 5",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 612],
            ["address" => 35306, "name" => "WR - Spannung String 6",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 614],
            ["address" => 35307, "name" => "WR - Strom String 6",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 615],
            ["address" => 35174, "name" => "WR - Temperatur",              "type" => "S16", "unit" => "°C",       "scale" => 0.1, "pos" => 620],
            ["address" => 35191, "name" => "WR - Erzeugung Gesamt",        "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 630],
            ["address" => 35193, "name" => "WR - Erzeugung Tag",           "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 640],
            ["address" => 35301, "name" => "WR - Leistung Gesamt",         "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 650],
            ["address" => 35337, "name" => "WR - P MPPT1",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 660],
            ["address" => 35338, "name" => "WR - P MPPT2",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 670],
            ["address" => 35339, "name" => "WR - P MPPT3",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 680],
            ["address" => 35340, "name" => "WR - P MPPT4",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 690],
            ["address" => 35341, "name" => "WR - P MPPT5",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 700],
            ["address" => 35342, "name" => "WR - P MPPT6",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 710],
            ["address" => 35343, "name" => "WR - P MPPT7",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 720],
            ["address" => 35344, "name" => "WR - P MPPT8",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 730],
            ["address" => 35345, "name" => "WR - I MPPT1",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 740],
            ["address" => 35346, "name" => "WR - I MPPT2",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 750],
            ["address" => 35347, "name" => "WR - I MPPT3",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 760],
            ["address" => 35348, "name" => "WR - I MPPT4",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 770],
            ["address" => 35349, "name" => "WR - I MPPT5",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 780],
            ["address" => 35350, "name" => "WR - I MPPT6",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 790],
            ["address" => 35351, "name" => "WR - I MPPT7",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 800],
            ["address" => 35352, "name" => "WR - I MPPT8",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 810],
            ["address" => 35365, "name" => "WR - Isolationswiderstand",    "type" => "U16", "unit" => "KΩ",       "scale" => 0.1, "pos" => 820],
        ];
    }
}