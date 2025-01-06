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
        $this->RegisterPropertyInteger("PollInterval", 5); // Standard: 60 Sekunden

        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
        $this->ApplyChanges();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lese die ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("ApplyChanges", "SelectedRegisters ist keine gültige Liste", 0);
            return;
        }
    
        // Liste der aktuellen Register-Identifikatoren
        $currentIdents = [];
    
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
            $currentIdents[] = $ident;
    
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
                    default:
                        $this->SendDebug("ApplyChanges", "Unbekannter Variablentyp für {$selectedRegister['unit']}.", 0);
                        continue 2;
                }
                $this->SendDebug("ApplyChanges", "Variable erstellt: $ident mit Name {$selectedRegister['name']} und Profil {$variableDetails['profile']}.", 0);
            } else {
                $this->SendDebug("ApplyChanges", "Variable mit Ident $ident existiert bereits.", 0);
            }
        }
    
        // Variablen löschen, die nicht mehr in der aktuellen Liste sind
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] === OBJECTTYPE_VARIABLE && !in_array($object['ObjectIdent'], $currentIdents)) {
                $this->UnregisterVariable($object['ObjectIdent']);
                $this->SendDebug("ApplyChanges", "Variable mit Ident {$object['ObjectIdent']} gelöscht.", 0);
            }
        }
    
        // Timer setzen
        $pollInterval = $this->ReadPropertyInteger("PollInterval");
        $this->SetTimerInterval("Poller", $pollInterval * 1000);
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
    
        // Prüfen, ob der Parent geöffnet ist (sofern relevant für den Parent-Typ)
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
    
    public function GetConfigurationForm()
    {
        // Aktuelle Liste der Register abrufen und in der Property aktualisieren
        $registers = $this->GetRegisters();
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        // Optionen für die Auswahlliste
        $registerOptions = array_map(function ($register) {
            return [
                "caption" => "{$register['address']} - {$register['name']} ({$register['unit']})",
                "value" => json_encode($register)
            ];
        }, $registers);
        
    
        return json_encode([
            "elements" => [
                [
                    "type"  => "List",
                    "name"  => "SelectedRegisters",
                    "caption" => "Selected Registers",
                    "rowCount" => 10,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        [
                            "caption" => "Address",
                            "name" => "address",
                            "width" => "300px",
                            "add" => '',
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
                    "name"  => "PollInterval",
                    "caption" => "Abfrageintervall (Sekunden)",
                    "suffix" => "s"
                ]
            ],
            "actions" => [
                [
                    "type" => "Button",
                    "caption" => "Werte lesen",
                    "onClick" => 'Goodwe_RequestRead($id);'
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
                return ["profile" => "~Watt", "type" => VARIABLETYPE_FLOAT];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "~Battery.100", "type" => VARIABLETYPE_INTEGER];
            case "String":
                return ["profile" => "~String", "type" => VARIABLETYPE_STRING];
            default:
                return null; // Kein bekanntes Profil oder Typ
        }
    }
    
    private function GetRegisters()
    {
        return [
        // Smartmeter
        ["address" => 36019, "name" => "SM-Leistung PH1", "type" => "S32", "unit" => "W", "scale" => 1],
        ["address" => 36021, "name" => "SM-Leistung PH2", "type" => "S32", "unit" => "W", "scale" => 1],
        ["address" => 36023, "name" => "SM-Leistung PH3", "type" => "S32", "unit" => "W", "scale" => 1],
        ["address" => 36025, "name" => "SM-Leistung gesamt", "type" => "S32", "unit" => "W", "scale" => 1],
        // Batterie
       
        ["address" => 35182, "name" => "BAT-Leistung", "type" => "S32", "unit" => "W", "scale" => 1],
        ["address" => 35184, "name" => "BAT-Mode", "type" => "U16", "unit" => "", "scale" => 1],
        ["address" => 35206, "name" => "BAT-Laden", "type" => "U32", "unit" => "kWh", "scale" => 0.1],
        ["address" => 35209, "name" => "BAT-Entladen", "type" => "U32", "unit" => "kWh", "scale" => 0.1],
        ["address" => 37003, "name" => "BAT-Temperatur", "type" => "U16", "unit" => "°C", "scale" => 0.1],
        ["address" => 45356, "name" => "BAT-Min SOC online", "type" => "U16", "unit" => "%", "scale" => 1],
        ["address" => 45358, "name" => "BAT-Min SOC online", "type" => "U16", "unit" => "%", "scale" => 1],
        ["address" => 47511, "name" => "BAT-EMSPowerMode", "type" => "U16", "unit" => "", "scale" => 1],
        ["address" => 47512, "name" => "BAT-EMSPowerSet", "type" => "U16", "unit" => "W", "scale" => 1],
        ["address" => 47903, "name" => "BAT-Laden Strom max", "type" => "S16", "unit" => "A", "scale" => 0.1],
        ["address" => 47905, "name" => "BAT-Entladen Strom max", "type" => "S16", "unit" => "A", "scale" => 0.1],
        ["address" => 47906, "name" => "BAT-Spannung", "type" => "S16", "unit" => "V", "scale" => 0.1],
        ["address" => 47907, "name" => "BAT-Strom", "type" => "S16", "unit" => "A", "scale" => 0.1],
        ["address" => 47908, "name" => "BAT-SOC", "type" => "S16", "unit" => "%", "scale" => 1],
        ["address" => 47909, "name" => "BAT-SOH", "type" => "S16", "unit" => "%", "scale" => 1],
        // Wechslerichter
        ["address" => 35103, "name" => "WR-Spannung String 1", "type" => "U16", "unit" => "V", "scale" => 0.1],
        ["address" => 35104, "name" => "WR-Strom String 1", "type" => "U16", "unit" => "A", "scale" => 0.1],
        ["address" => 35105, "name" => "WR-Leistung String 1", "type" => "S16", "unit" => "W", "scale" => 0.1],
        ["address" => 35107, "name" => "WR-Spannung String 2", "type" => "U16", "unit" => "V", "scale" => 0.1],
        ["address" => 35108, "name" => "WR-Strom String 2", "type" => "U16", "unit" => "A", "scale" => 0.1],
        ["address" => 35109, "name" => "WR-Leistung String 2", "type" => "S16", "unit" => "W", "scale" => 0.1],
        ["address" => 35174, "name" => "WR-Wechselrichter Temperatur", "type" => "S16", "unit" => "°C", "scale" => 0.1],
        ["address" => 35189, "name" => "WR-Fehlermeldung", "type" => "U32", "unit" => "", "scale" => 1],
        ["address" => 35191, "name" => "WR-Erzeugung Gesamt", "type" => "U32", "unit" => "kWh", "scale" => 0.1],
        ["address" => 35193, "name" => "WR-Erzeugung Tag", "type" => "U32", "unit" => "kWh", "scale" => 0.1],
        ["address" => 35301, "name" => "WR-Leistung Gesamt", "type" => "U32", "unit" => "W", "scale" => 1],
        ["address" => 35337, "name" => "WR-P MPPT1", "type" => "S16", "unit" => "W", "scale" => 1],
        ["address" => 35338, "name" => "WR-P MPPT2", "type" => "S16", "unit" => "W", "scale" => 1],
        ["address" => 35339, "name" => "WR-P MPPT3", "type" => "S16", "unit" => "W", "scale" => 1],
        ["address" => 35345, "name" => "WR-I MPPT1", "type" => "S16", "unit" => "A", "scale" => 0.1],
        ["address" => 35346, "name" => "WR-I MPPT2", "type" => "S16", "unit" => "A", "scale" => 0.1],
        ["address" => 35347, "name" => "WR-I MPPT3", "type" => "S16", "unit" => "A", "scale" => 0.1]
    
        ];
    }
}
